<?php
/**
 * 파일: /scrapping/admin/db.php
 * 환경: PHP 5.3.3 / MySQL 5.1.x (mysqlnd 없이 동작)
 * - 접속 정보는 config.php 상수만 사용
 * - get_result() 미사용 (bind_result + metadata 방식)
 * - 제공 헬퍼: db_one, db_all, db_exec, db_begin/commit/rollback, db_insert_id
 * - 유틸: rand_bytes, uuid_v4, hash_equals (폴리필)
 */

if (!defined('SCRAPPING_DB_PHP')) define('SCRAPPING_DB_PHP', 1);

require_once __DIR__.'/config.php';

/* -----------------------------------------
 * 연결 (싱글톤)
 * ----------------------------------------- */
/**
 * @return mixed
 */
function db()
{
    static $mysqli = null;
    if ($mysqli instanceof mysqli) return $mysqli;

    // 에러 리포트 끔 (구버전 호환)
    if (!defined('MYSQLI_INIT_DONE')) {
        define('MYSQLI_INIT_DONE', 1);
        mysqli_report(MYSQLI_REPORT_OFF);
    }

    $host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $user = defined('DB_USER') ? DB_USER : 'root';
    $pass = defined('DB_PASS') ? DB_PASS : '';
    $name = defined('DB_NAME') ? DB_NAME : 'test';

    $mysqli = @new mysqli($host, $user, $pass, $name);
    if ($mysqli->connect_error) {
        die('DB 연결 실패: '.$mysqli->connect_error);
    }

    // utf8 (5.1 환경: utf8mb4 미지원일 수 있음)
    if (!$mysqli->set_charset('utf8')) {
        $mysqli->query("SET NAMES utf8");
    }
    return $mysqli;
}

/**
 * @return mixed
 */
function db_close()
{
    $m = db();
    if ($m instanceof mysqli) $m->close();
}

/* -----------------------------------------
 * 트랜잭션
 * ----------------------------------------- */
/**
 * @return mixed
 */
function db_begin()
{
    $m = db();
    if ($m->autocommit(false) === false) {
        throw new Exception('DB autocommit(false) 실패: '.$m->error);
    }
}
/**
 * @return mixed
 */
function db_commit()
{
    $m = db();
    if ($m->commit() === false) throw new Exception('DB COMMIT 실패: '.$m->error);
    $m->autocommit(true);
}
/**
 * @return mixed
 */
function db_rollback()
{
    $m = db();
    $m->rollback();
    $m->autocommit(true);
}

/* -----------------------------------------
 * 내부: prepare + bind
 *  - $types: 'sii' 같은 문자열 또는 null
 *  - $params: array(...) 또는 null
 * ----------------------------------------- */
/**
 * @return mixed
 */
function _db_prepare_bind($sql, $types = null, $params = null)
{
    $m = db();
    $stmt = $m->prepare($sql);
    if ($stmt === false) {
        throw new Exception('DB prepare error: '.$m->error.' (SQL='.substr($sql,0,200).')');
    }

    if ($types !== null && $params !== null) {
        // 인자 수 체크(디버깅 도움)
        if (strlen($types) != count($params)) {
            $stmt->close();
            throw new Exception('bind_param 인자 수 불일치: types='.strlen($types).', params='.count($params));
        }
        // call_user_func_array용 참조 배열 구성
        $bind = array($types);
        for ($i=0; $i<count($params); $i++) {
            $bind[] = &$params[$i];
        }
        if (!call_user_func_array(array($stmt, 'bind_param'), $bind)) {
            $err = $stmt->error;
            $stmt->close();
            throw new Exception('DB bind_param error: '.$err);
        }
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new Exception('DB execute error: '.$err);
    }
    return $stmt;
}

/* -----------------------------------------
 * 내부: 결과 바인딩 유틸 (mysqlnd 불필요)
 * ----------------------------------------- */
/**
 * @return mixed
 */
function _stmt_bind_assoc(mysqli_stmt $stmt, &$row, &$binds)
{
    $meta = $stmt->result_metadata();
    if (!$meta) { $row = null; $binds = null; return false; }

    $row = array();
    $binds = array();
    while ($field = $meta->fetch_field()) {
        $row[$field->name] = null;
        $binds[] = &$row[$field->name];
    }
    call_user_func_array(array($stmt, 'bind_result'), $binds);
    return true;
}

/* -----------------------------------------
 * SELECT 한 행
 *  - $throw=true 면 결과 없을 때 예외
 * ----------------------------------------- */
/**
 * @return mixed
 */
function db_one($sql, $types = null, $params = null, $throw = false)
{
    $stmt = _db_prepare_bind($sql, $types, $params);

    $row = null; $binds = null;
    $hasMeta = _stmt_bind_assoc($stmt, $row, $binds);
    if (!$hasMeta) { $stmt->close(); if ($throw) throw new Exception('DB not found (db_one)'); return null; }

    $found = $stmt->fetch();
    $stmt->free_result();
    $stmt->close();

    if (!$found) { if ($throw) throw new Exception('DB not found (db_one)'); return null; }

    // ✨ 참조 끊고 값 복사
    $copy = array();
    foreach ($row as $k => $v) $copy[$k] = $v;
    return $copy;
}


/* -----------------------------------------
 * SELECT 여러 행
 * ----------------------------------------- */
/**
 * @return mixed
 */
function db_all($sql, $types = null, $params = null)
{
    $stmt = _db_prepare_bind($sql, $types, $params);

    $row = null; $binds = null;
    $hasMeta = _stmt_bind_assoc($stmt, $row, $binds);
    if (!$hasMeta) { $stmt->close(); return array(); }

    $rows = array();
    $stmt->store_result();
    while ($stmt->fetch()) {
        // ✨ 매 행마다 깊은 복사본을 push (참조 끊기)
        $copy = array();
        foreach ($row as $k => $v) $copy[$k] = $v;
        $rows[] = $copy;
    }
    $stmt->free_result();
    $stmt->close();
    return $rows;
}

/* -----------------------------------------
 * INSERT / UPDATE / DELETE
 * ----------------------------------------- */
/**
 * @return mixed
 */
function db_exec($sql, $types = null, $params = null)
{
    $stmt = _db_prepare_bind($sql, $types, $params);
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

/**
 * @return mixed
 */
function db_insert_id()
{
    return db()->insert_id;
}




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
