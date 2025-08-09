<?php
// 파일: /scrapping/admin/licenses.php
require_once __DIR__.'/auth.php';
require_login();
$me = current_admin();

require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

/* ------------------------------
   공통 헬퍼 (PHP 5.3 호환)
------------------------------ */
function q($k, $d) { return isset($_REQUEST[$k]) ? trim($_REQUEST[$k]) : $d; }
function icon_dir($col, $sort, $dir){
    if ($col !== $sort) return '';
    return ($dir === 'ASC') ? '↑' : '↓';
}
function next_dir($dir){ return ($dir === 'ASC') ? 'DESC' : 'ASC'; }

/* ------------------------------
   라우팅
------------------------------ */
$action = q('action', 'list');

/* ------------------------------
   카운트 초기화
------------------------------ */
if ($action === 'reset_counts' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = q('id', '');
    if ($id === '') redirect_with('licenses.php', '잘못된 요청입니다.', 'error');

    db_begin();
    try {
        $which = q('which', 'both'); // total|daily|both
        $today = date('Y-m-d');
        if ($which === 'total') {
            db_exec("UPDATE license_keys SET total_activations=0 WHERE id=?", 's', array($id));
        } else if ($which === 'daily') {
            db_exec("UPDATE license_keys SET daily_activations=0, daily_activations_date=? WHERE id=?", 'ss', array($today, $id));
        } else {
            db_exec("UPDATE license_keys SET total_activations=0, daily_activations=0, daily_activations_date=? WHERE id=?", 'ss', array($today, $id));
        }
        db_commit();
        redirect_with('licenses.php?action=view&id='.$id, '카운트를 초기화했습니다.', 'ok');
    } catch (Exception $e) {
        db_rollback();
        redirect_with('licenses.php?action=view&id='.$id, '초기화 중 오류: '.$e->getMessage(), 'error');
    }
    exit;
}

/* ------------------------------
   카운트 수정
------------------------------ */
if ($action === 'update_counts' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = q('id', '');
    if ($id === '') redirect_with('licenses.php', '잘못된 요청입니다.', 'error');

    $total = q('total_activations', '');
    $daily = q('daily_activations', '');
    $today = date('Y-m-d');

    $total_ok = ($total === '' || ctype_digit($total));
    $daily_ok = ($daily === '' || ctype_digit($daily));
    if (!$total_ok || !$daily_ok) {
        redirect_with('licenses.php?action=view&id='.$id, '총/일일 카운트는 0 이상의 정수여야 합니다.', 'error');
    }

    db_begin();
    try {
        if ($total !== '') db_exec("UPDATE license_keys SET total_activations=? WHERE id=?", 'is', array((int)$total, $id));
        if ($daily !== '') db_exec("UPDATE license_keys SET daily_activations=?, daily_activations_date=? WHERE id=?", 'iss', array((int)$daily, $today, $id));
        db_commit();
        redirect_with('licenses.php?action=view&id='.$id, '카운트를 수정했습니다.', 'ok');
    } catch (Exception $e) {
        db_rollback();
        redirect_with('licenses.php?action=view&id='.$id, '수정 중 오류: '.$e->getMessage(), 'error');
    }
    exit;
}

/* ------------------------------
   상태 변경: 폐기
------------------------------ */
if ($action === 'revoke' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = q('id', '');
    if ($id === '') redirect_with('licenses.php', '잘못된 요청입니다.', 'error');

    db_begin();
    try {
        db_exec("UPDATE license_keys SET status='REVOKED', revoked_at=NOW() WHERE id=? AND status<>'REVOKED'", 's', array($id));
        db_commit();
        redirect_with('licenses.php?action=view&id='.$id, '라이선스를 폐기했습니다.', 'ok');
    } catch (Exception $e) {
        db_rollback();
        redirect_with('licenses.php?action=view&id='.$id, '폐기 중 오류: '.$e->getMessage(), 'error');
    }
    exit;
}

/* ------------------------------
   상태 변경: 복원
------------------------------ */
if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = q('id', '');
    if ($id === '') redirect_with('licenses.php', '잘못된 요청입니다.', 'error');

    db_begin();
    try {
        db_exec("UPDATE license_keys SET status='ACTIVE', revoked_at=NULL WHERE id=? AND status='REVOKED'", 's', array($id));
        db_commit();
        redirect_with('licenses.php?action=view&id='.$id, '라이선스를 복원했습니다.', 'ok');
    } catch (Exception $e) {
        db_rollback();
        redirect_with('licenses.php?action=view&id='.$id, '복원 중 오류: '.$e->getMessage(), 'error');
    }
    exit;
}

