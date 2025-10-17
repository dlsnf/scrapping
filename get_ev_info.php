<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/db_ev.php';
require_once __DIR__ . '/check_access_ev.php';

# send_status 함수 정의 (HTTP status 코드 설정, PHP 5.3.3 호환)
function send_status($code) {
    header("HTTP/1.1 $code");
}

# json_encode_utf8 함수 정의 (한글 이스케이프 제거, PHP 5.3.3 호환)
function json_encode_utf8($data) {
    $json = json_encode($data);
    # \uXXXX를 실제 UTF-8 문자로 변환
    return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($match) {
        return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
    }, $json);
}

function json_out($arr, $code=200) {
    send_status($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode_utf8($arr);
    exit;
}

$category = isset($_GET['category']) ? $_GET['category'] : '';
$sid = isset($_GET['sid']) ? $_GET['sid'] : '';
$key = isset($_GET['key']) ? $_GET['key'] : '';
$log = isset($_GET['log']) ? $_GET['log'] : '0';

# 사용자가 지정한 검증 로직
if ($sid === '' || $category === '' || $key === '') {
    json_out(array('error'=>'필수 파라미터 누락: sid, category, key','http_code'=>400),400);
}
$chk = check_access_inline_ev($category, $sid, $key, 'text');
if ($chk !== 'access') {
    json_out(array('error'=>$chk,'http_code'=>403),403);
}

# Docker 컨테이너 호출
$api_url = "http://localhost:5000/get_ev_status?sid=" . urlencode($sid);
$ch = curl_init($api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 타임아웃 30초 (필요 시 조정)
$response = curl_exec($ch);
$curl_error = curl_error($ch);
curl_close($ch);
    if ($response === false || !empty($curl_error)) {
    json_out(array('error' => 'API 호출 실패: ' . $curl_error, 'http_code' => 500), 500);
}

// $response를 배열로 디코드
$data = json_decode($response, true);

// 로그 저장 (log=1일 경우, 디코드된 데이터로 저장하여 한글 유지)
if ($log === '1') {
    $log_filename = 'ev_log_' . date('YmdHis') . '.json';
    file_put_contents(__DIR__ . '/' . $log_filename, json_encode_utf8($data));
}

// 응답 출력 (디코드 후 재인코딩하여 한글 이스케이프 제거)
header('Content-Type: application/json; charset=utf-8');
echo json_encode_utf8($data);

?>