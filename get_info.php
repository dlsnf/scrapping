<?php
// URL에서 sid 파라미터 받기
$sid = isset($_GET['sid']) ? trim($_GET['sid']) : '';
if (!$sid) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'msg'        => 'Error: sid 필요',
        'total_time' => '0.00 seconds'
    ));
    exit;
}

// 디버그 헤더 검사 (curl -H "X-Debug:1" ...)
$debug = false;
if (
    (isset($_GET['debug']) && $_GET['debug'] === '1') 
    || (isset($_SERVER['HTTP_X_DEBUG']) && $_SERVER['HTTP_X_DEBUG'] === '1')
) {
    $debug = true;
}

// FastAPI 마이크로서비스에 HTTP 요청 (로그 플래그 전달)
$url = 'http://127.0.0.1:5000/info?sid=' . urlencode($sid);
if ($debug) {
    // 터미널에서 curl -H "X-Debug:1" 이나 ?debug=1 로 호출할 때만 log=1
    $url .= '&log=1';
}

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json; charset=utf-8');
if ($response === false || $httpCode !== 200) {
    echo json_encode(array(
        'error'     => 'Failed to fetch data',
        'http_code' => $httpCode
    ));
    exit;
}

echo $response;
?>
