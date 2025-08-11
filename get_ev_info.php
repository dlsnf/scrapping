<?php
// 파일: /scrapping/get_ev_info.php
// 목적: 외부 호출 엔드포인트 (ev 전용 DB 사용 + 접근 체크 include)
// 주의: PHP 5.3 호환용으로 JSON 한글이 \uXXXX로 안 나오게 후처리 헬퍼 포함

require_once __DIR__ . '/db_ev.php';          // ★ admin/db.php 대신 이 파일 사용
require_once __DIR__ . '/check_access_ev.php';// ★ ev 전용 접근체크

/* -----------------------
 * JSON 한글 깨짐 방지(php5.3)
 * ----------------------- */
if (!function_exists('json_encode_utf8')) {
    function json_encode_utf8($data) {
        $json = json_encode($data); // PHP 5.3: \uXXXX 로 나옴
        if ($json === false) return 'null';
        // \uXXXX → UTF-8 문자로 변환
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($m){
            $code = hexdec($m[1]);
            if ($code < 0x80) {
                return chr($code);
            } elseif ($code < 0x800) {
                return chr(0xC0 | ($code >> 6)) .
                       chr(0x80 | ($code & 0x3F));
            } else {
                return chr(0xE0 | ($code >> 12)) .
                       chr(0x80 | (($code >> 6) & 0x3F)) .
                       chr(0x80 | ($code & 0x3F));
            }
        }, $json);
    }
}

function json_out($arr) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode_utf8($arr);
    exit;
}

/* -----------------------
 * 파라미터
 * ----------------------- */
$sid      = isset($_GET['sid']) ? trim($_GET['sid']) : '';           // product code
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$key      = isset($_GET['key']) ? strtoupper(trim($_GET['key'])) : '';
$debug    = (
    (isset($_GET['debug']) && $_GET['debug']==='1') ||
    (isset($_SERVER['HTTP_X_DEBUG']) && $_SERVER['HTTP_X_DEBUG']==='1')
);

/* -----------------------
 * 필수 체크
 * ----------------------- */
if ($sid==='' || $category==='' || $key==='') {
    json_out(array(
        'error'     => '필수 파라미터 누락: sid, category, key',
        'http_code' => 400
    ));
}

/* -----------------------
 * 접근 체크 (include 방식, ev DB 사용)
 * check_access_inline_ev()는 'access' 또는 사유 문자열을 반환
 * ----------------------- */
$chk = check_access_inline_ev($category, $sid /* product */, $key, 'text');
if ($chk !== 'access') {
    json_out(array(
        'error'     => $chk,   // 예: '라이선스 없음', '카테고리 불일치', '만료됨', ...
        'http_code' => 403
    ));
}

/* -----------------------
 * 실제 업무 로직 (예: 내부 FastAPI 호출)
 * ----------------------- */
$url = 'http://127.0.0.1:5000/info?sid=' . urlencode($sid);
if ($debug) $url .= '&log=1';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    json_out(array(
        'error'     => '데이터 조회 실패',
        'http_code' => $httpCode
    ));
}

/* 내부 서비스가 이미 UTF-8 JSON을 돌려준다는 가정 하에 그대로 출력 */
header('Content-Type: application/json; charset=utf-8');
echo $response;
