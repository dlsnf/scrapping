#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import os, sys, asyncio, time, re, json, random
from datetime import datetime
from typing import Dict, Tuple, Optional, Any, List

import pytz
from lxml import html
from pyppeteer import launch

# --------------------
# 설정
# --------------------
CHROME_BIN             = os.getenv("CHROME_BIN", "/usr/bin/chromium")
PYPPETEER_EXECUTABLE   = os.getenv("PYPPETEER_EXECUTABLE_PATH", CHROME_BIN)

MAX_NAV_MS             = int(os.getenv("NAV_DEFAULT_TIMEOUT_MS", "6000"))    # 1차 goto 타임아웃
RETRY_NAV_MS           = int(os.getenv("NAV_RETRY_TIMEOUT_MS",   "6000"))    # 2차 goto 타임아웃
POLL_WINDOW_SEC        = float(os.getenv("POLL_WINDOW_SEC", "5.0"))
POLL_INTERVAL_SEC      = float(os.getenv("POLL_INTERVAL_SEC", "0.35"))
LAUNCH_TIMEOUT_SEC     = float(os.getenv("LAUNCH_TIMEOUT_SEC", "12.0"))
HEADLESS               = True

LOG_ENABLED            = False

def now() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")

def log(*args):
    if LOG_ENABLED:
        print("[LOG %s]" % now(), *args, flush=True)

