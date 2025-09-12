#!/usr/bin/env python3
from fastapi import FastAPI, HTTPException, Request
import asyncio
import script

app = FastAPI()

# 구형 클라이언트 호환: 항상 연결 종료
@app.middleware("http")
async def force_conn_close(request: Request, call_next):
    resp = await call_next(request)
    resp.headers["Connection"] = "close"
    return resp

@app.on_event("startup")
async def on_startup():
    # 브라우저 웜업은 "백그라운드"로만 (요청을 붙잡지 않음)
    asyncio.create_task(script.background_warmup())

@app.on_event("shutdown")
async def on_shutdown():
    await script.shutdown()

@app.get("/info")
async def get_info(sid: str, log: bool = False, timeout_sec: int = 9):
    if not sid:
        raise HTTPException(status_code=400, detail="sid 파라미터 필요")

    script.log_enabled = bool(log)
    try:
        return await asyncio.wait_for(script.scrape_data(sid, timeout_sec=timeout_sec), timeout=timeout_sec)
    except script.OverCapacityError as e:
        raise HTTPException(status_code=503, detail=str(e))
    except asyncio.TimeoutError:
        raise HTTPException(status_code=504, detail="요청 타임아웃")
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
