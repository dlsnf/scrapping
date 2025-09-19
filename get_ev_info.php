<?php
require_once __DIR__ . '/db_ev.php';
require_once __DIR__ . '/check_access_ev.php';

/* ---------- JSON 한글 깨짐 방지 ---------- */
if (!function_exists('json_encode_utf8')) {
    function json_encode_utf8($data) {
        $json = json_encode($data);
        if ($json === false) return 'null';
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($m){
            $code = hexdec($m[1]);
            if ($code < 0x80) return chr($code);
            if ($code < 0x800) return chr(0xC0|($code>>6)).chr(0x80|($code&0x3F));
            return chr(0xE0|($code>>12)).chr(0x80|(($code>>6)&0x3F)).chr(0x80|($code&0x3F));
        }, $json);
    }
}

/* ---------- 상태코드 전송 (PHP 5.3용) ---------- */
function send_status($code) {
    $texts = array(200=>'OK',400=>'Bad Request',403=>'Forbidden',404=>'Not Found',500=>'Internal Server Error',502=>'Bad Gateway',504=>'Gateway Timeout');
    $proto = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
    $text  = isset($texts[$code]) ? $texts[$code] : '';
    header($proto . ' ' . $code . ' ' . $text);
}
function json_out($arr, $code=200) {
    send_status($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode_utf8($arr);
    exit;
}

/* ---------- 파라미터/체크 ---------- */
$sid      = isset($_GET['sid']) ? trim($_GET['sid']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$key      = isset($_GET['key']) ? strtoupper(trim($_GET['key'])) : '';
$debug    = ((isset($_GET['debug']) && $_GET['debug']==='1') || (isset($_SERVER['HTTP_X_DEBUG']) && $_SERVER['HTTP_X_DEBUG']==='1'));

if ($sid==='' || $category==='' || $key==='') {
    json_out(array('error'=>'필수 파라미터 누락: sid, category, key','http_code'=>400),400);
}
$chk = check_access_inline_ev($category,$sid,$key,'text');
if ($chk !== 'access') {
    json_out(array('error'=>$chk,'http_code'=>403),403);
}

/* ---------- docker exec 명령 구성 ---------- */
$container = 'pyppeteer-service';   // 실제 컨테이너 이름 확인!
$timeout_s = 15;                    // script.py --timeout 값
$cmd = 'docker exec ' . escapeshellarg($container)
     . ' python3 /app/script.py'
     . ' --sid=' . escapeshellarg($sid)
     . ' --timeout=' . (int)$timeout_s;
if ($debug) $cmd .= ' --log=1';

if (is_executable('/usr/bin/timeout')) {
    // docker exec 전체를 외부에서 한 번 더 감싸 타임아웃 보호
    $cmd = '/usr/bin/timeout ' . (int)($timeout_s + 3) . 's ' . $cmd;
}

/* ---------- proc_open으로 실행(Exit code/STDERR 분리) ---------- */
$descriptors = array(
    0 => array('pipe','r'),
    1 => array('pipe','w'),
    2 => array('pipe','w'),
);
$proc = proc_open($cmd, $descriptors, $pipes);
if (!is_resource($proc)) {
    json_out(array('error'=>'실행 시작 실패','cmd'=>$cmd),500);
}
fclose($pipes[0]); // stdin 닫기

$stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);

$status = proc_close($proc);
$body   = trim($stdout !== '' ? $stdout : $stderr);

/* ---------- JSON 여부 판별 후 반환 ---------- */
if ($body !== '' && ($body[0] === '{' || $body[0] === '[')) {
    send_status(200);
    header('Content-Type: application/json; charset=utf-8');
    echo $body;
    exit;
}

$http = ($status === 124 ? 504 : ($status ? 502 : 500));
json_out(array(
    'error'    => '비정상 응답',
    'http_code'=> $http,
    'exit'     => $status,
    'output'   => mb_substr($body, 0, 2000, 'UTF-8'),
    'cmd'      => $cmd
), $http);

?>