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
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>'.h($title).'</title>';

    // 공통 스타일
    echo '<link rel="stylesheet" href="assets/style.css">';

    // jQuery & DateTime Picker (로컬)
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

    // 상단 바
    echo '<header class="topbar container">';
    echo '<div class="brand">'.h(APP_NAME).'</div>';
    echo '<nav>';
    echo '<a href="index.php">대시보드</a>';
    echo '<a href="categories.php">카테고리</a> &nbsp; ';
    echo '<a href="licenses.php">라이선스</a>';    
    echo '<a href="customers.php">고객</a>';
    echo '<a href="products.php">상품</a>';
    echo '<a href="events.php">감사로그</a>';
    echo '</nav>';
    if ($user) {
        echo '<div style="margin-left:auto" class="muted">'.h($user['username']).'('.h($user['role']).') ';
        echo '<a class="btn" style="margin-left:8px" href="logout.php">로그아웃</a></div>';
    }
    echo '</header>';

    // 메인 컨테이너 시작
    echo '<main class="container"><div class="page">';
}


function render_footer(){
    echo '</div></main>';
    // footer를 쓰고 싶으면 여기에 추가 가능: echo '<footer class="container muted">© '.date('Y').'</footer>';
    echo '</body></html>';
}


/* --- 정렬 도우미: ORDER BY 구문 만들기 --- */
function get_sort_sql($allowed, $default_col, $default_dir){
    $sort = isset($_GET['sort']) ? $_GET['sort'] : $default_col;
    $dir  = isset($_GET['dir']) ? strtoupper($_GET['dir']) : $default_dir;
    if (!isset($allowed[$sort])) $sort = $default_col;
    if ($dir !== 'ASC' && $dir !== 'DESC') $dir = $default_dir;
    return array('ORDER BY '.$allowed[$sort].' '.$dir, $sort, $dir);
}

/* --- 정렬 링크(↑/↓) --- */
function sort_link($title, $key, $base=''){
    if ($base==='') {
        // 호출 파일명을 자동 추정
        $base = basename($_SERVER['PHP_SELF']);
    }
    $curr_sort = isset($_GET['sort']) ? $_GET['sort'] : '';
    $curr_dir  = isset($_GET['dir']) ? strtoupper($_GET['dir']) : 'ASC';
    $next_dir  = ($curr_sort === $key && $curr_dir==='ASC') ? 'DESC' : 'ASC';
    $arrow     = '';
    if ($curr_sort === $key) $arrow = ($curr_dir==='ASC') ? ' ↑' : ' ↓';

    $params = $_GET;
    $params['sort'] = $key;
    $params['dir']  = $next_dir;

    $q = array();
    foreach ($params as $k=>$v) { $q[] = urlencode($k).'='.urlencode($v); }
    return '<a href="'.h($base).'?'.implode('&',$q).'">'.h($title).$arrow.'</a>';
}

function flash_render() {
    if (!session_id()) session_start();
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $f) {
            $cls = ($f['type']==='ok') ? 'flash-ok' : 'flash-error';
            echo '<div class="'.$cls.'" style="padding:8px;margin:5px 0;border:1px solid #ccc;background:#f9f9f9">';
            echo h($f['msg']);
            echo '</div>';
        }
        unset($_SESSION['flash']);
    }
}

function redirect_with($url, $msg, $type='ok') {
    if (!session_id()) session_start();
    if (!isset($_SESSION['flash'])) $_SESSION['flash'] = array();
    $_SESSION['flash'][] = array('type'=>$type, 'msg'=>$msg);
    header('Location: '.$url);
    exit;
}
