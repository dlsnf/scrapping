#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os, asyncio, time, re
from datetime import datetime
from typing import Dict, Tuple, Optional
import pytz, requests
from lxml import html
from pyppeteer import launch

# ===== 환경 =====
MAX_CONCURRENCY     = int(os.getenv("MAX_CONCURRENCY", "2"))
QUEUE_TIMEOUT       = float(os.getenv("QUEUE_TIMEOUT", "3.0"))
NAV_TIMEOUT_DEFAULT = int(os.getenv("NAV_TIMEOUT_MS", "12000"))
RETRY_COUNT         = int(os.getenv("RETRY_COUNT", "2"))
HTTP_CONN_TIMEOUT   = float(os.getenv("HTTP_CONN_TIMEOUT", "2.0"))
HTTP_READ_TIMEOUT   = float(os.getenv("HTTP_READ_TIMEOUT", "3.5"))
CACHE_TTL_SEC       = int(os.getenv("CACHE_TTL_SEC", "30"))
LAUNCH_TIMEOUT_SEC  = float(os.getenv("LAUNCH_TIMEOUT_SEC", "8.0"))   # 브라우저 초기화 상한
ALLOW_BROWSER       = int(os.getenv("ALLOW_BROWSER", "1"))            # 0이면 브라우저 폴백 금지

CHROME_BIN = os.getenv("CHROME_BIN", "/usr/bin/chromium")

# ===== 전역 =====
browser = None
log_enabled = False
_init_lock = asyncio.Lock()
_sem = asyncio.Semaphore(MAX_CONCURRENCY)
_sid_locks: Dict[str, asyncio.Lock] = {}
_cache: Dict[str, Tuple[float, dict]] = {}

class OverCapacityError(RuntimeError): pass

def log(msg: str):
    if log_enabled:
        print(f"[LOG {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}")

# ===== 타임아웃 예산 =====
def _compute_budgets(timeout_sec: int):
    budget_ms = max(2000, (timeout_sec - 1) * 1000)  # 1초는 마진
    nav_ms = min(NAV_TIMEOUT_DEFAULT, int(budget_ms * 0.6))
    http_read = min(HTTP_READ_TIMEOUT, max(1.0, (budget_ms / 1000.0) * 0.4))
    return nav_ms, http_read

# ===== 파싱 유틸 =====
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
    # 폼이 없을 수도 있으니 테이블 기준 보완
    h4_nodes = tree.xpath('//form[@id="form"]//h4') or tree.xpath('//h4')
    title = ''.join(h4_nodes[0].xpath('./text()')).strip() if h4_nodes else ""
    company_nodes = tree.xpath('//div[@class="org_me"]/span/text()')
    company_name = company_nodes[0].strip() if company_nodes else ""

    chargers_info = []
    rows = tree.xpath('//table[@class="table01"]//tbody/tr') or tree.xpath('//tbody/tr')
    for idx, row in enumerate(rows):
        try:
            tds = row.xpath('./td')
            if len(tds) < 3:
                continue
            charger_type = tds[0].text_content().strip()
            td_text = tds[2].text_content().strip()
            state_node = tds[2].xpath('./span[@class="state"]/text()')
            raw_status = state_node[0].strip() if state_node else td_text.split('\n')[0].strip()
            rdate_node = tds[2].xpath('./span[@class="rdate"]/text()')
            date_finish = (rdate_node[0].strip() if rdate_node
                           else (td_text.split('\n')[-1].strip() if '\n' in td_text else ""))

            if "충전중" in raw_status or "사용중" in raw_status:
                charger_status = parse_status(raw_status); date_finish_info = ""
            else:
                charger_status = parse_status(raw_status); date_finish_info = compute_date_finish_info(date_finish)

            chargers_info.append({
                "type": charger_type,
                "status": charger_status,
                "dateFinish": date_finish,
                "dateFinishInfo": date_finish_info
            })
            log(f"▶ row[{idx}] {charger_type} / {charger_status} / {date_finish_info}")
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

# ===== HTTP 2-스텝 (워밍업 → 본요청) =====
def _http_fetch_and_parse(sid: str, started_at: float, timeout_sec: int):
    nav_ms, http_read = _compute_budgets(timeout_sec)
    base = "https://www.ev.or.kr/nportal/monitor/evMapInfo.do?pFlag=Y"
    url  = f"https://www.ev.or.kr/nportal/monitor/evMapInfo.do?sid={sid}&pFlag=Y"
    headers = {
        "User-Agent": ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                       "(KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36"),
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7",
        "Cache-Control": "no-cache", "Pragma": "no-cache", "Connection": "close",
        "Referer": "https://www.ev.or.kr/nportal/monitor/evMapInfo.do"
    }
    with requests.Session() as s:
        s.headers.update(headers)
        last_err = None
        for attempt in range(RETRY_COUNT):
            try:
                # 1) 워밍업: 쿠키/리다이렉트 확보
                s.get(base, timeout=(HTTP_CONN_TIMEOUT, min(http_read, 2.0)), allow_redirects=True)
                # 2) 본요청
                resp = s.get(url, timeout=(HTTP_CONN_TIMEOUT, http_read), allow_redirects=True)
                if resp.status_code >= 500:
                    last_err = f"HTTP {resp.status_code}"; continue
                resp.raise_for_status()
                text = resp.text
                # 폼 또는 테이블 확인으로 기준 완화
                if ('id="form"' not in text) and ('class="table01"' not in text):
                    last_err = "폼/테이블 미존재"; continue
                return _parse_html_to_payload(text, started_at)
            except Exception as e:
                last_err = str(e)
        raise RuntimeError(f"HTTP 경로 실패: {last_err}")

