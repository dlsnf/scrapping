<?php
// 라우터: action 값에 따라 분기
require_once __DIR__.'/auth.php';
require_login();
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';

// 공통으로 쓰는 정렬 유틸(화살표)
if (!function_exists('icon_dir')) {
    function icon_dir($col, $sort, $dir){
        if ($col !== $sort) return '';
        return ($dir === 'ASC') ? '↑' : '↓';
    }
}
if (!function_exists('next_dir')) {
    function next_dir($dir){ return ($dir === 'ASC') ? 'DESC' : 'ASC'; }
}

switch ($action) {
    case 'new':
        require __DIR__.'/licenses_new.php';
        break;
    case 'view':
        require __DIR__.'/licenses_view.php';
        break;
    case 'save':
    case 'revoke':
    case 'restore':
    case 'reset_counts':
    case 'update_counts':
        require __DIR__.'/licenses_actions.php';
        break;
    case 'list':
    default:
        require __DIR__.'/licenses_list.php';
        break;
}
