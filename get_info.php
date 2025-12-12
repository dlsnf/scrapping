<?php

/* ---------- 공통 유틸 ---------- */
/**
 * @return mixed
 */
function send_status($code) {
    $texts = array(
        200=>'OK', 204=>'No Content', 400=>'Bad Request', 403=>'Forbidden',
        404=>'Not Found', 500=>'Internal Server Error', 502=>'Bad Gateway',
        503=>'Service Unavailable', 504=>'Gateway Timeout'
    );
    $proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
    $text  = isset($texts[$code]) ? $texts[$code] : '';
    header($proto . ' ' . $code . ' ' . $text);
}

/**
 * @return mixed
 */
function json_out($arr, $code=200) {
    send_status($code);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($arr); // PHP 5.3: \uXXXX 유지 (클라이언트 파서 호환성↑)
    if ($json === false) $json = 'null';
    echo $json;
    exit;
}

// 멀티바이트 안전 substr (mbstring 없으면 best-effort)
/**
 * @return mixed
 */
function safe_substr($s, $len) {
    if (function_exists('mb_substr')) return mb_substr($s, 0, $len, 'UTF-8');
    return substr($s, 0, $len);
}

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



/* ---------- 환경변수/설정 ---------- */
$container = getenv('EV_CONTAINER') ?: 'pyppeteer-service';
$timeout_s = (int)(getenv('EV_TIMEOUT_SEC') ?: 25);
$compat200 = (getenv('EV_COMPAT_200') === '1'); // 레거시 소비자 200 유지용 완충 옵션

/* ---------- docker exec 명령 구성 ---------- */
$cmd = 'docker exec ' . escapeshellarg($container)
     . ' python3 /app/script.py'
     . ' --sid=' . escapeshellarg($sid)
     . ' --timeout=' . (int)$timeout_s;

if ($debug) $cmd .= ' --log=1'; // 개선: script.py가 --log 처리함

if (is_executable('/usr/bin/timeout')) {
    $cmd = '/usr/bin/timeout ' . (int)($timeout_s + 3) . 's ' . $cmd;
}

/* ---------- 실행 ---------- */
$descriptors = array(
    0 => array('pipe','r'),
    1 => array('pipe','w'),
    2 => array('pipe','w'),
);
$proc = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) {
    json_out(array('error'=>'실행 시작 실패'), 500);
}
fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);

$status = proc_close($proc);
$body   = trim($stdout !== '' ? $stdout : $stderr);

/* ---------- 결과 처리 ---------- */
header('X-Backend-Exit: ' . $status);

// JSON이면 내용 분석 후 상태코드 매핑
if ($body !== '' && ($body[0] === '{' || $body[0] === '[')) {
    $decoded = json_decode($body, true);
    if (is_array($decoded) && isset($decoded['msg'])) {
        $msg = $decoded['msg'];
        header('X-Result-Msg: ' . $msg);

        if ($msg === 'SUCCESS') {
            send_status(200);
            header('Content-Type: application/json; charset=utf-8');
            echo $body;
            exit;
        } elseif ($msg === 'RETRY') {
            // 재시도 권장. 호환모드가 아니면 503 사용(+Retry-After)
            if ($compat200) {
                send_status(200);
                header('Content-Type: application/json; charset=utf-8');
                echo $body;
            } else {
                header('Retry-After: 5');
                json_out($decoded, 503);
            }
        } elseif ($msg === 'NO_DATA') {
            if ($compat200) {
                send_status(200);
                header('Content-Type: application/json; charset=utf-8');
                echo $body;
            } else {
                // 본문 없이 204
                send_status(204);
            }
            exit;
        } else {
            // 알 수 없는 상태
            json_out($decoded, 502);
        }
    } else {
        // JSON이지만 msg 없음 → 그대로 200
        send_status(200);
        header('Content-Type: application/json; charset=utf-8');
        echo $body;
        exit;
    }
}

// JSON이 아니면 종료코드 기반 매핑
$http = ($status === 124 ? 504 : ($status ? 502 : 500));

$err_payload = array(
    'error'    => '비정상 응답',
    'http_code'=> $http,
    'exit'     => $status,
    'output'   => safe_substr($body, 2000),
);
if ($debug) $err_payload['cmd'] = $cmd;

json_out($err_payload, $http);

?>