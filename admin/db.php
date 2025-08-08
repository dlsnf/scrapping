<?php
require_once __DIR__.'/config.php';

/* DB 연결 */
function db() {
    static $conn;
    if ($conn) return $conn;

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die('DB 연결 실패: '.$conn->connect_error);
    }
    $conn->set_charset('utf8');
    return $conn;
}

/* ------- 내부 유틸: 결과를 assoc로 받기 (mysqlnd 불필요) ------- */
function stmt_fetch_assoc_all($stmt) {
    $meta = $stmt->result_metadata();
    if (!$meta) return array(); // 결과 없는 쿼리

    $fields = array();
    $row = array();
    $bindArgs = array();
    while ($field = $meta->fetch_field()) {
        $fields[] = $field->name;
        $row[$field->name] = null;
        $bindArgs[] = &$row[$field->name];
    }
    call_user_func_array(array($stmt, 'bind_result'), $bindArgs);

    $out = array();
    while ($stmt->fetch()) {
        // $row는 참조라서 복사본을 넣어야 함
        $copy = array();
        foreach ($fields as $f) $copy[$f] = $row[$f];
        $out[] = $copy;
    }
    return $out;
}

/* call_user_func_array에서 참조 넘기기 */
function ref($arr) {
    $refs = array();
    foreach ($arr as $k => $v) $refs[$k] = &$arr[$k];
    return $refs;
}

/* 단일 행 */
function db_one($sql, $types = '', $params = array()) {
    $stmt = db()->prepare($sql);
    if (!$stmt) die('SQL 준비 실패: '.db()->error);
    if ($types) call_user_func_array(array($stmt, 'bind_param'), array_merge(array($types), ref($params)));
    if (!$stmt->execute()) die('SQL 실행 오류: '.$stmt->error);

    // SELECT 여부 판별: result_metadata 있으면 SELECT
    $meta = $stmt->result_metadata();
    if ($meta) {
        $rows = stmt_fetch_assoc_all($stmt);
        $stmt->close();
        return $rows ? $rows[0] : null;
    } else {
        // 결과 없는 쿼리 (예: UPDATE)에서 db_one 쓰면 null
        $stmt->close();
        return null;
    }
}

/* 전체 행 */
function db_all($sql, $types = '', $params = array()) {
    $stmt = db()->prepare($sql);
    if (!$stmt) die('SQL 준비 실패: '.db()->error);
    if ($types) call_user_func_array(array($stmt, 'bind_param'), array_merge(array($types), ref($params)));
    if (!$stmt->execute()) die('SQL 실행 오류: '.$stmt->error);

    $meta = $stmt->result_metadata();
    if ($meta) {
        $rows = stmt_fetch_assoc_all($stmt);
        $stmt->close();
        return $rows;
    } else {
        $stmt->close();
        return array();
    }
}

/* 변경계열(INSERT/UPDATE/DELETE) */
function db_exec($sql, $types = '', $params = array()) {
    $stmt = db()->prepare($sql);
    if (!$stmt) die('SQL 준비 실패: '.db()->error);
    if ($types) call_user_func_array(array($stmt, 'bind_param'), array_merge(array($types), ref($params)));
    $ok = $stmt->execute();
    $err = $ok ? null : $stmt->error;
    $aff = $stmt->affected_rows;
    $stmt->close();
    if (!$ok) die('SQL 실행 오류: '.$err);
    return $aff;
}
