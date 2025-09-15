#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os, asyncio, time, re, uuid, json
from datetime import datetime
from typing import Dict, Tuple, Optional, Any, List
import pytz
from lxml import html
from pyppeteer import launch

# ========== 환경 ==========
MAX_CONCURRENCY       = int(os.getenv("MAX_CONCURRENCY", "1"))     # 1GB 서버 권장 1
QUEUE_TIMEOUT         = float(os.getenv("QUEUE_TIMEOUT", "3.0"))
NAV_TIMEOUT_DEFAULT   = int(os.getenv("NAV_TIMEOUT_MS", "9000"))   # 각 시도 내 네비 타임아웃(ms)
CSR_RETRY             = int(os.getenv("CSR_RETRY", "3"))           # 데이터 없을 때 재시도 횟수
CSR_RETRY_DELAY       = float(os.getenv("CSR_RETRY_DELAY", "1.0")) # 재시도 간격(초)
CACHE_TTL_SEC         = int(os.getenv("CACHE_TTL_SEC", "60"))      # sid 캐시 TTL (1분)
MAX_CACHE_ENTRIES     = int(os.getenv("MAX_CACHE_ENTRIES", "10"))  # 캐시 상한 (10개)
LAUNCH_TIMEOUT_SEC    = float(os.getenv("LAUNCH_TIMEOUT_SEC", "8.0"))  # 콜드런치 상한
BROWSER_COOLDOWN_SEC  = int(os.getenv("BROWSER_COOLDOWN_SEC", "20"))
CHROME_BIN            = os.getenv("CHROME_BIN", "/usr/bin/chromium")

# ========== 전역 ==========
browser = None
log_enabled = False
_init_lock = asyncio.Lock()
_sem = asyncio.Semaphore(MAX_CONCURRENCY)
_sid_locks: Dict[str, asyncio.Lock] = {}

# 캐시: sid -> (ts, payload)
_cache: Dict[str, Tuple[float, dict]] = {}

_launch_future: Optional[asyncio.Future] = None
_last_launch_fail_ts: float = 0.0

# ========== 도우미 ==========
class OverCapacityError(RuntimeError): pass
def _now_s(): return datetime.now().strftime('%Y-%m-%d %H:%M:%S')
def log(msg: str):
    if log_enabled: print(f"[LOG {_now_s()}] {msg}")

class Trace:
    def __init__(self, enabled: bool):
        self.enabled = enabled
        self.t0 = time.time()
        self.rid = f"{int(self.t0)%100000}-{uuid.uuid4().hex[:6]}"
        self.events: List[dict] = []
    def mark(self, name: str, **kw):
        if not self.enabled: return
        t = time.time() - self.t0
        ev = {"t": round(t,3), "ev": name}; ev.update(kw)
        self.events.append(ev)
        parts = [f"[RID {self.rid}] {name} @ {t:.3f}s"] + [f"{k}={v}" for k,v in kw.items()]
        log(" | ".join(parts))
    def export(self): return self.events

def _compute_nav_timeout(remaining_sec: float, attempts_left: int) -> int:
    if attempts_left <= 0: attempts_left = 1
    ms = max(1000, int((remaining_sec - 0.5) * 1000 / attempts_left))  # 0.5s 마진
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
        f"{i+1}. {c['status']} ({c['dateFinishInfo']}) / {c['type']}" if c['dateFinishInfo']
        else f"{i+1}. {c['status']} / {c['type']}"
        for i, c in enumerate(chargers_info)
    )
    return {
        "title": title, "company_name": company_name,
        "total_chargers": total, "used_chargers": used, "remaining_chargers": remaining,
        "address": address, "chargers_info": chargers_info, "printString": print_string,
        "msg": "SUCCESS", "total_time": f"{time.time() - started_at:.2f} seconds"
    }

# ========== 브라우저 관리 (단일 런치 + 쿨다운, 웜업 실패는 쿨다운 미적용) ==========
async def background_warmup():
    # 웜업 실패는 쿨다운 갱신 없이 조용히 재시도
    tries = 0
    while tries < 5 and browser is None:
        try:
            await init_browser(Trace(False), max_wait_sec=LAUNCH_TIMEOUT_SEC, set_cooldown_on_fail=False)
            return
        except Exception:
            tries += 1
            await asyncio.sleep(2 * tries)  # 2,4,6,8,...

