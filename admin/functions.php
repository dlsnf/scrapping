<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/db.php';

/* PHP 5.3에는 hash_equals() 없음 → 폴리필 */
if (!function_exists('hash_equals')) {
    function hash_equals($known_string, $user_string) {
        if (!is_string($known_string) || !is_string($user_string)) {
            return false;
        }
        if (strlen($known_string) !== strlen($user_string)) {
            return false;
        }
        $res = $known_string ^ $user_string;
        $ret = 0;
        for ($i = strlen($res) - 1; $i >= 0; $i--) {
            $ret |= ord($res[$i]);
        }
        return $ret === 0;
    }
}

/* 기본 출력 */
function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/* CSRF */
function csrf_token() {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(rand_bytes(16));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}
function csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = isset($_POST[CSRF_TOKEN_NAME]) ? $_POST[CSRF_TOKEN_NAME] : '';
        if (!$t || $t !== $_SESSION[CSRF_TOKEN_NAME]) {
            die('CSRF 토큰 오류');
        }
    }
}

/* PHP 5.3용 난수 바이트 */
function rand_bytes($length){
    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $b = openssl_random_pseudo_bytes($length, $strong);
        if ($b !== false) return $b;
    }
    $b = '';
    for ($i=0; $i<$length; $i++) $b .= chr(mt_rand(0,255));
    return $b;
}

/* SHA-256 + salt + 반복(레거시 환경용) */
function password_hash_legacy($password, $salt=null, $iter=8000) {
    if ($salt===null) $salt = bin2hex(rand_bytes(16));
    $h = hash('sha256', $salt.$password, true);
    for ($i=0; $i<$iter; $i++) $h = hash('sha256', $h.$password, true);
    return $salt.':'.$iter.':'.bin2hex($h);
}
function password_verify_legacy($password, $stored) {
    $parts = explode(':', $stored);
    if (count($parts) !== 3) return false;
    list($salt,$iter,$hashHex) = $parts;
    $calc = password_hash_legacy($password, $salt, (int)$iter);
    return hash_equals($stored, $calc);
}

/* UUID v4 (문자열) */
function uuid_v4() {
    $data = rand_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return sprintf('%s-%s-%s-%s-%s',
        substr($hex,0,8), substr($hex,8,4), substr($hex,12,4),
        substr($hex,16,4), substr($hex,20,12)
    );
}

/* 10자리 라이선스 키 (A-Z0-9) */
function generate_license_key($len=10){
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i=0; $i<$len; $i++) $out .= $chars[mt_rand(0, strlen($chars)-1)];
    return $out;
}

/* 감사 로그 기록 */
function log_event($license_id, $type, $actor_admin_id, $actor, $request_ip, $before=null, $after=null){
    $sql = "INSERT INTO license_events
        (license_id, event_type, actor_admin_id, actor, request_ip, `before`, `after`)
        VALUES (?,?,?,?,?,?,?)";
    $b = $before ? $before : null;
    $a = $after ? $after : null;
    db_exec($sql, 'sssssss', array($license_id, $type, $actor_admin_id, $actor, $request_ip, $b, $a));
}

/* 페이지 공통 헤더/푸터 */
function render_header($title, $user=null){
    echo '<!doctype html><html><head><meta charset="utf-8">';
    echo '<meta name="apple-mobile-web-app-capable" content="no">';
    echo '<title>'.h($title).'</title>';
    echo '<style>
    body{font:14px/1.4 Arial, Helvetica, sans-serif; margin:20px; color:#222}
    a{color:#0b5fff; text-decoration:none}
    a:hover{text-decoration:underline}
    .topbar{margin-bottom:15px; padding-bottom:10px; border-bottom:1px solid #ddd}
    .btn{display:inline-block; padding:6px 10px; border:1px solid #ccc; border-radius:4px; background:#f7f7f7}
    .btn.primary{border-color:#2a62ff; background:#2a62ff; color:#fff}
    .table{border-collapse:collapse; width:100%}
    .table th,.table td{border:1px solid #ddd; padding:8px; vertical-align:top}
    .table th{background:#fafafa}
    .muted{color:#777}
    .error{color:#b00020}
    .ok{color:#0a7}
    form.inline{display:inline}
    input[type=text],select,textarea{padding:6px; border:1px solid #ccc; border-radius:3px; width:100%}
    .row{display:block; margin-bottom:8px}
    .grid{display:grid; grid-template-columns:repeat(2,1fr); gap:10px}
    </style>';

    // ===== jQuery & DateTime Picker (로컬) =====
    echo '<link rel="stylesheet" href="assets/jquery-ui.min.css">';
    echo '<link rel="stylesheet" href="assets/jquery-ui-timepicker-addon.min.css">';
    echo '<script src="assets/jquery.min.js"></script>';
    echo '<script src="assets/jquery-ui.min.js"></script>';
    echo '<script src="assets/jquery-ui-timepicker-addon.min.js"></script>';
    echo '<script>
    if (window.jQuery) {
        jQuery(function($){
        $(".js-datetime").each(function(){
            try {
            $(this).datetimepicker({
                dateFormat: "yy-mm-dd",
                timeFormat: "HH:mm:ss",
                showSecond: true,
                controlType: "select",
                stepHour: 1, stepMinute: 1, stepSecond: 1
            });
            } catch(e) {}
        });
        });
    }
    </script>';

    echo '</head><body>';
    echo '<div class="topbar">';
    echo '<strong>'.h(APP_NAME).'</strong>';
    echo ' &nbsp;|&nbsp; <a href="index.php">대시보드</a> &nbsp; ';
    echo '<a href="licenses.php">라이선스</a> &nbsp; ';
    echo '<a href="customers.php">고객</a> &nbsp; ';
    echo '<a href="products.php">상품</a> &nbsp; ';
    echo '<a href="events.php">감사로그</a>';
    if ($user) {
        echo ' &nbsp;|&nbsp; <span class="muted">'.h($user['username']).'('.h($user['role']).')</span> ';
        echo ' <a class="btn" href="logout.php">로그아웃</a>';
    }
    echo '</div>';
}

function render_footer(){
    echo '</body></html>';
}
