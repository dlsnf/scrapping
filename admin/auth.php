<?php
require_once __DIR__.'/functions.php';
session_name(SESSION_NAME);
session_start();

/* 로그인 필요 페이지에서 호출 */
/**
 * @return mixed
 */
function require_login(){
    if (empty($_SESSION['admin'])) {
        header('Location: login.php');
        exit;
    }
}

/* 현재 사용자 */
/**
 * @return mixed
 */
function current_admin(){
    return isset($_SESSION['admin']) ? $_SESSION['admin'] : null;
}

/* 권한 체크(OWNER/ADMIN/OPERATOR/READONLY) */
/**
 * @return mixed
 */
function require_role($roles){
    $me = current_admin();
    if (!$me) { header('Location: login.php'); exit; }
    if (!in_array($me['role'], (array)$roles)) {
        die('권한이 없습니다.');
    }
}
