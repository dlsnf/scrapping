FROM python:3.7.12-slim-buster

# 아카이브 저장소로 sources.list 수정 (Buster EOL 대응)
RUN sed -i 's|http://deb.debian.org/debian|http://archive.debian.org/debian|g' /etc/apt/sources.list \
    && sed -i 's|http://security.debian.org/debian-security|http://archive.debian.org/debian-security|g' /etc/apt/sources.list \
    && sed -i '/buster-updates/d' /etc/apt/sources.list \
    && echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/10no-check-valid-until

# 시스템 의존성 설치
RUN apt-get update --allow-releaseinfo-change && apt-get install -y \
    wget gnupg \
    && wget -q -O - https://dl-ssl.google.com/linux/linux_signing_key.pub | apt-key add - \
    && sh -c 'echo "deb [arch=amd64] http://dl.google.com/linux/chrome/deb/ stable main" >> /etc/apt/sources.list.d/google.list' \
    && apt-get update --allow-releaseinfo-change \
    && apt-get install -y google-chrome-stable \
    && rm -rf /var/lib/apt/lists/*

# Python 패키지 설치 (pip 업그레이드)
RUN pip install --upgrade pip

# Pyppeteer 설치 (Chromium 다운로드 스킵)
ENV PYPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
RUN pip install pyppeteer==0.2.6

# Flask 및 의존성 설치
RUN pip install flask==1.1.4

# MarkupSafe 다운그레이드 (Jinja2 호환)
RUN pip install markupsafe==2.0.1

# 기타 패키지
RUN pip install beautifulsoup4==4.9.3
RUN pip install pytz==2021.1

# 작업 디렉토리
WORKDIR /app

# 소스 복사 (script.py로 변경)
COPY script.py .

# 포트 노출
EXPOSE 8000

# 서버 실행 (script.py로 변경)
CMD ["python", "script.py"]