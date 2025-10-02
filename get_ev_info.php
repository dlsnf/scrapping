<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/db_ev.php';
require_once __DIR__ . '/check_access_ev.php';

// send_status 함수 정의 (HTTP status 코드 설정, PHP 5.3.3 호환)
function send_status($code) {
    header("HTTP/1.1 $code");
}

// json_encode_utf8 함수 정의 (한글 이스케이프 제거, PHP 5.3.3 호환)
function json_encode_utf8($data) {
    $json = json_encode($data);
    // \uXXXX를 실제 UTF-8 문자로 변환
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

// 사용자가 지정한 검증 로직
if ($sid === '' || $category === '' || $key === '') {
    json_out(array('error'=>'필수 파라미터 누락: sid, category, key','http_code'=>400),400);
}
$chk = check_access_inline_ev($category, $sid, $key, 'text');
if ($chk !== 'access') {
    json_out(array('error'=>$chk,'http_code'=>403),403);
}

// Docker 컨테이너 호출
$scrape_url = "http://localhost:8000/scrape?sid=" . urlencode($sid);
if ($log == '1') {
    $scrape_url .= "&log=1";
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $scrape_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);  // 타임아웃 증가
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code != 200) {
    json_out(array('msg' => 'ERROR', 'error' => 'Scrape failed'), $http_code);
}

// 정상 반환: 헤더 설정 후 JSON 출력 (charset=utf-8 포함)
header('Content-Type: application/json; charset=utf-8');
$data = json_decode($response, true);
if ($data) {
    echo json_encode_utf8($data);  // 한글 처리된 JSON
} else {
    json_out(array('msg' => 'ERROR', 'error' => 'Invalid JSON response'), 500);
}
?>