#!/usr/bin/env python3
from fastapi import FastAPI, HTTPException
import script

app = FastAPI()

@app.on_event("startup")
async def on_startup():
    await script.init_browser()

@app.on_event("shutdown")
async def on_shutdown():
    await script.shutdown()

@app.get("/info")
async def get_info(sid: str, log: bool = False):
    if not sid:
        raise HTTPException(status_code=400, detail="sid 파라미터 필요")

    # URL 파라미터로 받은 log 값을 script.log_enabled 에 전달
    script.log_enabled = bool(log)

    try:
        return await script.scrape_data(sid)
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
