#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import argparse
import sys
import asyncio
import time
import json
import re
from datetime import datetime
from pyppeteer import launch
from lxml import html

# 전역 변수
browser = None
page = None
log_enabled = False
start_time = None

def log(message: str):
    """사용자 정의 로그만 출력"""
    if not log_enabled:
        return
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    print(f"[LOG {now}] {message}")

def compute_date_finish_info(date_finish_str: str) -> str:
    """‘YYYY.MM.DD HH:MM’ 문자열을 받아서 ‘n시간 m분 전 종료’ 반환"""
    try:
        finish_dt = datetime.strptime(date_finish_str, "%Y.%m.%d %H:%M")
        diff = datetime.now() - finish_dt
        total_min = int(diff.total_seconds() // 60)
        h, m = divmod(total_min, 60)
        return f"{h}시간 {m}분 전 종료" if h > 0 else f"{m}분 전 종료"
    except Exception as e:
        log(f"compute_date_finish_info 에러: {e}")
        return ""

def parse_status(status_text: str) -> str:
    """‘xx분 사용중’ 매칭 시 ‘사용중 (x시간 y분)’ 형태로 변환"""
    m = re.match(r"(\d+)분\s*사용중", status_text.strip())
    if m:
        total_min = int(m.group(1))
        h, mm = divmod(total_min, 60)
        if h > 0:
            return f"사용중 ({h}시간 {mm}분)"
        return f"사용중 ({mm}분)"
    return status_text.strip()

async def init_browser():
    """브라우저와 페이지를 전역으로 한 번만 띄우기"""
    global browser, page
    if browser is None:
        browser = await launch(
            headless=True,
            dumpio=False,
            args=[
                "--no-sandbox",
                "--disable-setuid-sandbox",
                "--disable-dev-shm-usage",
                "--disable-gpu",
                "--disable-extensions",
                "--disable-logging",
                "--log-level=3",
            ],
            executablePath="/usr/lib/chromium/chromium"
        )
        page = await browser.newPage()
        await page.setUserAgent(
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0 Safari/539.36"
        )
        await page.setViewport({"width": 800, "height": 600})
        log("브라우저 및 페이지 초기화 완료")
    return page

async def scrape_data(sid: str):
    global start_time
    overall_start = time.time()
    log(f"scrape_data 시작 (sid={sid})")
    page_inst = await init_browser()

    # 1) 페이지 로드 + 재시도 (max 2회)
    url = f"https://www.ev.or.kr/nportal/monitor/evMapInfo.do?sid={sid}&pFlag=Y"
    html_content = None
    for attempt in range(2):
        try:
            log(f"페이지 로드 시도 {attempt+1}/2: {url}")
            await page_inst.goto(url, {"waitUntil": "domcontentloaded", "timeout": 5000})
            await page_inst.waitForSelector("#form", {"timeout": 2000})
            html_content = await page_inst.content()
            if html_content.strip():
                break
            raise ValueError("빈 콘텐츠")
        except Exception as e:
            log(f"로드 에러: {e}")
            if attempt < 1:
                await asyncio.sleep(0.5)
                continue
            # 2회 실패 시 에러 리턴
            result = {
                "title": "", "company_name": "",
                "total_chargers": 0, "used_chargers": 0, "remaining_chargers": 0,
                "address": "", "chargers_info": [], "printString": "",
                "msg": f"ERROR: 페이지 로드 실패 ({e})",
                "total_time": f"{time.time() - overall_start:.2f} seconds"
            }
            print(json.dumps(result, ensure_ascii=False))
            return

    tree = html.fromstring(html_content)

    # 2) 제목 추출: <form id="form"> 내부의 <h4>
    form_h4 = tree.xpath('//form[@id="form"]//h4')
    title = ""
    if form_h4:
        title = "".join(form_h4[0].xpath('text()')).strip()
    log(f"title: {title}")

    # 3) 회사명: <div class="org_me"><span>회사명</span></div>
    company_nodes = tree.xpath('//div[@class="org_me"]/span/text()')
    company_name = company_nodes[0].strip() if company_nodes else ""
    log(f"company_name: {company_name}")

    # 4) 충전기 정보: table.table01 > tbody > tr 각각
    chargers_info = []
    rows = tree.xpath('//table[@class="table01"]//tbody/tr')
    for idx, row in enumerate(rows):
        try:
            tds = row.xpath('./td')
            if len(tds) < 3:
                continue
            charger_type = tds[0].text_content().strip()

            td_text = tds[2].text_content().strip()
            # 상태
            state_node = tds[2].xpath('./span[@class="state"]/text()')
            raw_status = state_node[0].strip() if state_node else td_text.split('\n')[0].strip()
            charger_status = parse_status(raw_status)
            # 종료시간
            rdate_node = tds[2].xpath('./span[@class="rdate"]/text()')
            date_finish = (
                rdate_node[0].strip()
                if rdate_node
                else (td_text.split('\n')[-1].strip() if '\n' in td_text else "")
            )
            date_finish_info = compute_date_finish_info(date_finish) if date_finish else ""

            # 새로운 조건 추가
            if "사용중" in charger_status:
                date_finish_info = ""

            chargers_info.append({
                "type": charger_type,
                "status": charger_status,
                "dateFinish": date_finish,
                "dateFinishInfo": date_finish_info
            })
            log(f"charger[{idx}]: {charger_type}, {charger_status}, {date_finish_info}")
        except Exception as e:
            log(f"parse row[{idx}] 에러: {e}")
            continue

    total = len(chargers_info)
    used = sum(1 for c in chargers_info if "사용가능" not in c["status"])
    remaining = total - used
    log(f"total={total}, used={used}, remaining={remaining}")

    # 5) 주소: table.table03 > tbody > tr > td
    addr_nodes = tree.xpath('//table[@class="table03"]//tbody/tr/td/text()')
    address = addr_nodes[0].strip() if addr_nodes else ""
    log(f"address: {address}")

    # 6) 결과 조합 및 출력
    result = {
        "title": title,
        "company_name": company_name,
        "total_chargers": total,
        "used_chargers": used,
        "remaining_chargers": remaining,
        "address": address,
        "chargers_info": chargers_info,
        "printString": "\n".join(
            f"{i+1}. {c['status']} ({c['dateFinishInfo']})" if c["dateFinishInfo"]
            else f"{i+1}. {c['status']}"
            for i, c in enumerate(chargers_info)
        ),
        "msg": "SUCCESS",
        "total_time": f"{time.time() - overall_start:.2f} seconds"
    }
    print(json.dumps(result, ensure_ascii=False))

async def shutdown():
    global browser
    if browser:
        await browser.close()
        log("브라우저 종료 완료")

if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="EV 충전소 정보 스크래퍼")
    parser.add_argument("--sid", default="PL005189", help="스크랩할 SID (기본: PL005189)")
    parser.add_argument("--log", action="store_true", help="디버깅 로그 출력")
    args = parser.parse_args()

    start_time = datetime.now()
    log_enabled = args.log
    sid = args.sid

    log(f"스크립트 시작, sid={sid}, log={log_enabled}")
    try:
        asyncio.get_event_loop().run_until_complete(scrape_data(sid))
    finally:
        asyncio.get_event_loop().run_until_complete(shutdown())
