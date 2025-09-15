FROM python:3.7-slim-buster
WORKDIR /app

# 고정 미러(구 버전 이미지용)
RUN sed -i 's|deb.debian.org|archive.debian.org|g' /etc/apt/sources.list \
 && sed -i '/deb-src/d' /etc/apt/sources.list

# Chromium + 최소 의존 패키지 (안정성 보강 포함)
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      ca-certificates \
      fonts-liberation \
      libasound2 \
      libdbus-glib-1-2 \
      libxcomposite1 \
      libxcursor1 \
      libxdamage1 \
      libxrandr2 \
      libxss1 \
      libxtst6 \
      xdg-utils \
      chromium \
      chromium-driver \
      libx11-6 libnss3 libatk1.0-0 libatk-bridge2.0-0 \
      libpango-1.0-0 libgtk-3-0 libgbm1 \
      libglib2.0-0 libxshmfence1 \
      libxml2-dev libxslt1-dev zlib1g-dev gcc g++ make && \
    rm -rf /var/lib/apt/lists/*

# 파이썬 라이브러리
RUN python3 -m ensurepip --upgrade && \
    python3 -m pip install --no-cache-dir --upgrade pip && \
    python3 -m pip install --no-cache-dir \
      fastapi uvicorn pyppeteer lxml pytz

# Chromium 경로
ENV PYPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

# 앱 소스
COPY app.py .
COPY script.py .

EXPOSE 5000
# 워커 1 + keep-alive 0 (구형 환경 호환)
CMD ["uvicorn","app:app","--host","0.0.0.0","--port","5000","--workers","1","--timeout-keep-alive","0"]
