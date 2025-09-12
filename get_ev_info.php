<?php
// 파일: /scrapping/get_ev_info.php
// 목적: 외부 호출 엔드포인트 (ev 전용 DB 사용 + 접근 체크 include)

require_once __DIR__ . '/db_ev.php';
require_once __DIR__ . '/check_access_ev.php';

/* -----------------------
 * JSON 한글 깨짐 방지(php5.3)
 * ----------------------- */
if (!function_exists('json_encode_utf8')) {
    function json_encode_utf8($data) {
        $json = json_encode($data);
        if ($json === false) return 'null';
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
$sid      = isset($_GET['sid']) ? trim($_GET['sid']) : '';
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
 * 접근 체크
 * ----------------------- */
$chk = check_access_inline_ev($category, $sid, $key, 'text');
if ($chk !== 'access') {
    json_out(array(
        'error'     => $chk,
        'http_code' => 403
    ));
}

/* -----------------------
 * 내부 FastAPI 호출
 * - PHP 타임아웃을 약간 늘리고(10초),
 * - FastAPI에 timeout_sec=9로 상한을 명시해 서로 맞춥니다.
 * - IPv4 강제/재시도/에러 메시지 포함
 * ----------------------- */
$base = 'http://127.0.0.1:5000/info?sid=' . urlencode($sid);
$base .= '&timeout_sec=9';       // FastAPI 전체 타임아웃 9초로 제한
if ($debug) $base .= '&log=1';

$headers = array(
    'Accept: application/json',
    'Connection: close',
);

$attempts = 2;                  // 간단 재시도 2회
$lastErr = '';
$lastHttp = 0;
$response = false;

for ($i = 0; $i < $attempts; $i++) {
    $url = $base; // 필요 시 시도마다 파라미터 추가 가능

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // 타임아웃: 연결 2초, 전체 10초 (FastAPI 9초와 정합)
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    // IPv6 이슈 회피 (CentOS 6에서도 동작)
    if (defined('CURL_IPRESOLVE_V4')) {
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    // 재시도 시 새 연결 강제
    if ($i > 0) {
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, true);
    }

    $response = curl_exec($ch);
    $lastHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $lastHttp === 200) {
        // 성공
        header('Content-Type: application/json; charset=utf-8');
        echo $response;
        exit;
    }

    // 실패 → 에러기록 후 짧게 대기
    $lastErr = $curlErr ? $curlErr : ('HTTP '.$lastHttp);
    usleep(200 * 1000); // 200ms
}

// 최종 실패 응답 (디버깅 보조 정보 포함)
json_out(array(
    'error'      => '데이터 조회 실패',
    'http_code'  => $lastHttp,          // 0이면 타임아웃/접속 실패
    'curl_error' => $lastErr,           // 원인 파악용
    'endpoint'   => $base               // 호출했던 주소 확인용
));