async def init_browser(tr: Trace, max_wait_sec: Optional[float] = None, set_cooldown_on_fail: bool = True):
    """
    전역 싱글톤 런치.
    - 중복 시도 방지(공유 Future)
    - 실패 시 쿨다운(BROWSER_COOLDOWN_SEC). 단, set_cooldown_on_fail=False면 갱신 안 함(웜업용).
    - 호출자별 대기 상한(max_wait_sec)
    """
    global browser, _launch_future, _last_launch_fail_ts

    if browser is not None:
        return browser

    now = time.time()
    if (now - _last_launch_fail_ts) < BROWSER_COOLDOWN_SEC:
        raise OverCapacityError("브라우저 재기동 쿨다운 중")

    # 이미 런치 중이면 그 Future만 기다림
    if _launch_future and not _launch_future.done():
        to = max_wait_sec or LAUNCH_TIMEOUT_SEC
        try:
            browser = await asyncio.wait_for(_launch_future, timeout=to)
            tr.mark("browser_launch_done"); log("브라우저 초기화 완료")
            return browser
        except asyncio.TimeoutError:
            raise OverCapacityError("브라우저 기동 중(대기 초과)")

    async with _init_lock:
        if browser is not None:
            return browser
        if _launch_future and not _launch_future.done():
            to = max_wait_sec or LAUNCH_TIMEOUT_SEC
            return await asyncio.wait_for(_launch_future, timeout=to)

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
        browser = await asyncio.wait_for(_launch_future, timeout=to)
        tr.mark("browser_launch_done"); log("브라우저 초기화 완료")
        return browser
    except Exception:
        if set_cooldown_on_fail:
            _last_launch_fail_ts = time.time()      # 실패 기록 → 쿨다운
        try:
            if _launch_future and not _launch_future.done():
                _launch_future.cancel()
        except Exception:
            pass
        _launch_future = None
        raise OverCapacityError("브라우저 기동 실패")

async def _on_browser_disconnected():
    global browser, _launch_future, _last_launch_fail_ts
    log("브라우저 연결 종료 감지 → 재기동 예정")
    try:
        if browser: await browser.close()
    except Exception:
        pass
    browser = None
    _last_launch_fail_ts = time.time()          # 끊겼으면 잠시 쿨다운
    try:
        if _launch_future and not _launch_future.done():
            _launch_future.cancel()
    except Exception:
        pass
    _launch_future = None

async def _new_page(nav_timeout_ms: int, tr: Trace, max_launch_wait_sec: float):
    br = await init_browser(tr, max_wait_sec=max_launch_wait_sec, set_cooldown_on_fail=True)
    p = await br.newPage()
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
    p.on("request", lambda r: asyncio.ensure_future(_on_req(r)))
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
    except Exception as e:
        if tr.enabled:
            tr.mark("xhr_json_err", err=str(e))

def _try_parse_from_json(blobs: List[Tuple[str, Any]], started_at: float) -> Optional[dict]:
    for url, data in blobs:
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
                    f"{i+1}. {c['status']} ({c['dateFinishInfo']}) / {c['type']}" if c['dateFinishInfo']
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

# ========== CSR 전용 수집(재시도 포함) ==========
def _compute_nav_ms(remaining: float, attempt: int) -> int:
    return _compute_nav_timeout(remaining, max(1, CSR_RETRY - attempt + 1))

async def _browser_fetch_and_parse(sid: str, started_at: float, timeout_sec: int, tr: Trace) -> dict:
    url = f"https://www.ev.or.kr/nportal/monitor/evMapInfo.do?sid={sid}&pFlag=Y"
    deadline = time.time() + timeout_sec
    attempt = 0
    last_err: Optional[str] = None

    page = None
    json_blobs: List[Tuple[str, Any]] = []

    try:
        while attempt < CSR_RETRY:
            attempt += 1
            remaining = deadline - time.time()
            if remaining <= 0:
                raise asyncio.TimeoutError("예산 소진")

            nav_ms = _compute_nav_ms(remaining, attempt)

            # 첫 시도 & 아직 브라우저 없음이면 런치 대기에 더 많은 예산 할당(최대 70%)
            need_launch = (browser is None)
            if need_launch and attempt == 1:
                max_launch_wait = min(LAUNCH_TIMEOUT_SEC, max(3.0, remaining * 0.7))
            else:
                max_launch_wait = min(3.0, max(1.5, remaining * 0.3))

            if page is None:
                page = await _new_page(nav_timeout_ms=nav_ms, tr=tr, max_launch_wait_sec=max_launch_wait)
                page.on("response", lambda r: asyncio.ensure_future(_capture_json_response(r, json_blobs, tr)))

            # 이동 또는 새로고침
            t0 = time.time()
            try:
                if attempt == 1:
                    await page.goto(url, {"waitUntil": "domcontentloaded", "timeout": nav_ms})
                else:
                    await page.reload({"waitUntil": "domcontentloaded", "timeout": nav_ms})
                tr.mark("goto_or_reload", attempt=attempt, dt=round(time.time()-t0,3), nav_ms=nav_ms)
            except Exception as e:
                last_err = f"nav:{e}"
                tr.mark("nav_error", attempt=attempt, err=str(e))
                await asyncio.sleep(0.2)
                continue

            # 1) 표식 대기 (대체 셀렉터 포함)
            t1 = time.time()
            try:
                await page.waitForSelector("#form, .table01, .tbl_list, .tbl", {"timeout": min(2000, nav_ms)})
                tr.mark("wait_selector", dt=round(time.time()-t1,3))
            except Exception:
                tr.mark("wait_selector_miss")

            # 2) 행 대기
            row_wait_js = """
              (function(){
                var q = document.querySelectorAll('.table01 tbody tr');
                if (q && q.length>0) return true;
                var a = document.querySelectorAll('.tbl_list tbody tr, .tbl tbody tr');
                return (a && a.length>0);
              })()
            """
            try:
                await page.waitForFunction(row_wait_js, {"timeout": min(2500, nav_ms)})
                tr.mark("wait_rows_ok")
            except Exception:
                tr.mark("wait_rows_miss")

            # 3) DOM 파싱 시도
            html_content = await page.content()
            if ("<tbody" in html_content) and ("table" in html_content):
                tr.mark("browser_parse_start", size=len(html_content))
                payload = _parse_html_to_payload(html_content, started_at)
                if payload.get("total_chargers", 0) > 0 or payload.get("chargers_info"):
                    tr.mark("browser_parse_done")
                    return payload
                else:
                    last_err = "empty_payload"
                    tr.mark("browser_empty_payload")

            # 4) JSON 폴백 (XHR 스니핑)
            parsed = _try_parse_from_json(json_blobs, started_at)
            if parsed:
                tr.mark("json_parsed")
                return parsed

            # 5) 원하는 값이 없으면 1초 대기 후 재시도
            if attempt < CSR_RETRY:
                tr.mark("retry_wait", sec=CSR_RETRY_DELAY)
                await asyncio.sleep(CSR_RETRY_DELAY)

        raise RuntimeError(f"CSR 재시도 실패: {last_err or '데이터 미발견'}")

    finally:
        if page:
            try: await page.close()
            except Exception: pass

