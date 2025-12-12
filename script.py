import asyncio
import time
import json
from quart import Quart, request, jsonify
from playwright.async_api import async_playwright
from bs4 import BeautifulSoup
import pytz
from datetime import datetime
import logging
import nest_asyncio

# 로그 설정
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

app = Quart(__name__)

# nest_asyncio 적용
nest_asyncio.apply()

# 글로벌 브라우저/컨텍스트 변수
browser = None
context = None

@app.before_serving
async def init_browser():
    global browser, context
    logging.info("브라우저 초기화 시작 (Playwright + Firefox, 워커별)")
    start_time = time.time()
    
    # Playwright 실행
    playwright = await async_playwright().start()
    browser = await playwright.firefox.launch(
        headless=True,
        args=['--no-sandbox']
    )
    
    # 컨텍스트 생성 (리소스 최적화)
    context = await browser.new_context(
        ignore_https_errors=True
    )
    
    logging.info(f"브라우저 초기화 완료: 소요 시간={time.time() - start_time:.2f} 초")

@app.after_serving
async def shutdown_browser():
    global browser, context
    if context:
        await context.close()
        logging.info("컨텍스트 종료 완료")
    if browser:
        await browser.close()
        logging.info("브라우저 종료 완료")

async def scrape_ev_status(sid):
    global context
    start_time = time.time()
    logging.info(f"스크래핑 시작: sid={sid}, start_time={start_time}")
    
    if context is None:
        logging.error("컨텍스트가 초기화되지 않았습니다.")
        return {"msg": "FAIL: Context not initialized"}
    
    # 재시도 로직: 최대 2회
    for attempt in range(2):
        try:
            page_start = time.time()
            page = await context.new_page()
            page_end = time.time()
            logging.info(f"새 페이지 생성 완료: 소요 시간={page_end - page_start:.2f} 초")
            
            try:
                # Playwright에서 리소스 차단 (이미지, 스타일시트, 폰트)
                async def handle_route(route):
                    if route.request.resource_type in ['image', 'stylesheet', 'font']:
                        await route.abort()
                    else:
                        await route.continue_()
                
                await page.route('**/*', handle_route)
                
                goto_start = time.time()
                url = f"https://www.ev.or.kr/nportal/monitor/evMapInfo.do?sid={sid}&pFlag=Y"
                await page.goto(url, wait_until='domcontentloaded', timeout=30000)
                goto_end = time.time()
                logging.info(f"페이지 로드 완료 (goto): 소요 시간={goto_end - goto_start:.2f} 초")
                
                wait_start = time.time()
                title = None
                for wait_attempt in range(10):
                    loop_start = time.time()
                    content = await page.content()
                    soup = BeautifulSoup(content, 'html.parser')
                    title_input = soup.find('input', id='stat_nm')
                    if title_input and title_input.get('value'):
                        title = title_input.get('value')
                        logging.info(f"Title 발견: 시도 {wait_attempt+1}회, 소요 시간={time.time() - loop_start:.2f} 초")
                        break
                    logging.info(f"Title 미발견: 시도 {wait_attempt+1}회, 소요 시간={time.time() - loop_start:.2f} 초, 0.5초 대기")
                    await asyncio.sleep(0.5)
                wait_end = time.time()
                logging.info(f"로딩 대기 루프 완료: 총 소요 시간={wait_end - wait_start:.2f} 초")
                
                if not title:
                    logging.warning("Title 미발견: 10회 시도 후 실패")
                    return {"msg": "FAIL: Title not found after 10 attempts"}
                
                parse_start = time.time()
                soup = BeautifulSoup(await page.content(), 'html.parser')
                
                title = soup.find('input', id='stat_nm').get('value')
                
                company_elem = soup.find(class_='org_me')
                company_name = company_elem.find('span').text.strip() if company_elem and company_elem.find('span') else ""
                
                address_table = soup.find(class_='table03')
                address = address_table.find('tbody').find('tr').find('td').text.strip() if address_table else ""
                
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
                            
                            if "충전중" in raw_status:
                                status = "충전중"
                            elif "충전가능" in raw_status:
                                status = "충전가능"
                            else:
                                status = raw_status
                            
                            date_finish_elem = tds[2].find(class_='rdate')
                            date_finish = date_finish_elem.text.strip() if date_finish_elem else ""
                            
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
                
                total_chargers = len(chargers_info)
                
                used_chargers = sum(1 for c in chargers_info if "가능" not in c["status"])
                
                remaining_chargers = total_chargers - used_chargers
                
                print_string = ""
                for i, c in enumerate(chargers_info, 1):
                    print_string += f"{i}. {c['status']}({c['dateFinishInfo']}) / {c['type']}\n\n"
                print_string = print_string.strip()
                
                parse_end = time.time()
                logging.info(f"HTML 전체 파싱 완료: 소요 시간={parse_end - parse_start:.2f} 초")
                
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
                logging.info("페이지 종료 완료")
            
            break
        
        except Exception as e:
            logging.warning(f"오류 발생 (시도 {attempt+1}회): {str(e)}")
            if attempt < 1:
                await asyncio.sleep(1)
            else:
                return {"msg": f"FAIL: Error after retry - {str(e)}"}
    
    logging.info("페이지는 종료됨 (새 페이지로 재사용 준비)")

@app.route('/get_ev_status', methods=['GET'])
async def get_ev_status():
    sid = request.args.get('sid')
    if not sid:
        return jsonify({"msg": "FAIL: sid required"})
    
    result = await scrape_ev_status(sid)
    return jsonify(result)

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)