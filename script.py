from flask import Flask, request, jsonify
import asyncio
from pyppeteer import launch
from bs4 import BeautifulSoup
import time
from datetime import datetime
import pytz
import threading
import re  # 문자열 파싱용

app = Flask(__name__)

seoul_tz = pytz.timezone('Asia/Seoul')

def parse_time_diff(end_time_str):
    """dateFinish 문자열을 datetime으로 파싱하고, 현재(서울)와 차이 계산"""
    try:
        end_dt = datetime.strptime(end_time_str, '%Y.%m.%d %H:%M')
        end_dt = seoul_tz.localize(end_dt)
        now = datetime.now(seoul_tz)
        diff = now - end_dt
        if diff.total_seconds() < 0:
            return "미래 시간"  # 예외 처리
        hours = int(diff.total_seconds() / 3600)
        minutes = int((diff.total_seconds() % 3600) / 60)
        if hours > 0:
            return f"{hours}시간 {minutes}분"
        elif minutes > 0:
            return f"{minutes}분"
        else:
            return "방금"
    except:
        return "시간 파싱 오류"

def extract_elapsed_time(status_text):
    """충전중 status_text에서 경과 시간 추출 (e.g., "14시간6분 충전중" -> "14시간 6분")"""
    # 시간 패턴 매치 (e.g., "X시간Y분" 또는 "X분")
    match = re.search(r'(\d+)시\s*간\s*(\d+)분|(\d+)분', status_text)
    if match:
        if match.group(1):  # 시간+분 패턴
            return f"{match.group(1)}시간 {match.group(2)}분"
        elif match.group(3):  # 분만 패턴
            return f"{match.group(3)}분"
    return ""

async def scrape_async(sid, log, start_time):
    """비동기 스크래핑 로직 (분리)"""
    try:
        # Pyppeteer로 CSR 로드 (시스템 Chrome 사용)
        browser = await launch(
            headless=True, 
            args=['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
            executablePath='/usr/bin/google-chrome-stable'
        )
        page = await browser.newPage()
        url = f"https://www.ev.or.kr/nportal/monitor/evMapInfo.do?sid={sid}&pFlag=Y"
        await page.goto(url, {'waitUntil': 'networkidle2'})
        await page.waitFor(5000)  # 추가 대기
        html = await page.content()
        await browser.close()

        soup = BeautifulSoup(html, 'html.parser')

        # title: id="form" > h4 전체 텍스트, span 제거 (강화)
        form = soup.find('div', id='form')
        title_elem = form.find('h4') if form else None
        title = title_elem.get_text(strip=True) if title_elem else ''
        if title_elem:
            spans = title_elem.find_all('span')
            for span in spans:
                title = title.replace(span.get_text(strip=True), '').strip()
            title = re.sub(r'\s+', ' ', title).strip()  # 다중 공백 제거

        # company_name: class="org_me" text
        company_elem = soup.find('div', class_='org_me')
        company_name = company_elem.get_text(strip=True) if company_elem else ''

        # address: class="table03" > tbody > tr:first > td text
        table03 = soup.find('table', class_='table03')
        address = ''
        if table03:
            tbody = table03.find('tbody')
            if tbody:
                first_tr = tbody.find('tr')
                if first_tr:
                    first_td = first_tr.find('td')
                    if first_td:
                        address = first_td.get_text(strip=True)

        # chargers_info: table01 > tbody > 각 tr 파싱
        chargers = []
        table01 = soup.find('table', class_='table01')
        if table01:
            tbody = table01.find('tbody')
            if tbody:
                rows = tbody.find_all('tr')
                for row in rows:
                    tds = row.find_all('td')
                    if len(tds) >= 3:
                        type_ = tds[0].get_text(strip=True)
                        # 세 번째 td에서 status_elem (class="state")와 rdate 추출
                        third_td = tds[2]
                        status_elem = third_td.find('span', class_='state')
                        rdate_elem = third_td.find(class_='rdate')  # 태그 구별 없이 클래스명으로 추출

                        # status 원본 텍스트 (시간 포함)
                        status_full = status_elem.get_text(strip=True) if status_elem else ''
                        # status: 시간 제거 후 "충전중" 또는 "충전가능"만 추출
                        status_match = re.search(r'(충전중|충전가능|사용가능)$', status_full)
                        status = (status_match.group(1) + '\n') if status_match else '알 수 없음\n'

                        # dateFinish: rdate 클래스 요소의 text (태그 무관)
                        date_finish = rdate_elem.get_text(strip=True) if rdate_elem else ''

                        # dateFinishInfo 분기 처리
                        if status.strip() == '충전가능':
                            date_finish_info = parse_time_diff(date_finish) + " 전 종료" if date_finish else ''
                        elif status.strip() == '충전중':
                            date_finish_info = extract_elapsed_time(status_full)  # 경과 시간만
                        else:
                            date_finish_info = ''

                        chargers.append({
                            "type": type_,
                            "status": status,
                            "dateFinish": date_finish,
                            "dateFinishInfo": date_finish_info
                        })

        total_chargers = len(chargers)
        used_chargers = sum(1 for c in chargers if c['status'].strip() == '충전중')  # 충전중만 사용으로 카운트
        remaining_chargers = total_chargers - used_chargers

        # printString 생성: chargers_info 개수만큼 번호 매김, 형식: "1. {status}\n({dateFinishInfo}) / {type}\n\n..."
        print_lines = []
        for i, charger in enumerate(chargers, 1):
            info = f"{i}. {charger['status'].strip()}\n({charger['dateFinishInfo']}) / {charger['type']}"
            print_lines.append(info)
        print_string = '\n\n'.join(print_lines)

        total_time = round(time.time() - start_time, 2)

        result = {
            "title": title,
            "company_name": company_name,
            "total_chargers": total_chargers,
            "used_chargers": used_chargers,
            "remaining_chargers": remaining_chargers,
            "address": address,
            "chargers_info": chargers,
            "msg": "SUCCESS",
            "printString": print_string,
            "total_time": f"{total_time} seconds"
        }

        if log:
            print(f"[LOG] Scraping completed for sid: {sid}, time: {total_time}s")

        return result
    except Exception as e:
        if log:
            print(f"[LOG] Scraping error for sid: {sid}: {str(e)}")
        return {"msg": "ERROR", "error": str(e)}

@app.route('/scrape', methods=['GET'])
def scrape():
    start_time = time.time()
    sid = request.args.get('sid')
    if not sid:
        return jsonify({"msg": "ERROR", "error": "sid required"}), 400

    log = request.args.get('log', '0') == '1'
    if log:
        print(f"[LOG] Scraping started for sid: {sid}")

    # 항상 새 이벤트 루프 생성 (스레드 안전)
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    try:
        result = loop.run_until_complete(scrape_async(sid, log, start_time))
    finally:
        loop.close()

    return jsonify(result)

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=8000, threaded=False)  # threaded=False로 변경 (메인 스레드 고정)