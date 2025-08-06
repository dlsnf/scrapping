FROM python:3.7-slim-buster
WORKDIR /app

# 0) Buster archive 변경
RUN sed -i 's|deb.debian.org|archive.debian.org|g' /etc/apt/sources.list \
 && sed -i '/deb-src/d' /etc/apt/sources.list

# 1) 시스템 패키지 (Chromium 실행에 필요한 deps 포함)
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      ca-certificates \
      fonts-liberation \
      libasound2 \
      libatk-adaptor \
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
      libxml2-dev libxslt1-dev zlib1g-dev gcc g++ make && \
    rm -rf /var/lib/apt/lists/*

# 2) Python 라이브러리 설치
RUN python3 -m ensurepip --upgrade && \
    python3 -m pip install --no-cache-dir --upgrade pip && \
    python3 -m pip install --no-cache-dir \
      pyppeteer lxml pytz fastapi uvicorn

# 3) Chromium 경로 설정 (실제 바이너리 경로 기준)
ENV PYPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

# 4) 애플리케이션 복사
COPY script.py .
COPY app.py .

# 5) HTTP 서버 실행
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "5000"]
