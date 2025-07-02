FROM python:3.7-slim-buster

WORKDIR /app

# 1) 시스템 패키지 설치
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      chromium \
      chromium-driver \
      libx11-6 libnss3 libatk1.0-0 libatk-bridge2.0-0 libpango-1.0-0 libgtk-3-0 libgbm1 \
      libxml2-dev libxslt1-dev zlib1g-dev gcc g++ make && \
    rm -rf /var/lib/apt/lists/*

# 2) pip 설치 확인 및 Python 라이브러리 설치
RUN python3 -m ensurepip --upgrade && \
    python3 -m pip install --no-cache-dir --upgrade pip && \
    python3 -m pip install --no-cache-dir pyppeteer lxml flask && \
    python3 -m pip list | grep -E 'pyppeteer|lxml|flask'

# 3) Chromium 경로 설정
ENV PYPPETEER_EXECUTABLE_PATH=/usr/lib/chromium/chromium

# 4) 스크립트 복사
COPY script.py .

# (4) 컨테이너 유지
CMD ["tail", "-f", "/dev/null"]