/* ------------------------------
   라이선스 정보 저장(키/발급일 제외)
------------------------------ */
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id            = q('id', '');
    $category_id   = q('category_id', '');
    $product_id    = q('product_id', '');
    $customer_id   = q('customer_id', '');
    $status        = q('status', '');
    $expires_at_in = q('expires_at', '');
    $max_total_in  = q('max_activations', '');
    $max_daily_in  = q('daily_max_activations', '');
    $notes         = q('notes', '');

    if ($id==='') redirect_with('licenses.php', '잘못된 요청입니다.', 'error');
    if ($category_id==='' || $product_id==='' || $customer_id==='' || $status==='') {
        redirect_with('licenses.php?action=view&id='.$id, '필수 항목이 누락되었습니다.', 'error');
    }

    // 만료일: 비었으면 9999-12-31 23:59:59
    $expires_at = null;
    if ($expires_at_in === '') {
        $expires_at = '9999-12-31 23:59:59';
    } else if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires_at_in)) {
        $expires_at = $expires_at_in.' 23:59:59';
    } else {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $expires_at_in)) {
            redirect_with('licenses.php?action=view&id='.$id, '만료일 형식이 잘못되었습니다. (예: 2029-12-31 또는 2029-12-31 23:59:59)', 'error');
        }
        $expires_at = $expires_at_in;
    }

    // 최대치: 빈값이면 NULL(무한)
    $max_total = ($max_total_in === '') ? null : (int)$max_total_in;
    $max_daily = ($max_daily_in === '') ? null : (int)$max_daily_in;
    if ($max_total !== null && $max_total < 0) $max_total = 0;
    if ($max_daily !== null && $max_daily < 0) $max_daily = 0;

    // 동일 카테고리+상품+고객 조합 중복 방지
    $dup = db_one("
        SELECT id FROM license_keys
         WHERE category_id=? AND product_id=? AND customer_id=? AND id<>?
         LIMIT 1
    ", 'ssss', array($category_id, $product_id, $customer_id, $id));
    if ($dup) {
        redirect_with('licenses.php?action=view&id='.$id, '동일한 카테고리/상품/고객 조합의 라이선스가 이미 존재합니다.', 'error');
    }

    // 상태 변경에 따른 revoked_at 보정
    $set_revoked = false; $clear_revoked = false;
    $current = db_one("SELECT status FROM license_keys WHERE id=? LIMIT 1", 's', array($id));
    if ($current) {
        if ($status === 'REVOKED' && $current['status'] !== 'REVOKED') $set_revoked = true;
        if ($status !== 'REVOKED' && $current['status'] === 'REVOKED') $clear_revoked = true;
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
        $types = 'ssss';
        $params = array($category_id, $product_id, $customer_id, $status);
        $types .= 's';   $params[] = $expires_at;
        if ($max_total!==null) { $types.='i'; $params[] = $max_total; }
        if ($max_daily!==null) { $types.='i'; $params[] = $max_daily; }
        $types .= 's';   $params[] = $notes;
        $types .= 's';   $params[] = $id;

        db_exec($sql, $types, $params);

        if ($set_revoked)  db_exec("UPDATE license_keys SET revoked_at=NOW() WHERE id=?",  's', array($id));
        if ($clear_revoked) db_exec("UPDATE license_keys SET revoked_at=NULL WHERE id=?", 's', array($id));

        db_commit();
        redirect_with('licenses.php?action=view&id='.$id, '라이선스 정보를 저장했습니다.', 'ok');
    } catch (Exception $e) {
        db_rollback();
        redirect_with('licenses.php?action=view&id='.$id, '저장 중 오류: '.$e->getMessage(), 'error');
    }
    exit;
}


