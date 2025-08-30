#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import asyncio
import time
import re
from datetime import datetime
import pytz

from pyppeteer import launch
from lxml import html

browser = None
log_enabled = False  # 디버그 로그를 터미널 테스트 시 켜기

def log(message: str):
    if not log_enabled:
        return
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[LOG {now}] {message}")

# -----------------------------------
# 콘솔 메시지 필터링 함수
# -----------------------------------
def handle_console(msg):
    if "ol is not defined" in msg.text:
        return
    print(f"[PAGE LOG] {msg.text}")

# -----------------------------------
# 브라우저 초기화 (싱글톤)
# -----------------------------------
async def init_browser():
    global browser
    if browser is None:
        browser = await launch(
            headless=True,
            dumpio=True,
            args=[
                "--no-sandbox",
                "--disable-setuid-sandbox",
                "--disable-dev-shm-usage",
                "--disable-gpu",
                "--disable-extensions",
                "--disable-logging",
                "--log-level=3",
            ],
            executablePath="/usr/bin/chromium"
        )
        log("브라우저 초기화 완료")
    return browser

# -----------------------------------
# 페이지 생성 (요청마다 새 페이지)
# -----------------------------------
async def new_page():
    br = await init_browser()
    page = await br.newPage()
    page.on("console", lambda msg: handle_console(msg))
    await page.setUserAgent(
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0 Safari/539.36"
    )
    await page.setViewport({"width": 800, "height": 600})
    return page

def compute_date_finish_info(date_finish_str: str) -> str:
    seoul = pytz.timezone("Asia/Seoul")
    naive = datetime.strptime(date_finish_str, "%Y.%m.%d %H:%M")
    finish_dt = seoul.localize(naive)
    now = datetime.now(seoul)
    diff = now - finish_dt

    if diff.total_seconds() < 0:
        return ""
    if diff.total_seconds() < 60:
        return "방금 전 종료"

    total_min = int(diff.total_seconds() // 60)
    h, m = divmod(total_min, 60)

    if h >= 24:
        d = h // 24
        rem_h = h % 24
        return f"{d}일 {rem_h}시간 전 종료"

    return f"{h}시간 {m}분 전 종료" if h > 0 else f"{m}분 전 종료"

def parse_status(status_text: str) -> str:
    text = status_text.strip()
    m = re.match(r"(?:(\d+)\s*시간)?\s*(?:(\d+)\s*분)?\s*충전중[’']?$", text)
    if m:
        hh = m.group(1)
        mm = m.group(2)
        if hh and mm:
            return f"충전중 ({int(hh)}시간 {int(mm)}분)"
        elif hh:
            return f"충전중 ({int(hh)}시간)"
        elif mm:
            minutes = int(mm)
            if minutes >= 60:
                h, r = divmod(minutes, 60)
                return f"충전중 ({h}시간 {r}분)"
            return f"충전중 ({minutes}분)"
        else:
            return "충전중"
    return text

# -----------------------------------
# 스크래핑 함수 (요청마다 page 생성)
# -----------------------------------
async def scrape_data(sid: str) -> dict:
    overall_start = time.time()
    log(f"▶ scrape_data 시작 (sid={sid})")
    page_inst = await new_page()
    log("▶ new_page 완료")

    url = f"https://www.ev.or.kr/nportal/monitor/evMapInfo.do?sid={sid}&pFlag=Y"
    html_content = None
    for attempt in range(2):
        try:
            log(f"▶ goto 시도 {attempt+1}")
            await page_inst.goto(url, {"waitUntil": "networkidle2", "timeout": 10000})
            await asyncio.sleep(0.5)  # JS 실행 안정화
            await page_inst.waitForSelector("#form", {"timeout": 2000})
            html_content = await page_inst.content()
            if html_content.strip():
                break
            raise ValueError("빈 콘텐츠")
        except Exception as e:
            log(f"▶ 페이지 로드 에러 시도 {attempt+1}: {e}")
            if attempt < 1:
                await asyncio.sleep(0.5)
                continue
            return {
                "title": "",
                "company_name": "",
                "total_chargers": 0,
                "used_chargers": 0,
                "remaining_chargers": 0,
                "address": "",
                "chargers_info": [],
                "printString": "",
                "msg": f"ERROR: 페이지 로드 실패 ({e})",
                "total_time": f"{time.time() - overall_start:.2f} seconds"
            }

    # HTML 파싱 (기존 로직 동일)
    tree = html.fromstring(html_content)
    h4_nodes = tree.xpath('//form[@id="form"]//h4')
    title = ''.join(h4_nodes[0].xpath('./text()')).strip() if h4_nodes else ""
    company_nodes = tree.xpath('//div[@class="org_me"]/span/text()')
    company_name = company_nodes[0].strip() if company_nodes else ""

    chargers_info = []
    rows = tree.xpath('//table[@class="table01"]//tbody/tr')
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
                charger_status = parse_status(raw_status)
                date_finish_info = ""
            else:
                charger_status = parse_status(raw_status)
                date_finish_info = compute_date_finish_info(date_finish)

            chargers_info.append({
                "type": charger_type,
                "status": charger_status,
                "dateFinish": date_finish,
                "dateFinishInfo": date_finish_info
            })
            log(f"▶ charger[{idx}]: {charger_type}, {charger_status}, {date_finish_info}")
        except Exception as e:
            log(f"▶ row[{idx}] 파싱 에러: {e}")
            continue

    total = len(chargers_info)
    used = sum(
        1 for c in chargers_info
        if ("사용중" in c["status"]) or ("충전중" in c["status"]) or ("충전불가" in c["status"])
    )
    remaining = total - used
    addr_nodes = tree.xpath('//table[@class="table03"]//tbody/tr/td/text()')
    address = addr_nodes[0].strip() if addr_nodes else ""

    print_string = "\n\n".join(
        f"{i+1}. {c['status']} ({c['dateFinishInfo']}) / {c['type']}" if c['dateFinishInfo']
        else f"{i+1}. {c['status']} / {c['type']}"
        for i, c in enumerate(chargers_info)
    )

    await page_inst.close()  # ----------------------------------- 중요: 요청마다 페이지 종료

    return {
        "title": title,
        "company_name": company_name,
        "total_chargers": total,
        "used_chargers": used,
        "remaining_chargers": remaining,
        "address": address,
        "chargers_info": chargers_info,
        "printString": print_string,
        "msg": "SUCCESS",
        "total_time": f"{time.time() - overall_start:.2f} seconds"
    }

async def shutdown():
    global browser
    if browser:
        await browser.close()
        log("브라우저 종료 완료")
