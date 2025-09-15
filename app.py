#!/usr/bin/env python3
from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import PlainTextResponse, JSONResponse
import asyncio
import script

app = FastAPI()

# 항상 Connection: close (CentOS6 + 구형 cURL 호환)
@app.middleware("http")
async def force_conn_close(request: Request, call_next):
    resp = await call_next(request)
    resp.headers["Connection"] = "close"
    return resp

@app.on_event("startup")
async def on_startup():
    # 브라우저 웜업은 비동기로(요청 블로킹 방지, 실패해도 쿨다운 미적용)
    asyncio.create_task(script.background_warmup())

@app.on_event("shutdown")
async def on_shutdown():
    await script.shutdown()

# 루트/상태/크롤러 억제
@app.get("/", include_in_schema=False)
async def root():
    return JSONResponse({"status": "ok", "service": "pyppeteer-service"})

@app.get("/health", include_in_schema=False)
async def health():
    return {"status": "ok", "service": "pyppeteer-service"}

@app.get("/robots.txt", include_in_schema=False)
async def robots():
    return PlainTextResponse("User-agent: *\nDisallow: /\n", media_type="text/plain")

# 메인 API
@app.get("/info")
async def get_info(sid: str, log: bool = False, timeout_sec: int = 9):
    if not sid:
        raise HTTPException(status_code=400, detail="sid 파라미터 필요")
    script.log_enabled = bool(log)
    try:
        return await asyncio.wait_for(
            script.scrape_data(sid, timeout_sec=timeout_sec),
            timeout=timeout_sec
        )
    except script.OverCapacityError as e:
        raise HTTPException(status_code=503, detail=str(e))
    except asyncio.TimeoutError:
        raise HTTPException(status_code=504, detail="요청 타임아웃")
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
