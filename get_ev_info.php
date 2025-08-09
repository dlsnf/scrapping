<?php
// 파일: /var/www/html/scrapping/get_ev_info.php

// 한글 JSON이 \uXXXX로 이스케이프되지 않도록 우회 처리(PHP 5.3 전용)
function json_encode_utf8($data) {
    $json = json_encode($data); // PHP 5.3은 한글을 \uXXXX로 내보냄
    // \uXXXX -> UTF-8 변환 (BMP 범위만 처리: 한글은 BMP 내)
    $json = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($m) {
        $code = hexdec($m[1]);
        if ($code < 0x80) {
            return chr($code);
        } elseif ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        } else {
            return chr(0xE0 | ($code >> 12))
                 . chr(0x80 | (($code >> 6) & 0x3F))
                 . chr(0x80 | ($code & 0x3F));
        }
    }, $json);
    return $json;
}

// 1) 필수 파라미터 수집
$sid      = isset($_GET['sid']) ? trim($_GET['sid']) : '';                 // product code
$category = isset($_GET['category']) ? trim($_GET['category']) : '';       // category name
$key      = isset($_GET['key']) ? strtoupper(trim($_GET['key'])) : '';     // license key

if ($sid === '' || $category === '' || $key === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode_utf8(array(
        'error'     => '필수 파라미터 누락: sid, category, key',
        'http_code' => 400
    ));
    exit;
}

// 2) 디버그 플래그 (?debug=1 또는 X-Debug:1 헤더)
$debug = false;
if ((isset($_GET['debug']) && $_GET['debug'] === '1')
    || (isset($_SERVER['HTTP_X_DEBUG']) && $_SERVER['HTTP_X_DEBUG'] === '1')) {
    $debug = true;
}

// 3) 접근 체크: include 방식으로 check_access_inline 호출
require_once __DIR__ . '/check_access.php'; // check_access_inline() 사용

$check_resp = check_access_inline($category, $sid, $key, 'text');
// 허용 문구는 'access' (요청하신 대로 변경된 버전 기준)
if (trim($check_resp) !== 'access') {
    // 불가: 그대로 표출 (plain text)
    header('Content-Type: text/plain; charset=utf-8');
    echo $check_resp;
    exit;
}

// 4) 허용 시 FastAPI 호출
$api_url = 'http://127.0.0.1:5000/info?sid=' . urlencode($sid);
if ($debug) {
    $api_url .= '&log=1';
}

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// 5) FastAPI 응답 처리 (그대로 JSON 패스스루)
header('Content-Type: application/json; charset=utf-8');
if ($response === false || $httpCode !== 200) {
    echo json_encode_utf8(array(
        'error'     => '데이터 조회 실패(FastAPI)',
        'http_code' => $httpCode,
        'curl_error'=> $curlErr
    ));
    exit;
}

echo $response;
