<?php
// URL에서 sid 파라미터 받기
$sid = isset($_GET['sid']) ? trim($_GET['sid']) : '';

// sid 값이 비어 있는지 검증
if (empty($sid)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        "msg" => "Error 파라미터 값이 존재하지 않습니다.",
        "total_time" => "0.00 seconds"
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

// sid를 명령어에 포함
$command = "/usr/bin/docker exec pyppeteer-service python /app/script.py --sid " . escapeshellarg($sid);

$output = shell_exec($command);

header('Content-Type: application/json; charset=utf-8');
if ($output === null || empty($output) || strpos($output, 'permission denied') !== false) {
    $error = error_get_last();
    echo json_encode(array(
        "error" => "스크립트 실행 실패",
        "details" => $error,
        "output" => $output
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

echo $output;
?>