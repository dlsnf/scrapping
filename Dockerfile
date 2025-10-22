# Python 3.7 기반 이미지 사용 (CentOS 6.10과 호환되도록 slim 버전)
FROM python:3.7-slim-buster

# 저장소 변경: archive.debian.org로 이동 (Buster EOL 대응)
RUN echo "deb http://archive.debian.org/debian buster main contrib non-free" > /etc/apt/sources.list && \
    echo "deb http://archive.debian.org/debian-security buster/updates main contrib non-free" >> /etc/apt/sources.list && \
    echo "deb http://archive.debian.org/debian buster-updates main contrib non-free" >> /etc/apt/sources.list && \
    echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until && \
    echo 'Acquire::Max-ValidTime 0;' > /etc/apt/apt.conf.d/99no-max-valid-time

# 필요한 시스템 패키지 설치 (Pyppeteer가 Chromium 필요)
RUN apt-get update && apt-get install -y \
    wget unzip curl gnupg \
    libnss3 libgconf-2-4 libxss1 libasound2 libatk1.0-0 libatk-bridge2.0-0 libcairo2 libcups2 libdbus-1-3 libexpat1 libfontconfig1 libgbm1 libgcc1 libgdk-pixbuf2.0-0 libglib2.0-0 libgtk-3-0 libnspr4 libpango-1.0-0 libpangocairo-1.0-0 libstdc++6 libx11-6 libx11-xcb1 libxcb1 libxcomposite1 libxcursor1 libxdamage1 libxext6 libxfixes3 libxi6 libxrandr2 libxrender1 libxtst6 xdg-utils \
    && rm -rf /var/lib/apt/lists/*

# 작업 디렉토리 설정
WORKDIR /app

# Python 라이브러리 설치
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Chromium 미리 다운로드 (빌드 타임에 실행)
RUN python -c "import pyppeteer; pyppeteer.chromium_downloader.download_chromium()"

# 스크립트 복사
COPY script.py .

# Flask 서버 실행 (포트 5000)
CMD ["python", "script.py"]