<?php
// 위치: /scrapping/check_access.php
require_once __DIR__ . '/admin/db.php';
require_once __DIR__ . '/admin/functions.php';

/**
 * include로 호출 가능한 함수형 진입점
 * - return: format='text'면 문자열("access" 또는 불가 사유 문구 포함)
 *           format='json'이면 JSON 문자열
 */
function check_access_inline($category, $product, $key, $format = 'text') {
    $msg_allowed = 'access';

    $is_unlimited_or_pos = function($v){
        if ($v === null || $v === '') return true;  // 무한
        return ((int)$v) > 0;
    };

    $text = function($ok, $reason=''){
        if ($ok) return 'access';
        $s = '접근이 불가합니다.';
        if ($reason!=='') $s .= ' (사유: '.$reason.')';
        return $s;
    };

    // ---- 파라미터 검증 ----
    if ($category==='' || $product==='' || $key==='') {
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'필수 파라미터(category, product, key) 누락'))
            : $text(false, '필수 파라미터(category, product, key) 누락');
    }

    // ---- 1) 라이선스 조회 ----
    $row = db_one("
        SELECT lk.id, lk.`key`, lk.status,
               lk.max_activations, lk.daily_max_activations,
               lk.total_activations, lk.daily_activations, lk.daily_activations_date,
               lk.expires_at, lk.last_request_at, lk.request_count_today, lk.request_count_date,
               c.name AS category_name, p.code AS product_code
          FROM license_keys lk
     LEFT JOIN categories c ON c.id = lk.category_id
     LEFT JOIN products  p ON p.id = lk.product_id
         WHERE lk.`key` = ?
         LIMIT 1
    ", 's', array(strtoupper(trim($key))));

    if (!$row) {
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'해당 라이선스 키가 없습니다.'))
            : $text(false, '해당 라이선스 키가 없습니다.');
    }
    if ($row['category_name'] !== $category) {
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'카테고리명이 일치하지 않습니다.'))
            : $text(false, '카테고리명이 일치하지 않습니다.');
    }
    if ($row['product_code'] !== $product) {
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'상품코드가 일치하지 않습니다.'))
            : $text(false, '상품코드가 일치하지 않습니다.');
    }
    if ($row['status'] !== 'ACTIVE') {
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'라이선스 상태가 사용(ACTIVE)이 아닙니다.'))
            : $text(false, '라이선스 상태가 사용(ACTIVE)이 아닙니다.');
    }

    // 만료일 체크
    if (!empty($row['expires_at']) && $row['expires_at'] !== '9999-12-31 23:59:59') {
        if (strtotime($row['expires_at']) < time()) {
            return ($format==='json')
                ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'라이선스가 만료되었습니다.'))
                : $text(false, '라이선스가 만료되었습니다.');
        }
    }

    if (!$is_unlimited_or_pos($row['daily_max_activations'])) {
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'일일 최대 활성화 수가 0입니다.'))
            : $text(false, '일일 최대 활성화 수가 0입니다.');
    }
    if (!$is_unlimited_or_pos($row['max_activations'])) {
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'최대 활성화 수가 0입니다.'))
            : $text(false, '최대 활성화 수가 0입니다.');
    }

    // ---- 2) 트랜잭션 + 행잠금 + 요청 빈도 제한(10초), 일요청 50회 제한 ----
    $license_id = $row['id'];
    db_begin();

    $locked = db_one("
        SELECT id, status,
               max_activations, daily_max_activations,
               total_activations, daily_activations, daily_activations_date,
               expires_at,
               last_request_at, request_count_today, request_count_date
          FROM license_keys
         WHERE id = ?
         FOR UPDATE
    ", 's', array($license_id));

    if (!$locked) { db_rollback();
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'라이선스가 존재하지 않습니다.'))
            : $text(false, '라이선스가 존재하지 않습니다.');
    }

    if ($locked['status'] !== 'ACTIVE') { db_rollback();
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'라이선스 상태가 사용(ACTIVE)이 아닙니다.'))
            : $text(false, '라이선스 상태가 사용(ACTIVE)이 아닙니다.');
    }

    // 만료 재확인
    if (!empty($locked['expires_at']) && $locked['expires_at'] !== '9999-12-31 23:59:59') {
        if (strtotime($locked['expires_at']) < time()) {
            db_rollback();
            return ($format==='json')
                ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'라이선스가 만료되었습니다.'))
                : $text(false, '라이선스가 만료되었습니다.');
        }
    }

    // 10초 중복요청 차단
    $now = time();
    if (!empty($locked['last_request_at'])) {
        $last = strtotime($locked['last_request_at']);
        if ($last && ($now - $last) < 1) {
            db_rollback();
            return ($format==='json')
                ? json_encode(array('allowed'=>false,'message'=>'잠시 후 다시 시도해 주세요.','reason'=>'1초 내 중복 요청'))
                : '잠시 후 다시 시도해 주세요.';
        }
    }

    // 하루 50회 요청 제한
    $today = date('Y-m-d');
    $req_date = $locked['request_count_date'];
    $req_cnt  = (int)$locked['request_count_today'];
    if ($req_date !== $today) { $req_cnt = 0; }

    if ($req_cnt >= 50) {
        db_rollback();
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'오늘은 더이상 이용할 수 없습니다.','reason'=>'일 요청 한도 초과'))
            : '오늘은 더이상 이용할 수 없습니다.';
    }

    // 활성화 한도 체크 (무한이 아니면 비교)
    $max_total = $locked['max_activations'];
    $max_daily = $locked['daily_max_activations'];
    $cnt_total = (int)$locked['total_activations'];
    $cnt_daily = (int)$locked['daily_activations'];
    $cnt_date  = $locked['daily_activations_date'];

    if ($cnt_date !== $today) {
        db_exec("UPDATE license_keys
                    SET daily_activations=0, daily_activations_date=?
                  WHERE id=?", 'ss', array($today, $license_id));
        $cnt_daily = 0;
        $cnt_date  = $today;
    }

    if ($max_daily !== null && $max_daily !== '' && $cnt_daily >= (int)$max_daily) {
        db_rollback();
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'일일 최대 활성화 수 초과'))
            : $text(false, '일일 최대 활성화 수 초과');
    }
    if ($max_total !== null && $max_total !== '' && $cnt_total >= (int)$max_total) {
        db_rollback();
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'접근이 불가합니다.','reason'=>'총 최대 활성화 수 초과'))
            : $text(false, '총 최대 활성화 수 초과');
    }

    // 카운트 증가 & 요청 카운트/시간 갱신
    db_exec("UPDATE license_keys
                SET total_activations = total_activations + 1,
                    daily_activations = daily_activations + 1,
                    last_request_at = NOW(),
                    request_count_today = ?,
                    request_count_date  = ?
              WHERE id = ?", 'iss', array($req_cnt+1, $today, $license_id));

    db_commit();

    // 성공
    return ($format==='json')
        ? json_encode(array('allowed'=>true,'message'=>$msg_allowed))
        : $msg_allowed;
}

/* -------- 여기 아래는 '직접 URL로 호출'했을 때만 실행 -------- */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $category = isset($_GET['category']) ? trim($_GET['category']) : '';
    $product  = isset($_GET['product'])  ? trim($_GET['product'])  : '';
    $key      = isset($_GET['key'])      ? strtoupper(trim($_GET['key'])) : '';
    $format   = isset($_GET['format'])   ? strtolower(trim($_GET['format'])) : 'text';

    $out = check_access_inline($category, $product, $key, $format);
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
    } else {
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $out;
    exit;
}
