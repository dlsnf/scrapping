<?php
/**
 * 파일: /scrapping/check_access_ev.php
 * 목적: get_ev_info 전용 접근 체크 (ev_db 사용)
 * 반환: include 모드일 때 문자열("access" 또는 오류 메시지). 직접 호출 시 text/json 모두 지원 가능하도록 최소 구현.
 */

require_once __DIR__ . '/db_ev.php';

if (!function_exists('check_access_inline_ev')) {
/**
 * @return mixed
 */
function check_access_inline_ev($category, $product, $key, $format /* 'text' 또는 'json' */)
{
    $category = trim($category);
    $product  = trim($product);
    $key      = strtoupper(trim($key));
    if ($category==='' || $product==='' || $key==='') {
        return ($format==='json')
            ? json_encode(array('allowed'=>false,'message'=>'필수 파라미터 누락','reason'=>'category/product/key'))
            : '필수 파라미터 누락';
    }

    // 1) 라이선스 조회
    $row = ev_db_one("
        SELECT lk.id, lk.`key`, lk.status, lk.expires_at,
               lk.max_activations, lk.daily_max_activations,
               lk.total_activations, lk.daily_activations, lk.daily_activations_date,
               lk.last_request_at, lk.request_count_today, lk.request_count_date,
               c.name AS category_name, p.code AS product_code
          FROM license_keys lk
     LEFT JOIN categories c ON c.id = lk.category_id
     LEFT JOIN products  p ON p.id = lk.product_id
         WHERE lk.`key` = ?
         LIMIT 1
    ", 's', array($key));

    if (!$row) return ($format==='json') ? json_encode(array('allowed'=>false,'message'=>'라이선스 없음')) : '라이선스 없음';
    if ($row['category_name'] !== $category) return '카테고리 불일치';
    if ($row['product_code']  !== $product)  return '상품코드 불일치';
    if ($row['status'] !== 'ACTIVE')         return '상태 비활성';
    // 만료일 체크
    if (!empty($row['expires_at']) && $row['expires_at'] !== '0000-00-00 00:00:00') {
        if (strtotime($row['expires_at']) < time()) return '라이센스 만료됨';
    }

    // 2) 1초 중복 요청 차단
    $now = time();
    $last = $row['last_request_at'] ? strtotime($row['last_request_at']) : 0;
    if ($last && ($now - $last) < 1) {
        return '잠시 후 다시 시도해 주세요.';
    }

    // 3) 일일 요청 50회 제한
    $today = date('Y-m-d');
    $req_date = $row['request_count_date'];
    $req_cnt  = (int)$row['request_count_today'];
    if ($req_date !== $today) { $req_cnt = 0; }

    if ($req_cnt >= 100) {
        return '오늘은 더이상 이용할 수 없습니다.';
    }

    // 4) 활성화 수 제한 체크 (0은 불가, NULL/빈문자면 무한)
    $max_total = $row['max_activations'];
    $max_daily = $row['daily_max_activations'];
    $cnt_total = (int)$row['total_activations'];
    $cnt_daily = (int)$row['daily_activations'];
    $cnt_date  = $row['daily_activations_date'];

    if ($cnt_date !== $today) $cnt_daily = 0;
    if ($max_daily !== null && $max_daily !== '' && $cnt_daily >= (int)$max_daily) return '일일 최대 활성화 수 초과';
    if ($max_total !== null && $max_total !== '' && $cnt_total >= (int)$max_total) return '총 최대 활성화 수 초과';

    // 5) 카운트/시간 업데이트
    // 날짜 바뀌면 일일 카운트 리셋 + 요청 카운트 리셋
    if ($cnt_date !== $today) {
        ev_db_exec("UPDATE license_keys
                       SET daily_activations=0,
                           daily_activations_date=?,
                           request_count_today=0,
                           request_count_date=?
                     WHERE id=?", 'sss', array($today, $today, $row['id']));
        $cnt_daily = 0; $req_cnt = 0;
    }

    // 증가 (활성화 카운트 + 요청 카운트 + last_request_at)
    ev_db_exec("UPDATE license_keys
                   SET total_activations = total_activations + 1,
                       daily_activations = daily_activations + 1,
                       request_count_today = request_count_today + 1,
                       request_count_date  = ?,
                       last_request_at = NOW()
                 WHERE id = ?", 'ss', array($today, $row['id']));

    // OK
    return 'access';
}}