# ========== 캐시(1분 TTL, 최대 10개) / 동시성 / 엔트리 ==========
def _cache_gc():
    """TTL 만료 및 개수 상한 초과시 가장 오래된 항목부터 정리"""
    now = time.time()
    # 1) 만료 제거
    expired = [k for k, (ts, _) in _cache.items() if (now - ts) > CACHE_TTL_SEC]
    for k in expired:
        _cache.pop(k, None)
    # 2) 개수 상한
    if len(_cache) > MAX_CACHE_ENTRIES:
        to_drop = len(_cache) - MAX_CACHE_ENTRIES
        for k in sorted(_cache, key=lambda x: _cache[x][0])[:to_drop]:
            _cache.pop(k, None)

def _get_sid_lock(sid: str) -> asyncio.Lock:
    lk = _sid_locks.get(sid)
    if lk is None:
        lk = asyncio.Lock(); _sid_locks[sid] = lk
    return lk

def _cache_get(sid: str) -> Optional[dict]:
    ent = _cache.get(sid)
    if not ent:
        _cache_gc()
        return None
    ts, payload = ent
    if (time.time() - ts) > CACHE_TTL_SEC:
        _cache.pop(sid, None)
        _cache_gc()
        return None
    return payload

def _cache_put(sid: str, payload: dict):
    _cache_gc()
    _cache[sid] = (time.time(), payload)
    _cache_gc()

async def scrape_data(sid: str, timeout_sec: int = 9) -> dict:
    started = time.time()
    tr = Trace(log_enabled)
    tr.mark("start", sid=sid, timeout=timeout_sec)

    # 캐시
    cached = _cache_get(sid)
    if cached:
        tr.mark("cache_hit")
        if log_enabled: cached["_trace"] = tr.export()
        return cached

    # 동시성 게이트
    try:
        t0 = time.time()
        await asyncio.wait_for(_sem.acquire(), timeout=QUEUE_TIMEOUT)
        tr.mark("sem_acquired", wait=round(time.time()-t0,3))
    except asyncio.TimeoutError:
        tr.mark("sem_timeout", waited=QUEUE_TIMEOUT)
        raise OverCapacityError("서버 혼잡: 잠시 후 다시 시도해주세요")

    try:
        # 동일 sid 코얼레싱
        sid_lock = _get_sid_lock(sid)
        t1 = time.time()
        await sid_lock.acquire()
        tr.mark("sid_lock_acquired", wait=round(time.time()-t1,3))

        try:
            # 더블체크 캐시
            c2 = _cache_get(sid)
            if c2:
                tr.mark("cache_hit_2")
                if log_enabled: c2["_trace"] = tr.export()
                return c2

            # CSR 전용 수집
            payload = await _browser_fetch_and_parse(sid, started, timeout_sec, tr)
            if log_enabled: payload["_trace"] = tr.export()
            _cache_put(sid, payload)
            return payload

        except asyncio.CancelledError:
            tr.mark("cancelled_top"); raise
        finally:
            try: sid_lock.release()
            except Exception: pass
    finally:
        try: _sem.release()
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
    except Exception:
        pass
    _launch_future = None
