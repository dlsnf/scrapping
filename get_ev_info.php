<?php
// 파일: /var/www/html/scrapping/get_ev_info.php

// 1) 파라미터
$sid      = isset($_GET['sid']) ? trim($_GET['sid']) : '';           // product code
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$key      = isset($_GET['key']) ? strtoupper(trim($_GET['key'])) : '';

if ($sid === '' || $category === '' || $key === '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'error'     => '필수 파라미터 누락: sid, category, key',
        'http_code' => 400
    ));
    exit;
}

// 2) 디버그
$debug = false;
if ((isset($_GET['debug']) && $_GET['debug'] === '1') 
    || (isset($_SERVER['HTTP_X_DEBUG']) && $_SERVER['HTTP_X_DEBUG'] === '1')) {
    $debug = true;
}

// 3) include로 접근 체크
require_once __DIR__ . '/check_access.php'; // check_access_inline() 사용

$check_resp = check_access_inline($category, $sid, $key, 'text');
if (trim($check_resp) !== 'access') {
    // 불가: 그대로 표출
    header('Content-Type: text/plain; charset=utf-8');
    echo $check_resp;
    exit;
}

// 4) 허용: FastAPI 호출
$api_url = 'http://127.0.0.1:5000/info?sid=' . urlencode($sid);
if ($debug) $api_url .= '&log=1';

$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

header('Content-Type: application/json; charset=utf-8');
if ($response === false || $httpCode !== 200) {
    echo json_encode(array(
        'error'     => 'Failed to fetch data',
        'http_code' => $httpCode,
        'curl_error'=> $curlErr
    ));
    exit;
}

echo $response;
