#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os, asyncio, time, re, uuid, json, pyppeteer
from datetime import datetime
from typing import Dict, Tuple, Optional, Any, List
import pytz
from lxml import html
from pyppeteer import launch
from websockets.exceptions import ConnectionClosedError
from pyppeteer.errors import NetworkError

# ========== 환경 ==========
MAX_CONCURRENCY       = int(os.getenv("MAX_CONCURRENCY", "1"))
QUEUE_TIMEOUT         = float(os.getenv("QUEUE_TIMEOUT", "6.0"))
NAV_TIMEOUT_DEFAULT   = int(os.getenv("NAV_TIMEOUT_MS", "9000"))     # goto/reload 상한
NAV_MIN_TIMEOUT_MS    = int(os.getenv("NAV_MIN_TIMEOUT_MS", "3500")) # goto/reload 하한
POLL_INTERVAL_SEC     = float(os.getenv("POLL_INTERVAL_SEC", "0.5")) # 500ms
POLL_WINDOW_SEC       = float(os.getenv("POLL_WINDOW_SEC", "5.0"))   # 5초
CACHE_TTL_SEC         = int(os.getenv("CACHE_TTL_SEC", "60"))        # 1분 캐시
MAX_CACHE_ENTRIES     = int(os.getenv("MAX_CACHE_ENTRIES", "10"))    # 10개 제한
LAUNCH_TIMEOUT_SEC    = float(os.getenv("LAUNCH_TIMEOUT_SEC", "8.0"))
BROWSER_COOLDOWN_SEC  = int(os.getenv("BROWSER_COOLDOWN_SEC", "8"))
MAX_PAGES_PER_BROWSER = int(os.getenv("MAX_PAGES_PER_BROWSER", "300"))
CHROME_BIN            = os.getenv("CHROME_BIN", "/usr/bin/chromium")
STALE_KILL_SEC        = int(os.getenv("STALE_KILL_SEC", "10"))
STALE_RELEASE_WAIT    = float(os.getenv("STALE_RELEASE_WAIT", "2.0"))

# ========== 전역 ==========
browser = None
log_enabled = False
_init_lock = asyncio.Lock()
_sem = asyncio.Semaphore(MAX_CONCURRENCY)
_sid_locks: Dict[str, asyncio.Lock] = {}
_cache: Dict[str, Tuple[float, dict]] = {}
_launch_future: Optional[asyncio.Future] = None
_last_launch_fail_ts: float = 0.0
_page_counter: int = 0
_inflight: Dict[str, Tuple[asyncio.Task, float]] = {}

# ========== 도우미 ==========
class OverCapacityError(RuntimeError): pass
def _now_s(): return datetime.now().strftime('%Y-%m-%d %H:%M:%S')
def log(msg: str):
    if log_enabled: print(f"[LOG {_now_s()}] {msg}", flush=True)

class Trace:
    def __init__(self, enabled: bool):
        self.enabled = enabled; self.t0 = time.time()
        self.rid = f"{int(self.t0)%100000}-{uuid.uuid4().hex[:6]}"; self.events: List[dict] = []
    def mark(self, name: str, **kw):
        if not self.enabled: return
        t = time.time() - self.t0
        ev = {"t": round(t,3), "ev": name}; ev.update(kw); self.events.append(ev)
        parts = [f"[RID {self.rid}] {name} @ {t:.3f}s"] + [f"{k}={v}" for k,v in kw.items()]
        log(" | ".join(parts))
    def export(self): return self.events

def _compute_nav_timeout(remaining_sec: float, attempts_left: int) -> int:
    if attempts_left <= 0: attempts_left = 1
    ms = int((max(0.0, remaining_sec) - 0.5) * 1000 / attempts_left)
    ms = max(NAV_MIN_TIMEOUT_MS, ms)
    return min(NAV_TIMEOUT_DEFAULT, ms)

