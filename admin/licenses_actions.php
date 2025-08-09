<?php
$me = current_admin();
$action = isset($_POST['action']) ? $_POST['action'] : '';

// 카운트 초기화
if ($action === 'reset_counts') {
    $id = isset($_POST['id']) ? trim($_POST['id']) : '';
    if ($id==='') redirect_with('licenses.php','잘못된 요청입니다.','error');
    $which = isset($_POST['which']) ? $_POST['which'] : 'both';
    $today = date('Y-m-d');

    db_begin();
    try {
        if ($which === 'total') {
            db_exec("UPDATE license_keys SET total_activations=0 WHERE id=?", 's', array($id));
        } elseif ($which === 'daily') {
            db_exec("UPDATE license_keys SET daily_activations=0, daily_activations_date=? WHERE id=?", 'ss', array($today,$id));
        } else {
            db_exec("UPDATE license_keys SET total_activations=0, daily_activations=0, daily_activations_date=? WHERE id=?", 'ss', array($today,$id));
        }
        db_commit();
        redirect_with('licenses.php?action=view&id='.$id, '카운트를 초기화했습니다.', 'ok');
    } catch (Exception $e) {
        db_rollback();
        redirect_with('licenses.php?action=view&id='.$id, '초기화 중 오류: '.$e->getMessage(), 'error');
    }
    exit;
}

// 카운트 수정
if ($action === 'update_counts') {
    $id = isset($_POST['id']) ? trim($_POST['id']) : '';
    if ($id==='') redirect_with('licenses.php','잘못된 요청입니다.','error');

    $total = isset($_POST['total_activations']) ? trim($_POST['total_activations']) : '';
    $daily = isset($_POST['daily_activations']) ? trim($_POST['daily_activations']) : '';
    $today = date('Y-m-d');

    $total_ok = ($total === '' || ctype_digit($total));
    $daily_ok = ($daily === '' || ctype_digit($daily));
    if (!$total_ok || !$daily_ok) redirect_with('licenses.php?action=view&id='.$id,'총/일일 카운트는 0 이상의 정수여야 합니다.','error');

    db_begin();
    try {
        if ($total !== '') db_exec("UPDATE license_keys SET total_activations=? WHERE id=?", 'is', array((int)$total,$id));
        if ($daily !== '') db_exec("UPDATE license_keys SET daily_activations=?, daily_activations_date=? WHERE id=?", 'iss', array((int)$daily,$today,$id));
        db_commit();
        redirect_with('licenses.php?action=view&id='.$id,'카운트를 수정했습니다.','ok');
    } catch (Exception $e) {
        db_rollback();
        redirect_with('licenses.php?action=view&id='.$id,'수정 중 오류: '.$e->getMessage(),'error');
    }
    exit;
}

// 폐기
if ($action === 'revoke') {
    $id = isset($_POST['id']) ? trim($_POST['id']) : '';
    if ($id==='') redirect_with('licenses.php','잘못된 요청입니다.','error');
    db_begin();
    try {
        db_exec("UPDATE license_keys SET status='REVOKED', revoked_at=NOW() WHERE id=? AND status<>'REVOKED'", 's', array($id));
        db_commit();
        redirect_with('licenses.php?action=view&id='.$id,'라이선스를 폐기했습니다.','ok');
    } catch (Exception $e) {
        db_rollback();
        redirect_with('licenses.php?action=view&id='.$id,'폐기 중 오류: '.$e->getMessage(),'error');
    }
    exit;
}

// 복원
if ($action === 'restore') {
    $id = isset($_POST['id']) ? trim($_POST['id']) : '';
    if ($id==='') redirect_with('licenses.php','잘못된 요청입니다.','error');
    db_begin();
    try {
        db_exec("UPDATE license_keys SET status='ACTIVE', revoked_at=NULL WHERE id=? AND status='REVOKED'", 's', array($id));
        db_commit();
        redirect_with('licenses.php?action=view&id='.$id,'라이선스를 복원했습니다.','ok');
    } catch (Exception $e) {
        db_rollback();
        redirect_with('licenses.php?action=view&id='.$id,'복원 중 오류: '.$e->getMessage(),'error');
    }
    exit;
}

