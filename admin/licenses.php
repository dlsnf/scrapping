<?php
require_once __DIR__.'/auth.php';
require_login();
$me = current_admin();

/* POST일 때만 CSRF 검사 */
csrf_check();

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id     = isset($_GET['id']) ? $_GET['id'] : null;

/* ====== 헬퍼 ====== */
function get_license($id){
    return db_one(
        "SELECT lk.*, c.name AS customer_name, p.name AS product_name
         FROM license_keys lk
         LEFT JOIN customers c ON lk.customer_id=c.id
         LEFT JOIN products p ON lk.product_id=p.id
         WHERE lk.id=?",
        's', array($id)
    );
}
function fetch_products(){
    return db_all("SELECT id, code, name FROM products ORDER BY name ASC");
}
function fetch_customers(){
    return db_all("SELECT id, name FROM customers ORDER BY name ASC");
}

/* ====== 발급(생성) 처리: 필수값 검증 포함 ====== */
if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 수집
    $key_in      = isset($_POST['key']) ? strtoupper(trim($_POST['key'])) : '';
    $product_id  = (isset($_POST['product_id']) && $_POST['product_id'] !== '') ? $_POST['product_id'] : null;
    $customer_id = (isset($_POST['customer_id']) && $_POST['customer_id'] !== '') ? $_POST['customer_id'] : null;
    $expires_at  = (isset($_POST['expires_at']) && $_POST['expires_at'] !== '') ? $_POST['expires_at'] : null;
    $notes       = isset($_POST['notes']) ? $_POST['notes'] : null;
    $meta        = isset($_POST['meta']) ? $_POST['meta'] : null;

    // 검증
    $errors = array();
    if ($product_id === null)  $errors[] = '상품을 선택하세요.';
    if ($customer_id === null) $errors[] = '고객을 선택하세요.';
    if ($key_in !== '' && !preg_match('/^[A-Z0-9]{10}$/', $key_in)) {
        $errors[] = '라이선스 키는 영문 대문자/숫자 10자리여야 합니다.';
    }
    if ($expires_at !== null && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires_at)) {
        $errors[] = '만료일 형식이 올바르지 않습니다. 예) 2025-12-31 23:59:59';
    }

    if (!empty($errors)) {
        // 에러 → 폼 재표시
        $products  = fetch_products();
        $customers = fetch_customers();
        render_header('라이선스 발급', $me);

        echo '<h2>라이선스 발급</h2>';
        echo '<div class="error"><ul>';
        foreach ($errors as $e) echo '<li>'.h($e).'</li>';
        echo '</ul></div>';

        echo '<form method="post" action="licenses.php?action=create">';
        echo '<input type="hidden" name="'.h(CSRF_TOKEN_NAME).'" value="'.h(csrf_token()).'">';

        echo '<div class="grid">';
        echo '<div><label>키(10자리, 비우면 자동):<br><input type="text" name="key" maxlength="10" value="'.h($key_in).'" /></label></div>';
        echo '<div><label>만료일(YYYY-MM-DD HH:MM:SS):<br><input type="text" name="expires_at" value="'.h($expires_at).'" class="js-datetime" /></label></div>';

        echo '<div><label>상품 *:<br><select name="product_id" required><option value="">(선택)</option>';
        foreach ($products as $p) {
            $sel = ($product_id === $p['id']) ? ' selected' : '';
            echo '<option value="'.h($p['id']).'"'.$sel.'>'.h($p['name']).' ['.h($p['code']).']</option>';
        }
        echo '</select></label></div>';

        echo '<div><label>고객 *:<br><select name="customer_id" required><option value="">(선택)</option>';
        foreach ($customers as $c) {
            $sel = ($customer_id === $c['id']) ? ' selected' : '';
            echo '<option value="'.h($c['id']).'"'.$sel.'>'.h($c['name']).'</option>';
        }
        echo '</select></label></div>';
        echo '</div>';

        echo '<div class="row"><label>메모:<br><textarea name="notes" rows="3">'.h($notes).'</textarea></label></div>';
        echo '<div class="row"><label>메타(JSON 대용 평문):<br><textarea name="meta" rows="3">'.h($meta).'</textarea></label></div>';

        echo '<button class="btn primary" type="submit">발급</button> ';
        echo '<a class="btn" href="licenses.php">목록</a>';
        echo '</form>';

        render_footer();
        exit;
    }

    // 생성
    $id         = uuid_v4();
    $status     = 'ISSUED';
    $issued_at  = date('Y-m-d H:i:s');
    $key_final  = ($key_in !== '') ? $key_in : generate_license_key(10);

    db_exec(
        "INSERT INTO license_keys
         (id, `key`, status, product_id, customer_id, issued_at, expires_at, notes, meta, created_by_admin_id)
         VALUES (?,?,?,?,?,?,?,?,?,?)",
        'ssssssssss',
        array($id, $key_final, $status, $product_id, $customer_id, $issued_at, $expires_at, $notes, $meta, $me['id'])
    );

    log_event($id, 'CREATE', $me['id'], $me['username'], $_SERVER['REMOTE_ADDR'], null, json_encode(array('key'=>$key_final)));
    header('Location: licenses.php?action=view&id='.$id);
    exit;
}

