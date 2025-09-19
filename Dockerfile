FROM python:3.7-slim-buster
WORKDIR /app

# buster EOL 미러
RUN sed -i 's|deb.debian.org|archive.debian.org|g' /etc/apt/sources.list \
 && sed -i '/deb-src/d' /etc/apt/sources.list

# 최소 시스템 패키지 + 크로미움
RUN apt-get update && apt-get install -y --no-install-recommends \
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
      libx11-6 libnss3 libatk1.0-0 libatk-bridge2.0-0 \
      libpango-1.0-0 libgtk-3-0 libgbm1 \
 && rm -rf /var/lib/apt/lists/*

# 파이썬 패키지 (서버 프레임워크 없음)
RUN python3 -m ensurepip --upgrade && \
    python3 -m pip install --no-cache-dir --upgrade pip && \
    python3 -m pip install --no-cache-dir pyppeteer lxml pytz

ENV CHROME_BIN=/usr/bin/chromium
COPY script.py .

# 컨테이너는 가볍게 대기 (php에서 docker exec로 script.py 호출)
CMD ["bash","-lc","exec tail -f /dev/null"]