// 저장(키/발급일 제외)
if ($action === 'save') {
    $id            = isset($_POST['id']) ? trim($_POST['id']) : '';
    $category_id   = isset($_POST['category_id']) ? trim($_POST['category_id']) : '';
    $product_id    = isset($_POST['product_id'])  ? trim($_POST['product_id'])  : '';
    $customer_id   = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';
    $status        = isset($_POST['status'])      ? trim($_POST['status'])      : '';
    $expires_at_in = isset($_POST['expires_at'])  ? trim($_POST['expires_at'])  : '';
    $max_total_in  = isset($_POST['max_activations'])        ? trim($_POST['max_activations'])        : '';
    $max_daily_in  = isset($_POST['daily_max_activations'])  ? trim($_POST['daily_max_activations'])  : '';
    $notes         = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    if ($id==='' || $category_id==='' || $product_id==='' || $customer_id==='' || $status==='') {
        redirect_with('licenses.php?action=view&id='.$id,'필수 항목이 누락되었습니다.','error');
    }

    if ($expires_at_in === '')      $expires_at = '9999-12-31 23:59:59';
    else if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$expires_at_in)) $expires_at = $expires_at_in.' 23:59:59';
    else                            $expires_at = $expires_at_in;

    $max_total = ($max_total_in === '' ? null : (int)$max_total_in);
    $max_daily = ($max_daily_in === '' ? null : (int)$max_daily_in);
    if ($max_total!==null && $max_total<0) $max_total=0;
    if ($max_daily!==null && $max_daily<0) $max_daily=0;

    // 조합 중복 방지
    $dup = db_one("
        SELECT id FROM license_keys
         WHERE category_id=? AND product_id=? AND customer_id=? AND id<>?
         LIMIT 1
    ", 'ssss', array($category_id,$product_id,$customer_id,$id));
    if ($dup) redirect_with('licenses.php?action=view&id='.$id,'동일한 카테고리/상품/고객 조합의 라이선스가 이미 존재합니다.','error');

    // 상태 전환 보정
    $set_revoked=false; $clear_revoked=false;
    $current = db_one("SELECT status FROM license_keys WHERE id=? LIMIT 1", 's', array($id));
    if ($current) {
        if ($status==='REVOKED' && $current['status']!=='REVOKED') $set_revoked=true;
        if ($status!=='REVOKED' && $current['status']==='REVOKED') $clear_revoked=true;
    }

    db_begin();
    try {
        $sql = "UPDATE license_keys
                   SET category_id=?,
                       product_id=?,
                       customer_id=?,
                       status=?,
                       expires_at=?,
                       max_activations=".($max_total===null ? "NULL" : "?").",
                       daily_max_activations=".($max_daily===null ? "NULL" : "?").",
                       notes=?
                 WHERE id=?";
        $types='ssss'; $params=array($category_id,$product_id,$customer_id,$status);
        $types.='s';   $params[]=$expires_at;
        if ($max_total!==null){ $types.='i'; $params[]=$max_total; }
        if ($max_daily!==null){ $types.='i'; $params[]=$max_daily; }
        $types.='s';   $params[]=$notes;
        $types.='s';   $params[]=$id;

        db_exec($sql,$types,$params);

        if ($set_revoked)   db_exec("UPDATE license_keys SET revoked_at=NOW() WHERE id=?", 's', array($id));
        if ($clear_revoked) db_exec("UPDATE license_keys SET revoked_at=NULL WHERE id=?", 's', array($id));

        db_commit();
        redirect_with('licenses.php?action=view&id='.$id,'라이선스 정보를 저장했습니다.','ok');
    } catch (Exception $e) {
        db_rollback();
        redirect_with('licenses.php?action=view&id='.$id,'저장 중 오류: '.$e->getMessage(),'error');
    }
    exit;
}

// 그 외
redirect_with('licenses.php','알 수 없는 요청입니다.','error');
