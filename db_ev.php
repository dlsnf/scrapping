<?php
/**
 * 파일: /scrapping/db_ev.php
 * 목적: get_ev_info.php 및 check_access_ev.php 전용 DB 연결(별도 계정)
 * 주의: admin/db.php와 충돌 방지를 위해 함수명을 ev_db_* 로 분리
 * 환경: PHP 5.3 / MySQL 5.1 (mysqlnd 없이 동작)
 */

/* ===== 여기 값만 이 파일에서 관리 ===== */
if (!defined('EV_DB_HOST')) define('EV_DB_HOST', 'localhost');
if (!defined('EV_DB_USER')) define('EV_DB_USER', '');   // ← 원하는 전용 계정으로 변경
if (!defined('EV_DB_PASS')) define('EV_DB_PASS', '');          // ← 원하는 전용 비밀번호
if (!defined('EV_DB_NAME')) define('EV_DB_NAME', 'scrapping');         // ← DB명

/* ===== 안전 비교(폴리필) ===== */
if (!function_exists('hash_equals')) {
/**
 * @return mixed
 */
    function hash_equals($a, $b){
        $a = (string)$a; $b = (string)$b;
        if (strlen($a) !== strlen($b)) return false;
        $res = 0; $len = strlen($a);
        for ($i=0; $i<$len; $i++) $res |= ord($a[$i]) ^ ord($b[$i]);
        return $res === 0;
    }
}

/* ===== 연결 싱글톤 ===== */
/**
 * @return mixed
 */
function ev_db()
{
    static $mysqli = null;
    if ($mysqli instanceof mysqli) return $mysqli;

    mysqli_report(MYSQLI_REPORT_OFF);
    // EV_DB_USER/EV_DB_PASS가 비어있으면(기본값) admin 설정의 DB_USER/DB_PASS를 사용하도록 허용
    $ev_user = EV_DB_USER;
    $ev_pass = EV_DB_PASS;
    if (($ev_user === null || $ev_user === '') && defined('DB_USER')) $ev_user = DB_USER;
    if (($ev_pass === null || $ev_pass === '') && defined('DB_PASS')) $ev_pass = DB_PASS;
    $mysqli = @new mysqli(EV_DB_HOST, $ev_user, $ev_pass, EV_DB_NAME);
    if ($mysqli->connect_error) {
        header('Content-Type: application/json; charset=utf-8');
        // 한글이 포함된 에러를 그대로 출력하려면 JSON 유니코드 이스케이프를 해제
        $payload = array('error' => 'DB 연결 실패(get_ev 전용): ' . $mysqli->connect_error);
        if (defined('JSON_UNESCAPED_UNICODE')) {
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        } else {
            // PHP 5.x 호환 폴백: \uXXXX를 실제 UTF-8 문자로 변환
            $json = json_encode($payload);
            echo preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($match){
                return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
            }, $json);
        }
        exit;
    }
    if (!$mysqli->set_charset('utf8')) {
        $mysqli->query("SET NAMES utf8");
    }
    return $mysqli;
}

/* ===== 내부: prepare + bind ===== */
/**
 * @return mixed
 */
function _ev_prepare_bind($sql, $types=null, $params=null){
    $m = ev_db();
    $stmt = $m->prepare($sql);
    if ($stmt === false) {
        throw new Exception('DB prepare error(ev): '.$m->error);
    }
    if ($types !== null && $params !== null) {
        if (strlen($types) != count($params)) {
            $stmt->close();
            throw new Exception('bind_param 인자 수 불일치(ev)');
        }
        $bind = array($types);
        for ($i=0; $i<count($params); $i++) $bind[] = &$params[$i];
        if (!call_user_func_array(array($stmt,'bind_param'), $bind)) {
            $err = $stmt->error; $stmt->close();
            throw new Exception('DB bind_param error(ev): '.$err);
        }
    }
    if (!$stmt->execute()) {
        $err = $stmt->error; $stmt->close();
        throw new Exception('DB execute error(ev): '.$err);
    }
    return $stmt;
}

/* ===== 내부: 결과 바인딩 ===== */
/**
 * @return mixed
 */
function _ev_bind_assoc(mysqli_stmt $stmt, &$row, &$binds){
    $meta = $stmt->result_metadata();
    if (!$meta){ $row=null; $binds=null; return false; }
    $row=array(); $binds=array();
    while ($f = $meta->fetch_field()){
        $row[$f->name] = null;
        $binds[] = &$row[$f->name];
    }
    call_user_func_array(array($stmt,'bind_result'), $binds);
    return true;
}

/* ===== SELECT 한 행 ===== */
/**
 * @return mixed
 */
function ev_db_one($sql, $types=null, $params=null){
    $stmt = _ev_prepare_bind($sql, $types, $params);
    $row=null; $binds=null;
    $ok = _ev_bind_assoc($stmt, $row, $binds);
    if (!$ok){ $stmt->close(); return null; }
    $found = $stmt->fetch();
    $stmt->free_result();
    $stmt->close();
    if (!$found) return null;

    // 깊은 복사
    $copy = array();
    foreach($row as $k=>$v) $copy[$k]=$v;
    return $copy;
}

/* ===== SELECT 여러 행 ===== */
/**
 * @return mixed
 */
function ev_db_all($sql, $types=null, $params=null){
    $stmt = _ev_prepare_bind($sql, $types, $params);
    $row=null; $binds=null;
    $ok = _ev_bind_assoc($stmt, $row, $binds);
    if (!$ok){ $stmt->close(); return array(); }
    $rows = array();
    $stmt->store_result();
    while ($stmt->fetch()){
        $copy=array();
        foreach($row as $k=>$v) $copy[$k]=$v;
        $rows[]=$copy;
    }
    $stmt->free_result();
    $stmt->close();
    return $rows;
}

/* ===== 변경 쿼리 ===== */
/**
 * @return mixed
 */
function ev_db_exec($sql, $types=null, $params=null){
    $stmt = _ev_prepare_bind($sql, $types, $params);
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}