/* ====== 수정 저장 ====== */
if ($action==='update' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id          = $_POST['id'];
    $rowBefore   = get_license($id);

    $status      = $_POST['status'];
    $product_id  = ($_POST['product_id'] !== '') ? $_POST['product_id'] : null;
    $customer_id = ($_POST['customer_id'] !== '') ? $_POST['customer_id'] : null;
    $expires_at  = ($_POST['expires_at'] !== '') ? $_POST['expires_at'] : null;
    $notes       = isset($_POST['notes']) ? $_POST['notes'] : null;
    $meta        = isset($_POST['meta']) ? $_POST['meta'] : null;

    $errors = array();
    if ($expires_at && !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expires_at)) {
        $errors[] = '만료일 형식이 올바르지 않습니다. 예) 2025-12-31 23:59:59';
    }
    if (!empty($errors)) {
        render_header('라이선스 수정', $me);
        echo '<h2>라이선스 수정</h2>';
        echo '<div class="error"><ul>';
        foreach ($errors as $e) echo '<li>'.h($e).'</li>';
        echo '</ul></div>';
        echo '<p><a class="btn" href="licenses.php?action=edit&id='.h($id).'">← 뒤로</a></p>';
        render_footer();
        exit;
    }

    $revoked_at = null;
    if ($status === 'REVOKED') $revoked_at = date('Y-m-d H:i:s');

    db_exec(
        "UPDATE license_keys
         SET status=?, product_id=?, customer_id=?, expires_at=?, revoked_at=?, notes=?, meta=?
         WHERE id=?",
        'ssssssss',
        array($status, $product_id, $customer_id, $expires_at, $revoked_at, $notes, $meta, $id)
    );

    log_event($id,'UPDATE',$me['id'],$me['username'],$_SERVER['REMOTE_ADDR'], json_encode($rowBefore), json_encode(get_license($id)));
    header('Location: licenses.php?action=view&id='.$id);
    exit;
}

/* ====== 폐기/복원 ====== */
if ($action==='revoke' && $id) {
    $rowBefore = get_license($id);
    db_exec("UPDATE license_keys SET status='REVOKED', revoked_at=NOW() WHERE id=?", 's', array($id));
    log_event($id,'REVOKE',$me['id'],$me['username'],$_SERVER['REMOTE_ADDR'], json_encode($rowBefore), json_encode(get_license($id)));
    header('Location: licenses.php?action=view&id='.$id);
    exit;
}
if ($action==='restore' && $id) {
    $rowBefore = get_license($id);
    db_exec("UPDATE license_keys SET status='ISSUED', revoked_at=NULL WHERE id=?", 's', array($id));
    log_event($id,'RESTORE',$me['id'],$me['username'],$_SERVER['REMOTE_ADDR'], json_encode($rowBefore), json_encode(get_license($id)));
    header('Location: licenses.php?action=view&id='.$id);
    exit;
}

