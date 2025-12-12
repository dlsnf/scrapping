<?php
/* 환경설정 */
date_default_timezone_set('Asia/Seoul');

define('DB_HOST', 'localhost');
define('DB_USER', 'scrapping_admin');   // 요청하신 계정
define('DB_PASS', '');      // 실제 비밀번호 입력
define('DB_NAME', 'scrapping');

define('APP_NAME', 'License Admin');
define('SESSION_NAME', 'licadmin_sess');
define('CSRF_TOKEN_NAME', 'csrf_token');

/* PHP 5.3에서 에러 표시(운영시 off 권장) */
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_NOTICE);