# ========== 파싱 유틸 ==========
def compute_date_finish_info(s: str) -> str:
    if not s: return ""
    seoul = pytz.timezone("Asia/Seoul")
    naive = datetime.strptime(s, "%Y.%m.%d %H:%M")
    finish_dt = seoul.localize(naive); now = datetime.now(seoul)
    diff = now - finish_dt
    if diff.total_seconds() < 0: return ""
    if diff.total_seconds() < 60: return "방금 전 종료"
    total_min = int(diff.total_seconds() // 60); h, m = divmod(total_min, 60)
    if h >= 24:
        d = h // 24; rem_h = h % 24
        return f"{d}일 {rem_h}시간 전 종료"
    return f"{h}시간 {m}분 전 종료" if h > 0 else f"{m}분 전 종료"

def parse_status(t: str) -> str:
    t = t.strip()
    m = re.match(r"(?:(\d+)\s*시간)?\s*(?:(\d+)\s*분)?\s*충전중[’']?$", t)
    if m:
        hh, mm = m.group(1), m.group(2)
        if hh and mm: return f"충전중\n({int(hh)}시간 {int(mm)}분)"
        if hh: return f"충전중\n({int(hh)}시간)"
        if mm:
            minutes = int(mm)
            if minutes >= 60:
                h, r = divmod(minutes, 60)
                return f"충전중\n({h}시간 {r}분)"
            return f"충전중\n({minutes}분)"
        return "충전중\n"
    return t + "\n"

def _parse_html_to_payload(html_content: str, started_at: float) -> dict:
    tree = html.fromstring(html_content)
    h4_nodes = tree.xpath('//form[@id="form"]//h4') or tree.xpath('//h4')
    title = ''.join(h4_nodes[0].xpath('./text()')).strip() if h4_nodes else ""
    company_nodes = tree.xpath('//div[@class="org_me"]/span/text()')
    company_name = company_nodes[0].strip() if company_nodes else ""

    chargers_info = []
    rows = tree.xpath('//table[@class="table01"]//tbody/tr') \
        or tree.xpath('//table[contains(@class,"tbl_list") or contains(@class,"tbl")]//tbody/tr') \
        or tree.xpath('//tbody/tr')
    for idx, row in enumerate(rows):
        try:
            tds = row.xpath('./td')
            if len(tds) < 3: continue
            charger_type = tds[0].text_content().strip()
            td_text = tds[2].text_content().strip()
            state_node = tds[2].xpath('./span[@class="state"]/text()')
            raw_status = state_node[0].strip() if state_node else td_text.split('\n')[0].strip()
            rdate_node = tds[2].xpath('./span[@class="rdate"]/text()')
            date_finish = (rdate_node[0].strip() if rdate_node
                           else (td_text.split('\n')[-1].strip() if '\n' in td_text else ""))
            if "충전중" in raw_status or "사용중" in raw_status:
                charger_status = parse_status(raw_status); dfi = ""
            else:
                charger_status = parse_status(raw_status); dfi = compute_date_finish_info(date_finish)
            chargers_info.append({
                "type": charger_type,
                "status": charger_status,
                "dateFinish": date_finish,
                "dateFinishInfo": dfi
            })
            log(f"▶ row[{idx}] {charger_type} / {charger_status} / {dfi}")
        except Exception as e:
            log(f"▶ row[{idx}] 파싱 에러: {e}")
            continue

    total = len(chargers_info)
    used = sum(1 for c in chargers_info if ("사용중" in c["status"]) or ("충전중" in c["status"]) or ("충전불가" in c["status"]))
    remaining = total - used
    addr_nodes = tree.xpath('//table[@class="table03"]//tbody/tr/td/text()')
    address = addr_nodes[0].strip() if addr_nodes else ""

    print_string = "\n\n".join(
        f"{i+1}. {c['status']}({c['dateFinishInfo']}) / {c['type']}" if c['dateFinishInfo']
        else f"{i+1}. {c['status']} / {c['type']}"
        for i, c in enumerate(chargers_info)
    )
    return {
        "title": title, "company_name": company_name,
        "total_chargers": total, "used_chargers": used, "remaining_chargers": remaining,
        "address": address, "chargers_info": chargers_info, "printString": print_string,
        "msg": "SUCCESS", "total_time": f"{time.time() - started_at:.2f} seconds"
    }

# ========== 브라우저 관리 ==========
async def background_warmup():  # 현재 미사용
    tries = 0
    while tries < 5 and browser is None:
        try:
            await init_browser(Trace(False), max_wait_sec=LAUNCH_TIMEOUT_SEC, set_cooldown_on_fail=False)
            return
        except Exception:
            tries += 1
            await asyncio.sleep(2 * tries)

async def init_browser(tr: Trace, max_wait_sec: Optional[float] = None,
                       set_cooldown_on_fail: bool = True, allow_cooldown_override: bool = False):
    global browser, _launch_future, _last_launch_fail_ts
    if browser is not None:
        return browser

    now = time.time()
    cd_left = BROWSER_COOLDOWN_SEC - (now - _last_launch_fail_ts)
    if cd_left > 0:
        if not allow_cooldown_override or (_launch_future and not _launch_future.done()):
            raise OverCapacityError("브라우저 재기동 쿨다운 중")

    if _launch_future and not _launch_future.done():
        to = max_wait_sec or LAUNCH_TIMEOUT_SEC
        try:
            browser_r = await asyncio.wait_for(asyncio.shield(_launch_future), timeout=to)
            browser = browser_r
            tr.mark("browser_launch_done"); log("브라우저 초기화 완료")
            return browser
        except asyncio.TimeoutError:
            raise OverCapacityError("브라우저 기동 중(대기 초과)")

    async with _init_lock:
        if browser is not None: return browser
        if _launch_future and not _launch_future.done():
            to = max_wait_sec or LAUNCH_TIMEOUT_SEC
            return await asyncio.wait_for(asyncio.shield(_launch_future), timeout=to)

        tr.mark("browser_launch_start"); log("브라우저 초기화 시도")

        async def _do_launch():
            args = [
                "--no-sandbox","--disable-setuid-sandbox","--disable-dev-shm-usage",
                "--disable-gpu","--disable-extensions","--disable-logging","--log-level=3",
                "--no-zygote","--no-first-run","--disable-background-networking",
                "--disable-default-apps","--disable-sync","--metrics-recording-only",
                "--mute-audio","--blink-settings=imagesEnabled=false",
                "--renderer-process-limit=1",
                "--disable-features=IsolateOrigins,site-per-process",
                "--proxy-server=direct://","--proxy-bypass-list=*",
                "--disk-cache-dir=/tmp/chrome-cache",
                "--disable-software-rasterizer",
            ]
            b = await launch(
                headless=True, dumpio=False, args=args,
                executablePath=CHROME_BIN, userDataDir="/tmp/chrome-data"
            )
            try:
                b.on('disconnected', lambda *a, **k: asyncio.create_task(_on_browser_disconnected()))
            except Exception:
                pass
            return b

        _launch_future = asyncio.ensure_future(_do_launch())

    to = max_wait_sec or LAUNCH_TIMEOUT_SEC
    try:
        browser_r = await asyncio.wait_for(asyncio.shield(_launch_future), timeout=to)
        browser = browser_r; tr.mark("browser_launch_done"); log("브라우저 초기화 완료")
        return browser
    except asyncio.CancelledError:
        raise
    except Exception:
        if set_cooldown_on_fail:
            _last_launch_fail_ts = time.time()
        try:
            if _launch_future and not _launch_future.done(): _launch_future.cancel()
        except Exception: pass
        _launch_future = None
        raise OverCapacityError("브라우저 기동 실패")

async def _on_browser_disconnected():
    global browser, _launch_future, _last_launch_fail_ts
    log("브라우저 연결 종료 감지 → 재기동 예정")
    try:
        if browser: await browser.close()
    except Exception: pass
    browser = None; _last_launch_fail_ts = time.time()
    try:
        if _launch_future and not _launch_future.done(): _launch_future.cancel()
    except Exception: pass
    _launch_future = None

async def _recycle_browser():
    global browser, _launch_future, _last_launch_fail_ts
    b_old = browser
    if not b_old: return
    try:
        await b_old.close()
    except Exception: pass
    browser = None; _last_launch_fail_ts = 0
    try:
        await init_browser(Trace(False), max_wait_sec=LAUNCH_TIMEOUT_SEC,
                           set_cooldown_on_fail=False, allow_cooldown_override=True)
    except Exception: pass

async def _new_page(nav_timeout_ms: int, tr: Trace, max_launch_wait_sec: float):
    global _page_counter
    br = await init_browser(tr, max_wait_sec=max_launch_wait_sec,
                            set_cooldown_on_fail=True, allow_cooldown_override=True)
    p = await br.newPage(); _page_counter += 1
    if _page_counter >= MAX_PAGES_PER_BROWSER:
        _page_counter = 0; asyncio.create_task(_recycle_browser())

    tr.mark("new_page")
    await p.setUserAgent(
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
        "(KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36"
    )
    await p.setViewport({"width": 640, "height": 480})
    p.setDefaultNavigationTimeout(nav_timeout_ms)

    # 리소스 차단
    await p.setRequestInterception(True)
    async def _on_req(req):
        try:
            rt = getattr(req, "resourceType", None)
            if rt in ("image","stylesheet","font","media","other"):
                await req.abort()
            else:
                await req.continue_()
        except Exception:
            pass
    p.on("request", lambda r: asyncio.create_task(_on_req(r)))
    return p

# --- XHR(JSON) 스니핑 ---
async def _capture_json_response(resp, store: List[Tuple[str, Any]], tr: Trace):
    try:
        headers = getattr(resp, "headers", {}) or {}
        ct = (headers.get('content-type', '') or '').lower()
        rt = getattr(getattr(resp, "request", None), "resourceType", None)
        if (rt in ("xhr","fetch")) and ("application/json" in ct):
            data = await resp.json()
            store.append((resp.url, data))
            if tr.enabled:
                tr.mark("xhr_json", url=resp.url, size=len(json.dumps(data, ensure_ascii=False)))
    except (NetworkError, ConnectionClosedError, asyncio.CancelledError):
        return
    except Exception as e:
        if tr.enabled:
            tr.mark("xhr_json_err", err=str(e))

def _try_parse_from_json(blobs: List[Tuple[str, Any]], started_at: float) -> Optional[dict]:
    for _, data in blobs:
        candidates = []
        if isinstance(data, list):
            candidates.append(data)
        elif isinstance(data, dict):
            for key in ("chargers","items","rows","list","data","result"):
                v = data.get(key)
                if isinstance(v, list) and v:
                    candidates.append(v)
        for arr in candidates:
            chargers_info = []
            for it in arr:
                if not isinstance(it, dict): continue
                t  = it.get("type") or it.get("connectorType") or it.get("cpTp") or ""
                st = it.get("status") or it.get("statNm") or it.get("state") or it.get("stat") or ""
                end= it.get("dateFinish") or it.get("endTime") or it.get("rdate") or it.get("lastEndTime") or ""
                st_txt = str(st)
                if "충전" in st_txt:
                    status = parse_status(st_txt); dfi = ""
                else:
                    status = parse_status(st_txt); dfi = compute_date_finish_info(end) if end else ""
                chargers_info.append({"type": str(t), "status": status, "dateFinish": str(end), "dateFinishInfo": dfi})
            if chargers_info:
                total = len(chargers_info)
                used = sum(1 for c in chargers_info if ("사용중" in c["status"]) or ("충전중" in c["status"]) or ("충전불가" in c["status"]))
                remaining = total - used
                print_string = "\n\n".join(
                    f"{i+1}. {c['status']}({c['dateFinishInfo']}) / {c['type']}" if c['dateFinishInfo']
                    else f"{i+1}. {c['status']} / {c['type']}"
                    for i, c in enumerate(chargers_info)
                )
                return {
                    "title": "", "company_name": "",
                    "total_chargers": total, "used_chargers": used, "remaining_chargers": remaining,
                    "address": "", "chargers_info": chargers_info, "printString": print_string,
                    "msg": "SUCCESS (JSON)", "total_time": f"{time.time() - started_at:.2f} seconds"
                }
    return None

# ========== 폴링 보조 ==========
async def _poll_for_rows(page, json_blobs, started_at, max_poll_sec: float, interval_sec: float, tr: Trace) -> Optional[dict]:
    """
    페이지를 새로고침하지 않고, interval_sec 간격으로 최대 max_poll_sec 만큼
    - XHR JSON 스니핑 결과 먼저 확인
    - DOM에 행 생성 여부 빠르게 확인 → 있으면 content() 딱 1번 가져와 파싱
    """
    deadline = time.time() + max(0.0, max_poll_sec)
    tick = 0
    while time.time() < deadline:
        tick += 1
        # 1) JSON 먼저
        parsed = _try_parse_from_json(json_blobs, started_at)
        if parsed:
            tr.mark("poll_json_hit", tick=tick)
            return parsed

        # 2) DOM에 행이 생겼는지 경량 체크
        try:
            ok = await page.evaluate("""
                () => {
                  const q = document.querySelectorAll('.table01 tbody tr');
                  if (q && q.length>0) return true;
                  const a = document.querySelectorAll('.tbl_list tbody tr, .tbl tbody tr');
                  return !!(a && a.length>0);
                }
            """)
        except Exception:
            ok = False

        if ok:
            html_content = await page.content()
            if ("<tbody" in html_content) and ("table" in html_content):
                tr.mark("poll_dom_hit", tick=tick, size=len(html_content))
                return _parse_html_to_payload(html_content, started_at)

        await asyncio.sleep(interval_sec)

    tr.mark("poll_timeout", sec=max_poll_sec)
    return None

# ========== CSR 수집(폴링 + 1회 리로드) ==========
async def _browser_fetch_and_parse(sid: str, started_at: float, timeout_sec: int, tr: Trace) -> dict:
    url = f"https://www.ev.or.kr/nportal/monitor/evMapInfo.do?sid={sid}&pFlag=Y"
    deadline = time.time() + timeout_sec
    last_err: Optional[str] = None
    page = None
    json_blobs: List[Tuple[str, Any]] = []

    try:
        # ---- 1) 첫 진입 ----
        remaining = deadline - time.time()
        if remaining <= 0: raise asyncio.TimeoutError("예산 소진")
        nav_ms = max(NAV_MIN_TIMEOUT_MS, min(NAV_TIMEOUT_DEFAULT, int((remaining - 0.5) * 1000 * 0.5)))
        max_launch_wait = min(LAUNCH_TIMEOUT_SEC, max(3.0, remaining * 0.6))
        page = await _new_page(nav_timeout_ms=nav_ms, tr=tr, max_launch_wait_sec=max_launch_wait)

        # response 스니퍼 등록(예외 흡수)
        def _spawn(resp):
            task = asyncio.create_task(_capture_json_response(resp, json_blobs, tr))
            task.add_done_callback(lambda f: f.exception() and None)
        page.on("response", _spawn)

        t0 = time.time()
        try:
            await page.goto(url, {"waitUntil": "domcontentloaded", "timeout": nav_ms})
            tr.mark("goto_done", dt=round(time.time()-t0,3), nav_ms=nav_ms)
        except Exception as e:
            last_err = f"nav1:{e}"
            tr.mark("nav1_error", err=str(e))
        else:
            # 폴링 창은 남은 예산 안에서 5초를 넘지 않도록 조절
            rem = deadline - time.time()
            poll_window = min(POLL_WINDOW_SEC, max(0.5, rem - 1.0))
            got = await _poll_for_rows(page, json_blobs, started_at, poll_window, POLL_INTERVAL_SEC, tr)
            if got:
                return got

        # ---- 2) 1회 리로드 후 동일 폴링 ----
        rem = deadline - time.time()
        if rem <= 1.0:
            raise asyncio.TimeoutError("예산 소진(리로드 전)")
        nav_ms2 = max(NAV_MIN_TIMEOUT_MS, min(NAV_TIMEOUT_DEFAULT, int((rem - 0.3) * 1000 * 0.5)))
        try:
            await page.reload({"waitUntil": "domcontentloaded", "timeout": nav_ms2})
            tr.mark("reload_done", nav_ms=nav_ms2)
        except Exception as e:
            last_err = f"nav2:{e}"
            tr.mark("nav2_error", err=str(e))
        else:
            rem2 = deadline - time.time()
            poll_window2 = min(POLL_WINDOW_SEC, max(0.5, rem2 - 0.5))
            got2 = await _poll_for_rows(page, json_blobs, started_at, poll_window2, POLL_INTERVAL_SEC, tr)
            if got2:
                return got2

        raise RuntimeError(f"CSR 폴링 실패: {last_err or '데이터 미발견'}")

    finally:
        if page:
            try:
                await asyncio.sleep(0.05)  # 콜백 마무리 여유
                await page.close()
            except Exception:
                pass

# ========== inflight/캐시/동시성 ==========
async def _maybe_cancel_stale(sid: str, tr: Trace) -> bool:
    ent = _inflight.get(sid)
    if not ent: return False
    task, started = ent
    if task.done(): return False
    age = time.time() - started
    if age > STALE_KILL_SEC:
        tr.mark("cancel_stale", age=round(age,1)); task.cancel()
        try:
            await asyncio.wait_for(task, timeout=min(STALE_RELEASE_WAIT, max(0.5, age/4)))
        except Exception: pass
        return True
    return False

def _inflight_register(sid: str):
    try: _inflight[sid] = (asyncio.current_task(), time.time())
    except Exception: pass

def _inflight_pop_if_same(sid: str):
    try:
        ent = _inflight.get(sid)
        if not ent: return
        task, _ = ent
        if task is asyncio.current_task(): _inflight.pop(sid, None)
    except Exception: pass

def _cache_gc():
    now = time.time()
    expired = [k for k, (ts, _) in _cache.items() if (now - ts) > CACHE_TTL_SEC]
    for k in expired: _cache.pop(k, None)
    if len(_cache) > MAX_CACHE_ENTRIES:
        to_drop = len(_cache) - MAX_CACHE_ENTRIES
        for k in sorted(_cache, key=lambda x: _cache[x][0])[:to_drop]:
            _cache.pop(k, None)

def _get_sid_lock(sid: str) -> asyncio.Lock:
    lk = _sid_locks.get(sid)
    if lk is None: lk = asyncio.Lock(); _sid_locks[sid] = lk
    return lk

def _cache_get(sid: str) -> Optional[dict]:
    ent = _cache.get(sid)
    if not ent: _cache_gc(); return None
    ts, payload = ent
    if (time.time() - ts) > CACHE_TTL_SEC:
        _cache.pop(sid, None); _cache_gc(); return None
    return payload

def _cache_put(sid: str, payload: dict):
    _cache_gc(); _cache[sid] = (time.time(), payload); _cache_gc()

async def scrape_data(sid: str, timeout_sec: int = 9) -> dict:
    started = time.time(); deadline = started + timeout_sec
    tr = Trace(log_enabled); tr.mark("start", sid=sid, timeout=timeout_sec)

    cached = _cache_get(sid)
    if cached:
        tr.mark("cache_hit")
        if log_enabled: cached["_trace"] = tr.export()
        return cached

    try: await _maybe_cancel_stale(sid, tr)
    except Exception: pass

    sid_lock = _get_sid_lock(sid)
    try:
        t1 = time.time()
        await asyncio.wait_for(sid_lock.acquire(), timeout=max(0.5, timeout_sec - 0.5))
        tr.mark("sid_lock_acquired", wait=round(time.time()-t1,3))

        c2 = _cache_get(sid)
        if c2:
            tr.mark("cache_hit_2")
            if log_enabled: c2["_trace"] = tr.export()
            return c2

        rem = deadline - time.time(); tr.mark("budget_after_sid", rem=round(rem,3))
        if rem <= 1.0: raise asyncio.TimeoutError("대기 중 예산 소진(sid_lock)")

        _inflight_register(sid)
        sem_acquired = False
        try:
            t0 = time.time()
            await asyncio.wait_for(_sem.acquire(), timeout=min(QUEUE_TIMEOUT, max(0.1, rem - 0.5)))
            sem_acquired = True; tr.mark("sem_acquired", wait=round(time.time()-t0,3))
        except asyncio.TimeoutError:
            tr.mark("sem_timeout", waited=QUEUE_TIMEOUT)
            raise OverCapacityError("서버 혼잡: 잠시 후 다시 시도해주세요")

        rem2 = deadline - time.time(); tr.mark("budget_after_sem", rem=round(rem2,3))
        if rem2 <= 1.0: raise asyncio.TimeoutError("대기 중 예산 소진(sem)")

        try:
            try:
                payload = await _browser_fetch_and_parse(sid, started, int(rem2), tr)
            except RuntimeError as e:
                if "폴링 실패" in str(e) or "CSR" in str(e):
                    raise asyncio.TimeoutError(str(e))
                raise
            if log_enabled: payload["_trace"] = tr.export()
            _cache_put(sid, payload); return payload

        except (pyppeteer.errors.BrowserError, ConnectionClosedError, asyncio.IncompleteReadError) as e:
            raise OverCapacityError(f"브라우저 재기동 중: {e}")
        except asyncio.CancelledError:
            tr.mark("cancelled_top"); raise
        finally:
            if sem_acquired:
                try: _sem.release()
                except Exception: pass
    finally:
        _inflight_pop_if_same(sid)
        try: sid_lock.release()
        except Exception: pass
        tr.mark("end")

async def shutdown():
    global browser, _launch_future
    if browser:
        try: await browser.close(); log("브라우저 종료 완료")
        except Exception: pass
        browser = None
    try:
        if _launch_future and not _launch_future.done():
            _launch_future.cancel()
    except Exception: pass
    _launch_future = None
