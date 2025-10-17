import asyncio
import time
import json
from flask import Flask, request, jsonify
from pyppeteer import launch
from bs4 import BeautifulSoup
import pytz
from datetime import datetime
import nest_asyncio
nest_asyncio.apply()

app = Flask(__name__)

# 브라우저 풀 (재사용 위해 전역)
browser = None

async def get_browser():
    global browser
    if browser is None or browser.process.poll() is not None:  # poll() not None = 종료됨
        browser = await launch(
            headless=True,
            args=['--no-sandbox', '--disable-setuid-sandbox'],
            handleSIGINT=False,
            handleSIGTERM=False,
            handleSIGHUP=False
        )
    return browser

async def scrape_ev_status(sid):
    start_time = time.time()
    
    # 새 브라우저 launch
    browser = await launch(
        headless=True,
        args=['--no-sandbox', '--disable-setuid-sandbox'],
        handleSIGINT=False,
        handleSIGTERM=False,
        handleSIGHUP=False
    )
    page = await browser.newPage()
    
    try:
        # 대상 URL (타임아웃 60초)
        url = f"https://www.ev.or.kr/nportal/monitor/evMapInfo.do?sid={sid}&pFlag=Y"
        await page.goto(url, {'timeout': 60000})
        
        # 로딩 대기: 0.5초 간격으로 최대 10회, title 요소 체크
        title = None
        for _ in range(10):
            content = await page.content()
            soup = BeautifulSoup(content, 'html.parser')
            title_input = soup.find('input', id='stat_nm')
            if title_input and title_input.get('value'):
                title = title_input.get('value')
                break
            await asyncio.sleep(0.5)
        
        if not title:
            return {"msg": "FAIL: Title not found after 10 attempts"}
        
        # HTML 파싱
        soup = BeautifulSoup(await page.content(), 'html.parser')
        
        # title
        title = soup.find('input', id='stat_nm').get('value')
        
        # company_name
        company_elem = soup.find(class_='org_me')
        company_name = company_elem.find('span').text.strip() if company_elem and company_elem.find('span') else ""
        
        # address
        address_table = soup.find(class_='table03')
        address = address_table.find('tbody').find('tr').find('td').text.strip() if address_table else ""
        
        # chargers_info
        chargers_info = []
        table = soup.find(class_='table01')
        if table:
            rows = table.find('tbody').find_all('tr')
            for row in rows:
                tds = row.find_all('td')
                if len(tds) >= 3:
                    charger_type = tds[0].text.strip()
                    status_elem = tds[2].find(class_='state')
                    status = status_elem.text.strip() if status_elem else ""
                    
                    date_finish_elem = tds[2].find(class_='rdate')
                    date_finish = date_finish_elem.text.strip() if date_finish_elem else ""
                    
                    # dateFinishInfo 계산 (서울 시간 기준)
                    date_finish_info = ""
                    if date_finish:
                        try:
                            dt_finish_naive = datetime.strptime(date_finish, "%Y.%m.%d %H:%M")
                            dt_finish = pytz.timezone('Asia/Seoul').localize(dt_finish_naive)
                            dt_now = datetime.now(pytz.timezone('Asia/Seoul'))
                            
                            delta = dt_finish - dt_now
                            total_sec = delta.total_seconds()
                            
                            abs_hours = int(abs(total_sec) // 3600)
                            abs_minutes = int((abs(total_sec) % 3600) // 60)
                            
                            if total_sec >= 0:
                                date_finish_info = f"{abs_hours}시간 {abs_minutes}분"
                            else:
                                date_finish_info = f"{abs_hours}시간 {abs_minutes}분"
                            
                            if status != "충전중":
                                date_finish_info += " 전 종료"
                        except ValueError:
                            date_finish_info = ""
                    
                    chargers_info.append({
                        "type": charger_type,
                        "status": status + "\n" if status else "",
                        "dateFinish": date_finish,
                        "dateFinishInfo": date_finish_info
                    })
        
        # total_chargers
        total_chargers = len(chargers_info)
        
        # used_chargers: status가 "충전가능"이 아닌 것
        used_chargers = sum(1 for c in chargers_info if "가능" not in c["status"])
        
        # remaining_chargers
        remaining_chargers = total_chargers - used_chargers
        
        # printString
        print_string = ""
        for i, c in enumerate(chargers_info, 1):
            print_string += f"{i}. {c['status']}({c['dateFinishInfo']}) / {c['type']}\n\n"
        print_string = print_string.strip()
        
        # total_time
        total_time = f"{time.time() - start_time:.2f} seconds"
        
        return {
            "title": title,
            "company_name": company_name,
            "total_chargers": total_chargers,
            "used_chargers": used_chargers,
            "remaining_chargers": remaining_chargers,
            "address": address,
            "chargers_info": chargers_info,
            "msg": "SUCCESS",
            "printString": print_string,
            "total_time": total_time
        }
    finally:
        await page.close()
        await browser.close()  # 브라우저 완전 종료

@app.route('/get_ev_status', methods=['GET'])
def get_ev_status():
    sid = request.args.get('sid')
    if not sid:
        return jsonify({"msg": "FAIL: sid required"})
    
    loop = asyncio.new_event_loop()
    asyncio.set_event_loop(loop)
    result = loop.run_until_complete(scrape_ev_status(sid))
    return jsonify(result)

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)