# --------------------
# 시간/포맷 유틸
# --------------------
def compute_date_finish_info(date_finish_str: str) -> str:
    if not date_finish_str:
        return ""
    try:
        seoul = pytz.timezone("Asia/Seoul")
        naive = datetime.strptime(date_finish_str.strip(), "%Y.%m.%d %H:%M")
        finish_dt = seoul.localize(naive)
        now_k = datetime.now(seoul)
        diff = now_k - finish_dt
        if diff.total_seconds() < 0:
            return ""
        if diff.total_seconds() < 60:
            return "방금 전 종료"
        total_min = int(diff.total_seconds() // 60)
        h, m = divmod(total_min, 60)
        if h >= 24:
            d = h // 24; rh = h % 24
            return f"{d}일 {rh}시간 전 종료" if rh > 0 else f"{d}일 전 종료"
        return f"{h}시간 {m}분 전 종료" if h > 0 else f"{m}분 전 종료"
    except Exception:
        return ""

def parse_status(status_text: str) -> str:
    text = (status_text or "").strip()
    # "5시간 13분 충전중" / "충전중 5시간 13분" / "충전중" 등 케이스
    m = re.search(r"(?:(\d+)\s*시간)?\s*(?:(\d+)\s*분)?\s*(?:충전중|사용중)", text)
    if not m:
        m = re.search(r"(?:충전중|사용중)\s*(?:(\d+)\s*시간)?\s*(?:(\d+)\s*분)?", text)
    if m:
        hh, mm = m.group(1), m.group(2)
        # 표기는 "충전중\n(...)" 또는 "사용중\n(...)" 형태
        base = "충전중" if "충전중" in text else ("사용중" if "사용중" in text else text)
        if hh or mm:
            h = int(hh) if hh else 0
            mval = int(mm) if mm else 0
            # 60분 이상은 시/분으로 정규화
            if not hh and mval >= 60:
                h2, r2 = divmod(mval, 60)
                h += h2; mval = r2
            if h and mval:
                return f"{base}\n({h}시간 {mval}분)"
            if h:
                return f"{base}\n({h}시간)"
            if mval:
                return f"{base}\n({mval}분)"
        return base + "\n"
    # 그 외 상태(충전가능/충전불가 등)는 줄바꿈만 부여
    return text + "\n"

def build_print_string(chargers_info: List[dict]) -> str:
    if not chargers_info:
        return ""
    parts = []
    for i, c in enumerate(chargers_info):
        st = c.get("status", "").rstrip("\n")
        tp = c.get("type", "")
        dfi = c.get("dateFinishInfo", "")
        if dfi:
            parts.append(f"{i+1}. {st}\n({dfi}) / {tp}")
        else:
            parts.append(f"{i+1}. {st} / {tp}")
    return "\n\n".join(parts)

# --------------------
# 파서
# --------------------
def parse_dom(html_content: str) -> dict:
    tree = html.fromstring(html_content)

    # 타이틀/회사
    h4_nodes = tree.xpath('//form[@id="form"]//h4') or tree.xpath('//h4')
    title = ''.join(h4_nodes[0].xpath('./text()')).strip() if h4_nodes else ""

    company_nodes = tree.xpath('//div[@class="org_me"]/span/text()')
    company_name = company_nodes[0].strip() if company_nodes else ""

    # 행 찾기
    rows = tree.xpath('//table[@class="table01"]//tbody/tr') \
        or tree.xpath('//table[contains(@class,"tbl_list") or contains(@class,"tbl")]//tbody/tr') \
        or tree.xpath('//tbody/tr')

    chargers_info = []
    for row in rows:
        try:
            tds = row.xpath('./td')
            if len(tds) < 3:
                continue
            ctype = tds[0].text_content().strip()
            td_text = tds[2].text_content().strip()

            state_node = tds[2].xpath('./span[@class="state"]/text()')
            raw_status = state_node[0].strip() if state_node else (td_text.split('\n')[0].strip() if td_text else "")
            rdate_node = tds[2].xpath('./span[@class="rdate"]/text()')
            date_finish = (rdate_node[0].strip() if rdate_node
                           else (td_text.split('\n')[-1].strip() if '\n' in td_text else ""))

            if ("충전중" in raw_status) or ("사용중" in raw_status):
                status = parse_status(raw_status)
                dfi    = ""
            else:
                status = parse_status(raw_status)
                dfi    = compute_date_finish_info(date_finish)

            chargers_info.append({
                "type": ctype,
                "status": status,
                "dateFinish": date_finish,
                "dateFinishInfo": dfi,
            })
        except Exception:
            continue

    # 주소
    addr_nodes = tree.xpath('//table[@class="table03"]//tbody/tr/td/text()')
    address = addr_nodes[0].strip() if addr_nodes else ""

    total = len(chargers_info)
    used = sum(1 for c in chargers_info if ("사용중" in c["status"]) or ("충전중" in c["status"]) or ("충전불가" in c["status"]))
    remaining = total - used

    return {
        "title": title,
        "company_name": company_name,
        "total_chargers": total,
        "used_chargers": used,
        "remaining_chargers": remaining,
        "address": address,
        "chargers_info": chargers_info,
    }

# --------------------
# XHR 캡처 → 파싱
# --------------------
def try_parse_from_xhr(blobs: List[Tuple[str, Any]]) -> Optional[dict]:
    for url, data in blobs:
        # data에서 list 후보를 찾아봄
        cands = []
        if isinstance(data, list):
            cands.append(data)
        elif isinstance(data, dict):
            for k in ("chargers", "items", "rows", "list", "data", "result"):
                v = data.get(k)
                if isinstance(v, list) and v:
                    cands.append(v)
        for arr in cands:
            chargers_info = []
            for it in arr:
                if not isinstance(it, dict):
                    continue
                t  = it.get("type") or it.get("connectorType") or it.get("cpTp") or ""
                st = it.get("status") or it.get("statNm") or it.get("state") or it.get("stat") or ""
                end= it.get("dateFinish") or it.get("endTime") or it.get("rdate") or it.get("lastEndTime") or ""
                st_txt = str(st)
                if ("충전중" in st_txt) or ("사용중" in st_txt):
                    status = parse_status(st_txt); dfi = ""
                else:
                    status = parse_status(st_txt); dfi = compute_date_finish_info(end) if end else ""
                chargers_info.append({
                    "type": str(t), "status": status,
                    "dateFinish": str(end), "dateFinishInfo": dfi
                })
            if chargers_info:
                total = len(chargers_info)
                used = sum(1 for c in chargers_info if ("사용중" in c["status"]) or ("충전중" in c["status"]) or ("충전불가" in c["status"]))
                remaining = total - used
                return {
                    "title": "", "company_name": "",
                    "total_chargers": total,
                    "used_chargers": used,
                    "remaining_chargers": remaining,
                    "address": "",
                    "chargers_info": chargers_info,
                }
    return None

# --------------------
# 메인 수집
# --------------------
async def fetch_once(sid: str, overall_timeout: int = 15) -> dict:
    started = time.time()
    url = f"https://www.ev.or.kr/nportal/monitor/evMapInfo.do?sid={sid}&pFlag=Y"

    # chromium args
    args = [
        "--no-sandbox", "--disable-setuid-sandbox",
        "--disable-gpu", "--disable-extensions",
        "--no-zygote", "--no-first-run",
        "--disable-background-networking",
        "--disable-default-apps", "--disable-sync",
        "--metrics-recording-only", "--mute-audio",
        "--blink-settings=imagesEnabled=false",
        "--proxy-server=direct://", "--proxy-bypass-list=*",
        "--disable-features=IsolateOrigins,site-per-process",
        "--disk-cache-dir=/tmp/chrome-cache",
        "--disable-software-rasterizer",
    ]

    # 브라우저 실행
    browser = await launch(
        headless=HEADLESS,
        dumpio=False,
        args=args,
        executablePath=PYPPETEER_EXECUTABLE,
    )
    page = None
    ctx = None

    xhr_blobs: List[Tuple[str, Any]] = []
    reason = ""
    try:
        # 컨텍스트/페이지
        ctx = await browser.createIncognitoBrowserContext()
        page = await ctx.newPage()

        await page.setUserAgent(
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
            "(KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36"
        )
        await page.setViewport({"width": 640, "height": 480})
        page.setDefaultNavigationTimeout(MAX_NAV_MS)

        await page.setExtraHTTPHeaders({
            "Accept-Language": "ko-KR,ko;q=0.9,en-US;q=0.8,en;q=0.7",
            "Cache-Control": "no-cache",
        })

        # XHR 캡처
        async def on_response(resp):
            try:
                headers = resp.headers or {}
                ct = (headers.get("content-type", "") or "").lower()
                rt = getattr(getattr(resp, "request", None), "resourceType", None)
                if (rt in ("xhr","fetch","other")) and ("application/json" in ct):
                    data = await resp.json()
                    xhr_blobs.append((resp.url, data))
            except Exception:
                pass
        page.on("response", lambda r: asyncio.create_task(on_response(r)))

        # 불필요 리소스 차단 (XHR/JS/HTML은 살림)
        await page.setRequestInterception(True)
        async def on_req(req):
            try:
                rt = getattr(req, "resourceType", None)
                if rt in ("image", "font", "media"):   # stylesheet은 유지(초기 DOM 레이아웃 안정화 용)
                    await req.abort()
                else:
                    await req.continue_()
            except Exception:
                try: await req.continue_()
                except Exception: pass
        page.on("request", lambda r: asyncio.create_task(on_req(r)))

        # 1차 진입
        log("goto 1:", url)
        ok_nav = False
        try:
            await page.goto(url, {"waitUntil": "domcontentloaded", "timeout": MAX_NAV_MS})
            ok_nav = True
        except Exception as e:
            reason = f"goto1_timeout:{type(e).__name__}"

        # selector 대기 (1차)
        if ok_nav:
            try:
                await page.waitForSelector(".table01 tbody tr, .tbl_list tbody tr, .tbl tbody tr", {"timeout": 1500})
            except Exception:
                pass

        # 폴링 (1차)
        deadline = time.time() + min(POLL_WINDOW_SEC, max(0.5, overall_timeout - (time.time()-started) - 2.0))
        while time.time() < deadline:
            # XHR 파싱 우선
            parsed = try_parse_from_xhr(xhr_blobs)
            if parsed and parsed.get("total_chargers", 0) > 0:
                payload = parsed
                payload["msg"] = "SUCCESS"
                payload["printString"] = build_print_string(payload["chargers_info"])
                payload["total_time"] = f"{time.time()-started:.2f} seconds"
                return payload

            # DOM 파싱
            html_content = await page.content()
            if "<tbody" in html_content and "table" in html_content:
                parsed = parse_dom(html_content)
                if parsed.get("total_chargers", 0) > 0:
                    payload = parsed
                    payload["msg"] = "SUCCESS"
                    payload["printString"] = build_print_string(payload["chargers_info"])
                    payload["total_time"] = f"{time.time()-started:.2f} seconds"
                    return payload
            await asyncio.sleep(POLL_INTERVAL_SEC + random.uniform(0, 0.05))

        # 2차 재시도 (reload or 재진입)
        log("retry goto 2")
        try:
            await page.goto(url, {"waitUntil": "domcontentloaded", "timeout": RETRY_NAV_MS})
        except Exception as e:
            reason = reason or f"goto2_timeout:{type(e).__name__}"

        try:
            await page.waitForSelector(".table01 tbody tr, .tbl_list tbody tr, .tbl tbody tr", {"timeout": 2000})
        except Exception:
            pass

        deadline2 = time.time() + min(POLL_WINDOW_SEC, max(0.5, overall_timeout - (time.time()-started) - 1.0))
        while time.time() < deadline2:
            parsed = try_parse_from_xhr(xhr_blobs)
            if parsed and parsed.get("total_chargers", 0) > 0:
                payload = parsed
                payload["msg"] = "SUCCESS"
                payload["printString"] = build_print_string(payload["chargers_info"])
                payload["total_time"] = f"{time.time()-started:.2f} seconds"
                return payload

            html_content = await page.content()
            if "<tbody" in html_content and "table" in html_content:
                parsed = parse_dom(html_content)
                if parsed.get("total_chargers", 0) > 0:
                    payload = parsed
                    payload["msg"] = "SUCCESS"
                    payload["printString"] = build_print_string(payload["chargers_info"])
                    payload["total_time"] = f"{time.time()-started:.2f} seconds"
                    return payload
            await asyncio.sleep(POLL_INTERVAL_SEC + random.uniform(0, 0.05))

        # 여기까지 오면 NO_DATA
        return {
            "title": "",
            "company_name": "",
            "total_chargers": 0,
            "used_chargers": 0,
            "remaining_chargers": 0,
            "address": "",
            "chargers_info": [],
            "printString": "",
            "msg": "NO_DATA",
            "reason": reason or "polling_timeout",
            "total_time": f"{time.time()-started:.2f} seconds",
        }

    finally:
        try:
            if page: await page.close()
        except Exception:
            pass
        try:
            if ctx: await ctx.close()
        except Exception:
            pass
        try:
            await browser.close()
        except Exception:
            pass

# --------------------
# CLI 엔트리
# --------------------
def parse_args(argv: List[str]) -> dict:
    sid = ""
    timeout = 15
    log_on = False
    for a in argv:
        if a.startswith("--sid="):
            sid = a.split("=",1)[1].strip()
        elif a.startswith("--timeout="):
            try: timeout = int(a.split("=",1)[1])
            except: pass
        elif a == "--log=1" or a == "--log":
            log_on = True
    return {"sid": sid, "timeout": timeout, "log": log_on}

if __name__ == "__main__":
    args = parse_args(sys.argv[1:])
    LOG_ENABLED = bool(args.get("log"))
    sid = args.get("sid","").strip()
    timeout = int(args.get("timeout", 15)) or 15

    if not sid:
        print(json.dumps({"msg":"NO_SID","error":"sid required"}), end="")
        sys.exit(2)

    try:
        loop = asyncio.get_event_loop()
    except Exception:
        loop = asyncio.new_event_loop(); asyncio.set_event_loop(loop)

    try:
        payload = loop.run_until_complete(fetch_once(sid, overall_timeout=timeout))
        # NO_DATA면 종료코드 3으로
        if payload.get("msg") != "SUCCESS" or not payload.get("chargers_info"):
            print(json.dumps(payload, ensure_ascii=False), end="")
            sys.exit(3)
        print(json.dumps(payload, ensure_ascii=False), end="")
        sys.exit(0)
    except Exception as e:
        out = {"msg":"ERROR","error":str(e)}
        print(json.dumps(out, ensure_ascii=False), end="")
        sys.exit(1)