/* =========================================================
   신규 발급 (키 자동생성 + 폼값 유지)
========================================================= */
if ($action === 'new') {

    // 랜덤 10자리(A-Z0-9)
    function _gen_key10() {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $out = '';
        for ($i=0; $i<10; $i++) {
            $out .= $chars[mt_rand(0, strlen($chars)-1)];
        }
        return $out;
    }
    // 중복되지 않는 키 생성 (최대 50회 시도)
    function _generate_unique_key() {
        for ($i=0; $i<50; $i++) {
            $k = _gen_key10();
            $dup = db_one("SELECT id FROM license_keys WHERE `key`=? LIMIT 1", 's', array($k));
            if (!$dup) return $k;
        }
        return false;
    }

    // 선택지
    $categories = db_all("SELECT id,name FROM categories ORDER BY name ASC");
    $products   = db_all("SELECT id,code,category_id FROM products ORDER BY code ASC");
    $customers  = db_all("SELECT id,name,category_id FROM customers ORDER BY name ASC");

    // 폼 상태
    $form = array(
        'category_id' => '',
        'product_id'  => '',
        'customer_id' => '',
        'key'         => '',
        'status'      => 'ACTIVE',
        'expires_at'  => '',
        'max_activations'       => '',
        'daily_max_activations' => '',
        'notes'       => ''
    );
    $error_msg = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 값 수집 (유지용)
        $form['category_id'] = isset($_POST['category_id']) ? trim($_POST['category_id']) : '';
        $form['product_id']  = isset($_POST['product_id'])  ? trim($_POST['product_id'])  : '';
        $form['customer_id'] = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';
        $form['key']         = isset($_POST['key'])         ? strtoupper(trim($_POST['key'])) : '';
        $form['status']      = isset($_POST['status'])      ? trim($_POST['status']) : 'ACTIVE';
        $form['expires_at']  = isset($_POST['expires_at'])  ? trim($_POST['expires_at']) : '';
        $form['max_activations']       = isset($_POST['max_activations']) ? trim($_POST['max_activations']) : '';
        $form['daily_max_activations'] = isset($_POST['daily_max_activations']) ? trim($_POST['daily_max_activations']) : '';
        $form['notes']       = isset($_POST['notes']) ? trim($_POST['notes']) : '';

        // 필수값(키는 비워도 됨: 자동생성)
        $errs = array();
        if ($form['category_id']==='') $errs[]='카테고리';
        if ($form['product_id']==='')  $errs[]='상품';
        if ($form['customer_id']==='') $errs[]='고객';

        // 키 처리
        if ($form['key'] === '') {
            $gen = _generate_unique_key();
            if ($gen === false) $errs[]='라이선스 키 자동생성 실패(재시도 필요)';
            else $form['key'] = $gen;
        } else {
            if (!preg_match('/^[A-Z0-9]{10}$/', $form['key'])) {
                $errs[]='라이선스 키(대문자/숫자 10자리 형식)';
            }
        }

        if (!empty($errs)) {
            $error_msg = '필수 항목 누락/형식 오류: '.implode(', ', $errs);
        } else {
            // 만료일: 비우면 9999-12-31 23:59:59
            if ($form['expires_at']==='') {
                $expires_at = '9999-12-31 23:59:59';
            } else if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['expires_at'])) {
                $expires_at = $form['expires_at'].' 23:59:59';
            } else {
                $expires_at = $form['expires_at'];
            }

            // 최대치: 빈값이면 NULL
            $max_total = ($form['max_activations']==='' ? null : (int)$form['max_activations']);
            $max_daily = ($form['daily_max_activations']==='' ? null : (int)$form['daily_max_activations']);
            if ($max_total!==null && $max_total<0) $max_total=0;
            if ($max_daily!==null && $max_daily<0) $max_daily=0;

            // 조합 중복
            $dup = db_one(
                "SELECT id FROM license_keys WHERE category_id=? AND product_id=? AND customer_id=? LIMIT 1",
                'sss',
                array($form['category_id'], $form['product_id'], $form['customer_id'])
            );
            if ($dup) {
                $error_msg = '동일한 카테고리/상품/고객 조합이 이미 존재합니다.';
            } else {
                // 키 중복(이중확인)
                $dupKey = db_one("SELECT id FROM license_keys WHERE `key`=? LIMIT 1", 's', array($form['key']));
                if ($dupKey) {
                    $gen2 = _generate_unique_key();
                    if ($gen2 === false) {
                        $error_msg = '라이선스 키 자동생성 중 충돌 발생. 다시 시도해 주세요.';
                    } else {
                        $form['key'] = $gen2;
                    }
                }

                if ($error_msg === '') {
                    $id = uuid_v4();
                    $creator = current_admin();
                    $creator_id = $creator ? $creator['id'] : null;

                    db_begin();
                    try {
                        $sql = "INSERT INTO license_keys
                                   (id, `key`, status, product_id, customer_id, category_id,
                                    issued_at, expires_at, notes,
                                    max_activations, daily_max_activations,
                                    total_activations, daily_activations, daily_activations_date,
                                    created_by_admin_id)
                                VALUES
                                   (?, ?, ?, ?, ?, ?,
                                    NOW(), ?, ?,
                                    ".($max_total===null?'NULL':'?').", ".($max_daily===null?'NULL':'?').",
                                    0, 0, CURDATE(),
                                    ?)";
                        // id, key, status, product_id, customer_id, category_id, expires_at, notes  = 8
                        $types  = 'ssssssss';
                        $params = array(
                            $id, $form['key'], $form['status'],
                            $form['product_id'], $form['customer_id'], $form['category_id'],
                            $expires_at, $form['notes']
                        );
                        if ($max_total!==null) { $types.='i'; $params[]=$max_total; }
                        if ($max_daily!==null) { $types.='i'; $params[]=$max_daily; }
                        $types.='s'; $params[] = $creator_id; // created_by_admin_id

                        db_exec($sql, $types, $params);
                        db_commit();
                        redirect_with('licenses.php?action=view&id='.$id, '라이선스를 발급했습니다.', 'ok');
                    } catch (Exception $e) {
                        db_rollback();
                        $error_msg = '발급 중 오류: '.$e->getMessage();
                    }
                }
            }
        }
    }

    // 렌더
    render_header('라이선스 발급', $me);
    if ($error_msg!=='') {
        echo '<div class="error" style="margin:10px 0;">'.h($error_msg).'</div>';
    } else {
        if (function_exists('flash_render')) { flash_render(); }
    }
    ?>
    <h2>라이선스 발급</h2>
    <p><a class="btn" href="licenses.php">← 목록</a></p>

    <form method="post" action="licenses.php?action=new" class="grid" id="newForm" onsubmit="return confirm('발급할까요?');">
      <div class="row">
        <label>카테고리 <span class="error">*</span></label>
        <select name="category_id" id="n_category">
          <option value="">-- 선택 --</option>
          <?php foreach($categories as $c): ?>
            <option value="<?php echo h($c['id']); ?>" <?php echo ($form['category_id']===$c['id']?'selected="selected"':''); ?>>
              <?php echo h($c['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row">
        <label>상품 <span class="error">*</span></label>
        <select name="product_id" id="n_product"></select>
      </div>

      <div class="row">
        <label>고객 <span class="error">*</span></label>
        <select name="customer_id" id="n_customer"></select>
      </div>

      <div class="row">
        <label>라이선스 키(대문자/숫자 10자리) <span class="muted">(비우면 자동생성)</span></label>
        <input type="text" name="key" maxlength="10" value="<?php echo h($form['key']); ?>" placeholder="예: ABC123DEF4" />
      </div>

      <div class="row">
        <label>상태</label>
        <select name="status">
          <option value="ACTIVE"  <?php echo ($form['status']==='ACTIVE' ? 'selected="selected"':''); ?>>사용</option>
          <option value="ISSUED"  <?php echo ($form['status']==='ISSUED' ? 'selected="selected"':''); ?>>미사용</option>
          <option value="REVOKED" <?php echo ($form['status']==='REVOKED'? 'selected="selected"':''); ?>>폐기</option>
          <option value="EXPIRED" <?php echo ($form['status']==='EXPIRED'? 'selected="selected"':''); ?>>만료</option>
        </select>
      </div>

      <div class="row">
        <label>만료일</label>
        <input type="text" name="expires_at" id="expires_at_new" value="<?php echo h($form['expires_at']); ?>" placeholder="비우면 9999-12-31 23:59:59" />
      </div>

      <div class="row">
        <label>총 최대 활성화</label>
        <input type="text" name="max_activations" value="<?php echo h($form['max_activations']); ?>" placeholder="비우면 무한" />
      </div>

      <div class="row">
        <label>일일 최대 활성화</label>
        <input type="text" name="daily_max_activations" value="<?php echo h($form['daily_max_activations']); ?>" placeholder="비우면 무한" />
      </div>

      <div class="row" style="grid-column:1 / span 2">
        <label>메모</label>
        <textarea name="notes" rows="3"><?php echo h($form['notes']); ?></textarea>
      </div>

      <div class="row">
        <button class="btn primary" type="submit">발급</button>
      </div>
    </form>

    <script>
    (function(){
      var PRODUCTS  = <?php echo json_encode($products); ?>;
      var CUSTOMERS = <?php echo json_encode($customers); ?>;
      var CUR_CAT   = <?php echo json_encode($form['category_id']); ?>;
      var CUR_PROD  = <?php echo json_encode($form['product_id']); ?>;
      var CUR_CUST  = <?php echo json_encode($form['customer_id']); ?>;

      function filterByCat(items, catId, keyCat){
        var out = [];
        for (var i=0;i<items.length;i++){
          if (items[i][keyCat] === catId) out.push(items[i]);
        }
        return out;
      }
      function fill(sel, list, valueKey, textKey, cur){
        while(sel.options.length) sel.remove(0);
        for (var i=0;i<list.length;i++){
          var o = document.createElement('option');
          o.value = list[i][valueKey];
          o.text = list[i][textKey];
          if (cur && cur === list[i][valueKey]) o.selected = true;
          sel.appendChild(o);
        }
      }
      var catSel = document.getElementById('n_category');
      var pSel = document.getElementById('n_product');
      var cSel = document.getElementById('n_customer');

      function refresh(){
        var cat = catSel.value || null;
        var ps = cat ? filterByCat(PRODUCTS, cat, 'category_id') : [];
        var cs = cat ? filterByCat(CUSTOMERS, cat, 'category_id') : [];
        fill(pSel, ps, 'id', 'code', CUR_PROD);
        fill(cSel, cs, 'id', 'name', CUR_CUST);
      }
      catSel.onchange = function(){
        CUR_PROD = null; CUR_CUST = null;
        refresh();
      };

      if (CUR_CAT) catSel.value = CUR_CAT;
      refresh();
    })();
    </script>
    <?php
    render_footer();
    exit;
}


/* ------------------------------
   상세 보기
------------------------------ */
if ($action === 'view') {
    $id = q('id', '');
    $r = db_one("
        SELECT lk.*,
               c.name AS category_name,
               p.code AS product_code,
               cu.name AS customer_name
          FROM license_keys lk
     LEFT JOIN categories c ON c.id=lk.category_id
     LEFT JOIN products   p ON p.id=lk.product_id
     LEFT JOIN customers cu ON cu.id=lk.customer_id
         WHERE lk.id=?
         LIMIT 1
    ", 's', array($id));

    if (!$r) redirect_with('licenses.php', '해당 라이선스가 없습니다.', 'error');

    $categories  = db_all("SELECT id, name FROM categories ORDER BY name ASC");
    $all_products  = db_all("SELECT id, code, category_id FROM products ORDER BY code ASC");
    $all_customers = db_all("SELECT id, name, category_id FROM customers ORDER BY name ASC");

    render_header('라이선스 보기', $me);
    if (function_exists('flash_render')) { flash_render(); } ?>
    <h2>라이선스 보기</h2>
    <p><a class="btn" href="licenses.php">← 목록</a></p>

    <table class="table">
      <tr><th>라이선스 키</th><td><code><?php echo h($r['key']); ?></code> <span class="muted">※ 수정 불가</span></td></tr>
      <tr><th>발급일</th><td><?php echo h($r['issued_at']); ?> <span class="muted">※ 수정 불가</span></td></tr>
      <tr><th>총 접근 카운트</th><td><?php echo (int)$r['total_activations']; ?></td></tr>
      <tr><th>일일 접근 카운트</th><td><?php echo (int)$r['daily_activations']; ?> (기준일: <?php echo h($r['daily_activations_date']); ?>)</td></tr>
    </table>

    <h3>상태 변경</h3>
    <div class="row">
      <?php if ($r['status'] === 'REVOKED'): ?>
        <form method="post" action="licenses.php" onsubmit="return confirm('이 라이선스를 복원하시겠습니까?');" class="inline">
          <input type="hidden" name="action" value="restore"/>
          <input type="hidden" name="id" value="<?php echo h($r['id']); ?>"/>
          <button class="btn primary" type="submit">복원</button>
        </form>
      <?php else: ?>
        <form method="post" action="licenses.php" onsubmit="return confirm('이 라이선스를 폐기하시겠습니까?');" class="inline">
          <input type="hidden" name="action" value="revoke"/>
          <input type="hidden" name="id" value="<?php echo h($r['id']); ?>"/>
          <button class="btn" type="submit">폐기</button>
        </form>
      <?php endif; ?>
    </div>

    <h3 style="margin-top:15px">카운트 초기화</h3>
    <form method="post" action="licenses.php" onsubmit="return confirm('정말 초기화할까요?');">
      <input type="hidden" name="action" value="reset_counts"/>
      <input type="hidden" name="id" value="<?php echo h($r['id']); ?>"/>
      <label><input type="radio" name="which" value="total" /> 총 카운트만 0</label>
      <label><input type="radio" name="which" value="daily" /> 일일 카운트만 0</label>
      <label><input type="radio" name="which" value="both" checked="checked" /> 둘 다 0</label>
      <button class="btn" type="submit">초기화</button>
    </form>

    <h3 style="margin-top:15px">카운트 값 직접 수정</h3>
    <form method="post" action="licenses.php" class="grid" onsubmit="return confirm('저장할까요?');">
      <input type="hidden" name="action" value="update_counts"/>
      <input type="hidden" name="id" value="<?php echo h($r['id']); ?>"/>
      <div class="row">
        <label>총 접근 카운트</label>
        <input type="text" name="total_activations" value="<?php echo (int)$r['total_activations']; ?>" />
      </div>
      <div class="row">
        <label>일일 접근 카운트</label>
        <input type="text" name="daily_activations" value="<?php echo (int)$r['daily_activations']; ?>" />
        <div class="muted">※ 일일 카운트를 수정하면 기준일을 오늘 날짜로 갱신합니다.</div>
      </div>
      <div class="row">
        <button class="btn primary" type="submit">저장</button>
      </div>
    </form>

    <h3 style="margin-top:15px">라이선스 정보 수정 (키/발급일 제외)</h3>
    <form method="post" action="licenses.php" id="editForm" class="grid" onsubmit="return confirm('라이선스 정보를 저장할까요?');">
      <input type="hidden" name="action" value="save"/>
      <input type="hidden" name="id" value="<?php echo h($r['id']); ?>"/>

      <div class="row">
        <label>카테고리</label>
        <select name="category_id" id="f_category">
          <?php foreach ($categories as $c): ?>
            <option value="<?php echo h($c['id']); ?>" <?php echo ($r['category_id']===$c['id']?'selected="selected"':''); ?>>
              <?php echo h($c['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row">
        <label>상품</label>
        <select name="product_id" id="f_product"></select>
      </div>

      <div class="row">
        <label>고객</label>
        <select name="customer_id" id="f_customer"></select>
      </div>

      <div class="row">
        <label>상태</label>
        <select name="status">
          <option value="ACTIVE"  <?php echo ($r['status']==='ACTIVE' ? 'selected="selected"':''); ?>>사용</option>
          <option value="ISSUED"  <?php echo ($r['status']==='ISSUED' ? 'selected="selected"':''); ?>>미사용</option>
          <option value="REVOKED" <?php echo ($r['status']==='REVOKED'? 'selected="selected"':''); ?>>폐기</option>
          <option value="EXPIRED" <?php echo ($r['status']==='EXPIRED'? 'selected="selected"':''); ?>>만료</option>
        </select>
      </div>

      <div class="row">
        <label>만료일</label>
        <input type="text" name="expires_at" id="expires_at" value="<?php echo $r['expires_at'] ? h($r['expires_at']) : ''; ?>" placeholder="예: 2029-12-31 또는 2029-12-31 23:59:59" />
        <div class="muted">비워두면 저장 시 9999-12-31 23:59:59로 설정됩니다.</div>
      </div>

      <div class="row">
        <label>총 최대 활성화</label>
        <input type="text" name="max_activations" value="<?php echo ($r['max_activations']===null?'':(int)$r['max_activations']); ?>" />
        <div class="muted">비워두면 무한</div>
      </div>

      <div class="row">
        <label>일일 최대 활성화</label>
        <input type="text" name="daily_max_activations" value="<?php echo ($r['daily_max_activations']===null?'':(int)$r['daily_max_activations']); ?>" />
        <div class="muted">비워두면 무한</div>
      </div>

      <div class="row" style="grid-column:1 / span 2">
        <label>메모</label>
        <textarea name="notes" rows="4"><?php echo h($r['notes']); ?></textarea>
      </div>

      <div class="row">
        <button class="btn primary" type="submit">저장</button>
      </div>
    </form>

    <script>
    (function(){
      var PRODUCTS_BY_CAT = {};
      var CUSTOMERS_BY_CAT = {};
      <?php
        $pmap = array();
        foreach ($all_products as $p) {
            if (!isset($pmap[$p['category_id']])) $pmap[$p['category_id']] = array();
            $pmap[$p['category_id']][] = array('id'=>$p['id'], 'code'=>$p['code']);
        }
        $cmap = array();
        foreach ($all_customers as $cu) {
            if (!isset($cmap[$cu['category_id']])) $cmap[$cu['category_id']] = array();
            $cmap[$cu['category_id']][] = array('id'=>$cu['id'], 'name'=>$cu['name']);
        }
        echo "PRODUCTS_BY_CAT = ".json_encode($pmap).";\n";
        echo "CUSTOMERS_BY_CAT = ".json_encode($cmap).";\n";
        echo "var CUR_CAT = ".json_encode($r['category_id']).";\n";
        echo "var CUR_PROD = ".json_encode($r['product_id']).";\n";
        echo "var CUR_CUST = ".json_encode($r['customer_id']).";\n";
      ?>

      function fillSelect(sel, items, idKey, textKey, current){
        while (sel.options.length) sel.remove(0);
        for (var i=0;i<items.length;i++){
          var opt = document.createElement('option');
          opt.value = items[i][idKey];
          opt.text = items[i][textKey];
          if (current && current === items[i][idKey]) opt.selected = true;
          sel.appendChild(opt);
        }
      }

      function refreshLists(){
        var cat = document.getElementById('f_category').value;
        var pSel = document.getElementById('f_product');
        var cSel = document.getElementById('f_customer');
        var prods = PRODUCTS_BY_CAT[cat] || [];
        var custs = CUSTOMERS_BY_CAT[cat] || [];
        fillSelect(pSel, prods, 'id', 'code', CUR_PROD);
        fillSelect(cSel, custs, 'id', 'name', CUR_CUST);
      }

      document.getElementById('f_category').onchange = function(){
        CUR_PROD = null; CUR_CUST = null;
        refreshLists();
      };

      refreshLists();
    })();
    </script>

    <?php render_footer(); exit;
}

/* ------------------------------
   목록
------------------------------ */
$category_id = q('category_id', '');
$product_id  = q('product_id',  '');
$customer_id = q('customer_id', '');
$status      = q('status',      '');

$sort = q('sort', 'issued_at');  // 기본: 등록일
$dir  = q('dir',  'DESC');
$allow_cols = array('issued_at','status','key','product_code','customer_name','total_activations','daily_activations','max_activations','daily_max_activations');
if (!in_array($sort, $allow_cols)) $sort = 'issued_at';
$dir = ($dir==='ASC'?'ASC':'DESC');

$categories = db_all("SELECT id, name FROM categories ORDER BY name ASC");
if ($category_id !== '') {
    $products  = db_all("SELECT id, code FROM products WHERE category_id=? ORDER BY code ASC", 's', array($category_id));
    $customers = db_all("SELECT id, name FROM customers WHERE category_id=? ORDER BY name ASC", 's', array($category_id));
} else {
    $products  = db_all("SELECT id, code FROM products ORDER BY code ASC");
    $customers = db_all("SELECT id, name FROM customers ORDER BY name ASC");
}

$where = array();
$params = array();
$types  = '';

if ($category_id !== '') { $where[] = 'lk.category_id=?'; $types .= 's'; $params[] = $category_id; }
if ($product_id  !== '') { $where[] = 'lk.product_id=?';  $types .= 's'; $params[] = $product_id; }
if ($customer_id !== '') { $where[] = 'lk.customer_id=?'; $types .= 's'; $params[] = $customer_id; }
if ($status      !== '') { $where[] = 'lk.status=?';      $types .= 's'; $params[] = $status; }

$where_sql = count($where) ? ('WHERE '.implode(' AND ', $where)) : '';

$sql = "
SELECT lk.id, lk.`key`, lk.status, lk.issued_at, lk.expires_at,
       lk.total_activations, lk.daily_activations, lk.daily_activations_date,
       lk.max_activations, lk.daily_max_activations,
       c.name AS category_name,
       p.code AS product_code,
       cu.name AS customer_name
  FROM license_keys lk
  LEFT JOIN categories c ON c.id=lk.category_id
  LEFT JOIN products   p ON p.id=lk.product_id
  LEFT JOIN customers cu ON cu.id=lk.customer_id
  $where_sql
  ORDER BY $sort $dir, lk.id DESC
  LIMIT 200
";
$rows = ($types==='')
    ? db_all($sql)
    : db_all($sql, $types, $params);

render_header('라이선스', $me);
if (function_exists('flash_render')) { flash_render(); } ?>
<h2>라이선스</h2>

<div class="row" style="margin:10px 0">
  <a class="btn primary" href="licenses.php?action=new">+ 라이선스 발급</a>
</div>

<div class="row">
  <form id="filterForm" method="get" action="licenses.php" class="grid">
    <div>
      <label>카테고리</label>
      <select name="category_id" id="f_category_list" onchange="document.getElementById('filterForm').submit();">
        <option value="">-- 전체 --</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?php echo h($c['id']); ?>" <?php echo ($category_id===$c['id']?'selected="selected"':''); ?>>
            <?php echo h($c['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>상품</label>
      <select name="product_id" id="f_product_list" onchange="document.getElementById('filterForm').submit();">
        <option value="">-- 전체 --</option>
        <?php foreach ($products as $p): ?>
          <option value="<?php echo h($p['id']); ?>" <?php echo ($product_id===$p['id']?'selected="selected"':''); ?>>
            <?php echo h($p['code']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>고객</label>
      <select name="customer_id" id="f_customer_list" onchange="document.getElementById('filterForm').submit();">
        <option value="">-- 전체 --</option>
        <?php foreach ($customers as $cu): ?>
          <option value="<?php echo h($cu['id']); ?>" <?php echo ($customer_id===$cu['id']?'selected="selected"':''); ?>>
            <?php echo h($cu['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>상태</label>
      <select name="status" onchange="document.getElementById('filterForm').submit();">
        <option value="">-- 전체 --</option>
        <option value="ACTIVE"   <?php echo ($status==='ACTIVE'  ?'selected="selected"':''); ?>>사용</option>
        <option value="ISSUED"   <?php echo ($status==='ISSUED'  ?'selected="selected"':''); ?>>미사용</option>
        <option value="REVOKED"  <?php echo ($status==='REVOKED' ?'selected="selected"':''); ?>>폐기</option>
        <option value="EXPIRED"  <?php echo ($status==='EXPIRED' ?'selected="selected"':''); ?>>만료</option>
      </select>
    </div>
    <div style="align-self:end">
      <a class="btn" href="licenses.php">필터 초기화</a>
    </div>
  </form>
</div>

<div class="table-wrap">
<table class="table">
  <tr>
    <th><a href="?<?php echo h(http_build_query(array_merge($_GET, array('sort'=>'issued_at','dir'=>($_GET && isset($_GET['dir'])?next_dir($dir):$dir))))); ?>">등록일 <?php echo icon_dir('issued_at',$sort,$dir); ?></a></th>
    <th>카테고리</th>
    <th><a href="?<?php echo h(http_build_query(array_merge($_GET, array('sort'=>'product_code','dir'=>($_GET && isset($_GET['dir'])?next_dir($dir):$dir))))); ?>">상품코드 <?php echo icon_dir('product_code',$sort,$dir); ?></a></th>
    <th><a href="?<?php echo h(http_build_query(array_merge($_GET, array('sort'=>'customer_name','dir'=>($_GET && isset($_GET['dir'])?next_dir($dir):$dir))))); ?>">고객명 <?php echo icon_dir('customer_name',$sort,$dir); ?></a></th>
    <th><a href="?<?php echo h(http_build_query(array_merge($_GET, array('sort'=>'key','dir'=>($_GET && isset($_GET['dir'])?next_dir($dir):$dir))))); ?>">라이선스키 <?php echo icon_dir('key',$sort,$dir); ?></a></th>
    <th><a href="?<?php echo h(http_build_query(array_merge($_GET, array('sort'=>'status','dir'=>($_GET && isset($_GET['dir'])?next_dir($dir):$dir))))); ?>">상태 <?php echo icon_dir('status',$sort,$dir); ?></a></th>
    <th><a href="?<?php echo h(http_build_query(array_merge($_GET, array('sort'=>'max_activations','dir'=>($_GET && isset($_GET['dir'])?next_dir($dir):$dir))))); ?>">총 최대 활성화 <?php echo icon_dir('max_activations',$sort,$dir); ?></a></th>
    <th><a href="?<?php echo h(http_build_query(array_merge($_GET, array('sort'=>'daily_max_activations','dir'=>($_GET && isset($_GET['dir'])?next_dir($dir):$dir))))); ?>">일일 최대 활성화 <?php echo icon_dir('daily_max_activations',$sort,$dir); ?></a></th>
    <th><a href="?<?php echo h(http_build_query(array_merge($_GET, array('sort'=>'total_activations','dir'=>($_GET && isset($_GET['dir'])?next_dir($dir):$dir))))); ?>">총 접근 수 <?php echo icon_dir('total_activations',$sort,$dir); ?></a></th>
    <th><a href="?<?php echo h(http_build_query(array_merge($_GET, array('sort'=>'daily_activations','dir'=>($_GET && isset($_GET['dir'])?next_dir($dir):$dir))))); ?>">일일 접근 수 <?php echo icon_dir('daily_activations',$sort,$dir); ?></a></th>
    <th>만료일</th>
    <th>액션</th>
  </tr>
  <?php if ($rows): foreach ($rows as $r): ?>
    <tr>
      <td><?php echo h($r['issued_at']); ?></td>
      <td><?php echo h($r['category_name']); ?></td>
      <td><?php echo h($r['product_code']); ?></td>
      <td><?php echo h($r['customer_name']); ?></td>
      <td><code><?php echo h($r['key']); ?></code></td>
      <td><?php echo ($r['status']==='ACTIVE'?'사용':($r['status']==='ISSUED'?'미사용':($r['status']==='REVOKED'?'폐기':'만료'))); ?></td>
      <td><?php echo ($r['max_activations']===null ? '무한' : (int)$r['max_activations']); ?></td>
      <td><?php echo ($r['daily_max_activations']===null ? '무한' : (int)$r['daily_max_activations']); ?></td>
      <td><?php echo (int)$r['total_activations']; ?></td>
      <td><?php echo (int)$r['daily_activations']; ?></td>
      <td><?php echo $r['expires_at'] ? h($r['expires_at']) : '미지정'; ?></td>
      <td><a class="btn" href="licenses.php?action=view&id=<?php echo h($r['id']); ?>">보기</a></td>
    </tr>
  <?php endforeach; else: ?>
    <tr><td colspan="13" class="muted">데이터가 없습니다.</td></tr>
  <?php endif; ?>
</table>
</div>

<?php render_footer(); ?>
