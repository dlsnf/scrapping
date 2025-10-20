import asyncio
import time
import json
from flask import Flask, request, jsonify
from pyppeteer import launch
from bs4 import BeautifulSoup
import pytz
from datetime import datetime
import logging

# 로그 설정 (INFO 레벨, 시간 포함)
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

app = Flask(__name__)

async def scrape_ev_status(sid):
    start_time = time.time()
    logging.info(f"스크래핑 시작: sid={sid}, start_time={start_time}")
    
    # 브라우저 launch 시간 측정 (최적 args 추가)
    launch_start = time.time()
    browser = await launch(
        headless=True,
        args=['--no-sandbox', '--disable-setuid-sandbox', '--disable-gpu', '--disable-software-rasterizer', '--single-process'],
        handleSIGINT=False,
        handleSIGTERM=False,
        handleSIGHUP=False
    )
    launch_end = time.time()
    logging.info(f"브라우저 launch 완료: 소요 시간={launch_end - launch_start:.2f} 초")
    
    # 페이지 생성 시간 측정
    page_start = time.time()
    page = await browser.newPage()
    page_end = time.time()
    logging.info(f"새 페이지 생성 완료: 소요 시간={page_end - page_start:.2f} 초")
    
    try:
        # 리소스 블록 (이미지, CSS, 폰트 차단)
        await page.setRequestInterception(True)
        page.on('request', lambda req: asyncio.ensure_future(intercept_request(req)))

        async def intercept_request(req):
            if req.resourceType in ['image', 'stylesheet', 'font']:  # () 제거: resourceType은 속성
                await req.abort()
            else:
                await req.continue_()
        
        # 페이지 로드 시간 측정 (waitUntil 최적화, timeout 증가)
        goto_start = time.time()
        url = f"https://www.ev.or.kr/nportal/monitor/evMapInfo.do?sid={sid}&pFlag=Y"
        await page.goto(url, {'waitUntil': 'domcontentloaded', 'timeout': 90000})  # HTML 로드 후 중지, 90초 타임아웃
        goto_end = time.time()
        logging.info(f"페이지 로드 완료 (goto): 소요 시간={goto_end - goto_start:.2f} 초")
        
        # 로딩 대기 루프 시간 측정
        wait_start = time.time()
        title = None
        for attempt in range(10):
            loop_start = time.time()
            content = await page.content()
            soup = BeautifulSoup(content, 'html.parser')
            title_input = soup.find('input', id='stat_nm')
            if title_input and title_input.get('value'):
                title = title_input.get('value')
                logging.info(f"Title 발견: 시도 {attempt+1}회, 소요 시간={time.time() - loop_start:.2f} 초")
                break
            logging.info(f"Title 미발견: 시도 {attempt+1}회, 소요 시간={time.time() - loop_start:.2f} 초, 0.5초 대기")
            await asyncio.sleep(0.5)
        wait_end = time.time()
        logging.info(f"로딩 대기 루프 완료: 총 소요 시간={wait_end - wait_start:.2f} 초")
        
        if not title:
            logging.warning("Title 미발견: 10회 시도 후 실패")
            return {"msg": "FAIL: Title not found after 10 attempts"}
        
        # HTML 파싱 시간 측정
        parse_start = time.time()
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
                row_start = time.time()
                tds = row.find_all('td')
                if len(tds) >= 3:
                    charger_type = tds[0].text.strip()
                    status_elem = tds[2].find(class_='state')
                    raw_status = status_elem.text.strip() if status_elem else ""
                    
                    # status 조건 처리: "충전중" 포함 시 "충전중", "충전가능" 포함 시 "충전가능"
                    if "충전중" in raw_status:
                        status = "충전중"
                    elif "충전가능" in raw_status:
                        status = "충전가능"
                    else:
                        status = raw_status  # 다른 경우 원본 유지
                    
                    date_finish_elem = tds[2].find(class_='rdate')
                    date_finish = date_finish_elem.text.strip() if date_finish_elem else ""
                    
                    # dateFinishInfo 계산
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
                            
                            time_str = ""
                            if abs_hours > 0:
                                time_str += f"{abs_hours}시간 "
                            time_str += f"{abs_minutes}분"
                            
                            date_finish_info = time_str
                            
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
                logging.info(f"충전기 정보 파싱: 행 처리 소요 시간={time.time() - row_start:.2f} 초")
        
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
        
        parse_end = time.time()
        logging.info(f"HTML 전체 파싱 완료: 소요 시간={parse_end - parse_start:.2f} 초")
        
        # total_time
        total_time = f"{time.time() - start_time:.2f} seconds"
        logging.info(f"스크래핑 전체 완료: 총 소요 시간={total_time}")
        
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
        await browser.close()
        logging.info("브라우저 및 페이지 종료 완료")

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