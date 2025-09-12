# ===== Base =====
FROM python:3.7-slim-buster
WORKDIR /app
ENV DEBIAN_FRONTEND=noninteractive
ENV PYTHONUNBUFFERED=1

# ===== APT sources: buster 아카이브로 고정 + 만료 검사 비활성 =====
RUN sed -i 's|deb.debian.org|archive.debian.org|g' /etc/apt/sources.list \
 && sed -i 's|security.debian.org|archive.debian.org|g' /etc/apt/sources.list \
 && sed -i '/deb-src/d' /etc/apt/sources.list \
 && printf 'Acquire::Check-Valid-Until "false";\nAcquire::AllowInsecureRepositories "true";\n' > /etc/apt/apt.conf.d/99no-check-valid-until

# ===== 시스템 패키지 (Chromium + 필수 런타임만) =====
RUN apt-get update && apt-get install -y --no-install-recommends \
      ca-certificates \
      xdg-utils \
      chromium \
      libasound2 \
      libx11-6 libnss3 \
      libatk1.0-0 libatk-bridge2.0-0 \
      libxcomposite1 libxcursor1 libxdamage1 libxrandr2 libxfixes3 \
      libxss1 libxtst6 libxshmfence1 \
      libpango-1.0-0 libgtk-3-0 libgbm1 \
      fonts-liberation \
      gcc g++ make \
      libxml2-dev libxslt1-dev zlib1g-dev \
  && rm -rf /var/lib/apt/lists/*

# ===== Python 패키지 (Py3.7 호환 버전 고정) =====
RUN python3 -m pip install --no-cache-dir --upgrade "pip<24" "setuptools<70" wheel \
  && python3 -m pip install --no-cache-dir \
       "fastapi==0.95.2" \
       "uvicorn==0.22.0" \
       "pyppeteer==1.0.2" \
       "lxml<5" \
       "pytz" \
       "requests==2.31.0"

# ===== 앱 파일 복사 =====
COPY app.py .
COPY script.py .

# ===== 런타임 환경변수 (1GB 권장값) =====
ENV CHROME_BIN=/usr/bin/chromium
ENV MAX_CONCURRENCY=2
ENV QUEUE_TIMEOUT=3
ENV NAV_TIMEOUT_MS=15000
ENV RETRY_COUNT=2
ENV HTTP_CONN_TIMEOUT=2.5
ENV HTTP_READ_TIMEOUT=6.0

# ===== 포트/실행 =====
EXPOSE 5000
# 워커는 1개 (Chromium 중복 기동 방지). 동시성은 내부 세마포어로 제어.
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "5000", "--workers", "1"]
