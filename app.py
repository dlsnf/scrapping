#!/usr/bin/env python3
import asyncio, time, uuid
from fastapi import FastAPI, HTTPException, Query, Request
import script

app = FastAPI()

# 즉시 액세스 로그(요청이 들어왔는지 판단)
@app.middleware("http")
async def access_log_immediate(request: Request, call_next):
    rid = f"{int(time.time())%100000}-{uuid.uuid4().hex[:6]}"
    t0 = time.time()
    print(f'[AL {time.strftime("%Y-%m-%d %H:%M:%S")}] [RID {rid}] start {request.method} {request.url.path}?{request.query_params}', flush=True)
    try:
        resp = await call_next(request)
        return resp
    except Exception as e:
        print(f'[AL {time.strftime("%Y-%m-%d %H:%M:%S")}] [RID {rid}] EXC {type(e).__name__}: {e}', flush=True)
        raise
    finally:
        dt = time.time() - t0
        try:
            sc = resp.status_code  # type: ignore
        except Exception:
            sc = -1
        print(f'[AL {time.strftime("%Y-%m-%d %H:%M:%S")}] [RID {rid}] end status={sc} dt={dt:.3f}s', flush=True)

@app.get("/healthz")
async def healthz():
    return {"ok": True}

@app.on_event("startup")
async def on_startup():
    # 초기 웜업 없음(요청 때 lazy launch)
    pass

@app.on_event("shutdown")
async def on_shutdown():
    await script.shutdown()

@app.get("/info")
async def get_info(
    sid: str,
    timeout_sec: int = Query(9, ge=3, le=30),
    log: bool = False,
):
    if not sid:
        raise HTTPException(status_code=400, detail="sid 파라미터 필요")

    script.log_enabled = bool(log)

    try:
        # 최상단 하드 타임아웃(php 10초보다 약간 짧게)
        return await asyncio.wait_for(
            script.scrape_data(sid, timeout_sec=timeout_sec),
            timeout=timeout_sec + 0.5,
        )
    except asyncio.TimeoutError:
        raise HTTPException(status_code=504, detail="요청 타임아웃")
    except script.OverCapacityError as e:
        raise HTTPException(status_code=503, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
