<?php
// admin/db.php
// PHP 5.3 호환, mysqlnd 불필요(= mysqli_stmt_get_result 미사용)

require_once __DIR__ . '/config.php';

if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
if (!defined('DB_NAME')) define('DB_NAME', 'scrapping');
if (!defined('DB_PORT')) define('DB_PORT', 3306);

$DB = null;

/* 항상 유효한 mysqli 링크 반환 */
function db_get() {
    global $DB;
    if ($DB instanceof mysqli) {
        return $DB;
    }
    $DB = mysqli_init();
    if (!$DB) {
        die('DB init failed');
    }
    if (!@mysqli_real_connect($DB, DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT)) {
        die('DB connect failed: '.mysqli_connect_error());
    }
    mysqli_set_charset($DB, 'utf8');
    return $DB;
}

/* 선택: 명시적 종료 */
function db_close() {
    global $DB;
    if ($DB instanceof mysqli) {
        mysqli_close($DB);
        $DB = null;
    }
}

/* 트랜잭션 헬퍼 (prepared 아님) */
function db_begin() {
    $link = db_get();
    mysqli_autocommit($link, false); // 트랜잭션 시작
}
function db_commit() {
    $link = db_get();
    mysqli_commit($link);
    mysqli_autocommit($link, true);
}
function db_rollback() {
    $link = db_get();
    mysqli_rollback($link);
    mysqli_autocommit($link, true);
}

/* call_user_func_array용 참조 래퍼 (PHP 5.x) */
function _db_ref_values(&$arr){
    // PHP 5.3에서는 참조 배열이 필요
    if (strnatcmp(phpversion(),'5.3') >= 0) {
        $refs = array();
        foreach($arr as $k => $v) $refs[$k] = &$arr[$k];
        return $refs;
    }
    return $arr;
}

/* 내부: PREPARE + BIND + EXECUTE (결과 없는 쿼리) */
function _db_exec_stmt($sql, $types, $params) {
    $link = db_get();
    $stmt = mysqli_prepare($link, $sql);
    if (!$stmt) {
        throw new Exception('DB prepare error: '.mysqli_error($link));
    }
    $bind = array_merge(array($stmt, $types), _db_ref_values($params));
    call_user_func_array('mysqli_stmt_bind_param', $bind);
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception('DB exec error: '.$err);
    }
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return $affected;
}

/* 내부: PREPARE + BIND + EXECUTE (SELECT 결과를 bind_result로 받기) */
function _db_select_stmt($sql, $types, $params, $fetch_all=false) {
    $link = db_get();
    $stmt = mysqli_prepare($link, $sql);
    if (!$stmt) {
        throw new Exception('DB prepare error: '.mysqli_error($link));
    }
    $bind = array_merge(array($stmt, $types), _db_ref_values($params));
    call_user_func_array('mysqli_stmt_bind_param', $bind);

    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception('DB exec error: '.$err);
    }

    $meta = mysqli_stmt_result_metadata($stmt);
    if (!$meta) {
        // SELECT가 아님
        $row = null;
        mysqli_stmt_close($stmt);
        return $fetch_all ? array() : $row;
    }

    $fields = array();
    $row = array();
    $bindFields = array();
    while ($field = mysqli_fetch_field($meta)) {
        $fields[] = $field->name;
        $row[$field->name] = null;
        $bindFields[] = &$row[$field->name];
    }
    mysqli_free_result($meta);

    call_user_func_array('mysqli_stmt_bind_result', array_merge(array($stmt), $bindFields));

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        // 복제해서 저장
        $copy = array();
        foreach ($fields as $f) {
            $copy[$f] = $row[$f];
        }
        if ($fetch_all) {
            $rows[] = $copy;
        } else {
            // 첫 행만
            mysqli_stmt_close($stmt);
            return $copy;
        }
    }
    mysqli_stmt_close($stmt);
    return $fetch_all ? $rows : null;
}

/* 실행: INSERT/UPDATE/DELETE/DDL 등 */
function db_exec($sql, $types=null, $params=array()) {
    $link = db_get();
    if ($types === null) {
        $ok = mysqli_query($link, $sql);
        if (!$ok) {
            throw new Exception('DB exec error: '.mysqli_error($link));
        }
        return true;
    } else {
        return _db_exec_stmt($sql, $types, $params);
    }
}

/* 한 행 */
function db_one($sql, $types=null, $params=array()) {
    $link = db_get();
    if ($types === null) {
        $res = mysqli_query($link, $sql);
        if (!$res) throw new Exception('DB query error: '.mysqli_error($link));
        $row = mysqli_fetch_assoc($res);
        if ($res) mysqli_free_result($res);
        return $row;
    } else {
        return _db_select_stmt($sql, $types, $params, false);
    }
}

/* 여러 행 */
function db_all($sql, $types=null, $params=array()) {
    $link = db_get();
    if ($types === null) {
        $res = mysqli_query($link, $sql);
        if (!$res) throw new Exception('DB query error: '.mysqli_error($link));
        $rows = array();
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
        if ($res) mysqli_free_result($res);
        return $rows;
    } else {
        return _db_select_stmt($sql, $types, $params, true);
    }
}