# ===== 브라우저 관리 =====
async def background_warmup():
    # 컨테이너 기동 직후 미리 한 번만 띄워둠 (요청과 별개)
    if not ALLOW_BROWSER:
        return
    try:
        await asyncio.sleep(1.0)
        await init_browser()
    except Exception as e:
        log(f"웜업 스킵: {e}")

async def init_browser():
    global browser
    if (not ALLOW_BROWSER):
        raise RuntimeError("브라우저 폴백 비활성(ALLOW_BROWSER=0)")
    if browser is not None:
        return browser
    async with _init_lock:
        if browser is not None:
            return browser
        log("브라우저 초기화 시도")
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
        ]
        # 런칭 상한을 강제해 PHP 10s 안에 판단 가능
        b = await asyncio.wait_for(launch(
            headless=True, dumpio=False, args=args,
            executablePath=CHROME_BIN, userDataDir="/tmp/chrome-data"
        ), timeout=LAUNCH_TIMEOUT_SEC)
        browser = b
        try:
            b.on('disconnected', lambda *a, **k: asyncio.create_task(_on_browser_disconnected()))
        except Exception:
            pass
        log("브라우저 초기화 완료")
    return browser

async def _on_browser_disconnected():
    global browser
    log("브라우저 연결 종료 감지 → 재기동 예정")
    try:
        if browser:
            await browser.close()
    except Exception:
        pass
    browser = None

async def _new_page(nav_timeout_ms: int):
    try:
        br = await init_browser()
        p = await br.newPage()
    except Exception:
        br = await init_browser()  # 한 번 더 시도
        p = await br.newPage()
    await p.setUserAgent("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36")
    await p.setViewport({"width": 640, "height": 480})
    p.setDefaultNavigationTimeout(nav_timeout_ms)
    # 리소스 차단
    await p.setRequestInterception(True)
    async def _on_req(req):
        try:
            if req.resourceType in ("image","stylesheet","font","media","other"): await req.abort()
            else: await req.continue_()
        except Exception: pass
    p.on("request", lambda r: asyncio.ensure_future(_on_req(r)))
    return p

async def _browser_fetch_and_parse(sid: str, started_at: float, timeout_sec: int):
    nav_ms, _ = _compute_budgets(timeout_sec)
    url = f"https://www.ev.or.kr/nportal/monitor/evMapInfo.do?sid={sid}&pFlag=Y"
    page = await _new_page(nav_ms)
    last_err = None
    try:
        for attempt in range(RETRY_COUNT):
            try:
                await page.goto(url, {"waitUntil": "domcontentloaded", "timeout": nav_ms})
                await page.waitForSelector("#form, .table01", {"timeout": min(3000, nav_ms)})
                html_content = await page.content()
                return _parse_html_to_payload(html_content, started_at)
            except asyncio.CancelledError:
                raise
            except Exception as e:
                last_err = str(e)
                await asyncio.sleep(0.2)
        raise RuntimeError(f"브라우저 경로 실패: {last_err}")
    finally:
        try: await page.close()
        except Exception: pass

# ===== 캐시/코얼레싱 =====
def _get_sid_lock(sid: str) -> asyncio.Lock:
    lk = _sid_locks.get(sid)
    if lk is None:
        lk = asyncio.Lock(); _sid_locks[sid] = lk
    return lk

def _cache_get(sid: str) -> Optional[dict]:
    ent = _cache.get(sid)
    if not ent: return None
    ts, payload = ent
    return payload if (time.time() - ts) <= CACHE_TTL_SEC else None

def _cache_put(sid: str, payload: dict): _cache[sid] = (time.time(), payload)

# ===== 외부 진입점 =====
async def scrape_data(sid: str, timeout_sec: int = 9) -> dict:
    started = time.time()
    log(f"▶ scrape_data 시작 (sid={sid})")
    cached = _cache_get(sid)
    if cached:
        log("캐시 히트"); return cached

    acquired = False
    try:
        await asyncio.wait_for(_sem.acquire(), timeout=QUEUE_TIMEOUT)
        acquired = True

        sid_lock = _get_sid_lock(sid)
        async with sid_lock:
            cached2 = _cache_get(sid)
            if cached2:
                log("코얼레싱 중 캐시 히트"); return cached2

            # 1) HTTP 2-스텝
            try:
                payload = _http_fetch_and_parse(sid, started, timeout_sec)
                _cache_put(sid, payload); log("HTTP 경로 성공")
                return payload
            except Exception as http_err:
                log(f"HTTP 경로 실패 → 브라우저 폴백: {http_err}")

            # 2) 브라우저 폴백 (허용될 때만, 예산 내 1회)
            if ALLOW_BROWSER:
                payload = await _browser_fetch_and_parse(sid, started, timeout_sec)
                _cache_put(sid, payload)
                return payload
            else:
                raise RuntimeError("브라우저 비활성(ALLOW_BROWSER=0) + HTTP 실패")

    except asyncio.TimeoutError:
        raise OverCapacityError("서버 혼잡: 잠시 후 다시 시도해주세요")
    finally:
        if acquired: _sem.release()

async def shutdown():
    global browser
    if browser:
        try: await browser.close(); log("브라우저 종료 완료")
        except Exception: pass
        browser = None