/* ====== 렌더 ====== */
render_header('라이선스', $me);
$products  = fetch_products();
$customers = fetch_customers();

if ($action==='new'){ ?>
<h2>라이선스 발급</h2>
<form method="post" action="licenses.php?action=create">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <div class="grid">
    <div><label>키(10자리, 비우면 자동):<br>
      <input type="text" name="key" maxlength="10" />
    </label></div>

    <div><label>만료일(YYYY-MM-DD HH:MM:SS):<br>
      <input type="text" name="expires_at" class="js-datetime" />
    </label></div>

    <div><label>상품 *:<br>
      <select name="product_id" required><option value="">(선택)</option>
      <?php foreach($products as $p){ echo '<option value="'.h($p['id']).'">'.h($p['name']).' ['.h($p['code']).']</option>'; } ?>
      </select>
    </label></div>

    <div><label>고객 *:<br>
      <select name="customer_id" required><option value="">(선택)</option>
      <?php foreach($customers as $c){ echo '<option value="'.h($c['id']).'">'.h($c['name']).'</option>'; } ?>
      </select>
    </label></div>
  </div>

  <div class="row"><label>메모:<br><textarea name="notes" rows="3"></textarea></label></div>
  <div class="row"><label>메타(JSON 대용 평문):<br><textarea name="meta" rows="3"></textarea></label></div>

  <button class="btn primary" type="submit">발급</button>
  <a class="btn" href="licenses.php">목록</a>
</form>
<?php
} else if ($action==='view' && $id){
    $r = get_license($id);
    if (!$r){ echo '<p class="error">존재하지 않는 라이선스</p>'; }
    else {
?>
<h2>라이선스 상세</h2>
<p><a class="btn" href="licenses.php">← 목록</a>
<?php if ($r['status']!=='REVOKED'){ ?>
   <a class="btn" href="licenses.php?action=revoke&id=<?php echo h($id); ?>">폐기</a>
<?php } else { ?>
   <a class="btn" href="licenses.php?action=restore&id=<?php echo h($id); ?>">복원</a>
<?php } ?>
   <a class="btn" href="licenses.php?action=edit&id=<?php echo h($id); ?>">수정</a>
   <a class="btn" href="activations.php?license_id=<?php echo h($id); ?>">활성화 기록</a>
</p>
<table class="table">
<tr><th>ID</th><td><?php echo h($r['id']); ?></td></tr>
<tr><th>Key</th><td><code><?php echo h($r['key']); ?></code></td></tr>
<tr><th>Status</th><td><?php echo h($r['status']); ?></td></tr>
<tr><th>Product</th><td><?php echo h($r['product_name']); ?></td></tr>
<tr><th>Customer</th><td><?php echo h($r['customer_name']); ?></td></tr>
<tr><th>Issued</th><td><?php echo h($r['issued_at']); ?></td></tr>
<tr><th>Activated</th><td><?php echo h($r['activated_at']); ?></td></tr>
<tr><th>Expires</th><td><?php echo h($r['expires_at']); ?></td></tr>
<tr><th>Revoked</th><td><?php echo h($r['revoked_at']); ?></td></tr>
<tr><th>Notes</th><td><pre><?php echo h($r['notes']); ?></pre></td></tr>
<tr><th>Meta</th><td><pre><?php echo h($r['meta']); ?></pre></td></tr>
<tr><th>Version</th><td><?php echo (int)$r['version']; ?></td></tr>
</table>
<?php
    }
} else if ($action==='edit' && $id){
    $r = get_license($id);
    if (!$r){ echo '<p class="error">존재하지 않는 라이선스</p>'; }
    else {
?>
<h2>라이선스 수정</h2>
<form method="post" action="licenses.php?action=update">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <input type="hidden" name="id" value="<?php echo h($r['id']); ?>">
  <div class="grid">
    <div><label>Status:<br>
      <select name="status">
        <?php
        $stlist = array('ISSUED','ACTIVE','REVOKED','EXPIRED');
        foreach($stlist as $st){
            $sel = ($st==$r['status']) ? ' selected' : '';
            echo '<option'.$sel.'>'.h($st).'</option>';
        }
        ?>
      </select></label></div>

    <div><label>만료일:<br>
      <input type="text" name="expires_at" value="<?php echo h($r['expires_at']); ?>" class="js-datetime">
    </label></div>

    <div><label>상품:<br>
      <select name="product_id"><option value="">(선택)</option>
      <?php foreach($products as $p){ $sel=($p['id']==$r['product_id'])?' selected':''; echo '<option value="'.h($p['id']).'"'.$sel.'>'.h($p['name']).' ['.h($p['code']).']</option>'; } ?>
      </select></label></div>

    <div><label>고객:<br>
      <select name="customer_id"><option value="">(선택)</option>
      <?php foreach($customers as $c){ $sel=($c['id']==$r['customer_id'])?' selected':''; echo '<option value="'.h($c['id']).'"'.$sel.'>'.h($c['name']).'</option>'; } ?>
      </select></label></div>
  </div>

  <div class="row"><label>메모:<br><textarea name="notes" rows="3"><?php echo h($r['notes']); ?></textarea></label></div>
  <div class="row"><label>메타:<br><textarea name="meta" rows="3"><?php echo h($r['meta']); ?></textarea></label></div>

  <button class="btn primary" type="submit">저장</button>
  <a class="btn" href="licenses.php?action=view&id=<?php echo h($id); ?>">취소</a>
</form>
<?php
    }
} else {
    /* 목록 & 검색 */
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if ($q !== '') {
        $rows = db_all(
            "SELECT lk.id, lk.`key`, lk.status, lk.expires_at, c.name AS customer, p.name AS product
             FROM license_keys lk
             LEFT JOIN customers c ON lk.customer_id=c.id
             LEFT JOIN products p ON lk.product_id=p.id
             WHERE lk.`key` LIKE CONCAT('%',?,'%')
                OR c.name LIKE CONCAT('%',?,'%')
                OR p.name LIKE CONCAT('%',?,'%')
             ORDER BY lk.updated_at DESC
             LIMIT 200",
            'sss', array($q,$q,$q)
        );
    } else {
        $rows = db_all(
            "SELECT lk.id, lk.`key`, lk.status, lk.expires_at, c.name AS customer, p.name AS product
             FROM license_keys lk
             LEFT JOIN customers c ON lk.customer_id=c.id
             LEFT JOIN products p ON lk.product_id=p.id
             ORDER BY lk.updated_at DESC
             LIMIT 200"
        );
    }
?>
<h2>라이선스 목록</h2>
<p>
  <a class="btn primary" href="licenses.php?action=new">+ 발급</a>
</p>
<form method="get">
  <input type="hidden" name="action" value="list">
  <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="키/고객/상품 검색">
  <button class="btn" type="submit">검색</button>
</form>
<table class="table">
<tr><th>Key</th><th>Status</th><th>Product</th><th>Customer</th><th>Expires</th><th></th></tr>
<?php foreach($rows as $r){
    echo '<tr>';
    echo '<td><code>'.h($r['key']).'</code></td>';
    echo '<td>'.h($r['status']).'</td>';
    echo '<td>'.h($r['product']).'</td>';
    echo '<td>'.h($r['customer']).'</td>';
    echo '<td>'.h($r['expires_at']).'</td>';
    echo '<td><a class="btn" href="licenses.php?action=view&id='.h($r['id']).'">보기</a></td>';
    echo '</tr>';
} ?>
</table>
<?php } render_footer(); ?>
