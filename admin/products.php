<?php
require_once __DIR__.'/auth.php';
require_login();
$me = current_admin();

/* POST일 때만 CSRF 검사 */
csrf_check();

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id     = isset($_GET['id']) ? $_GET['id'] : null;

/* 단건 조회 */
function get_product($id){
    return db_one("SELECT * FROM products WHERE id=?", 's', array($id));
}

/* ===== 생성 처리 ===== */
if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') {
    $code  = isset($_POST['code']) ? trim($_POST['code']) : '';
    $name  = isset($_POST['name']) ? trim($_POST['name']) : '';
    $max_activations = isset($_POST['max_activations']) ? (int)$_POST['max_activations'] : 1;
    $valid_days      = isset($_POST['valid_days']) && $_POST['valid_days'] !== '' ? (int)$_POST['valid_days'] : null;

    $errors = array();
    if ($code === '') {
        $errors[] = '상품 코드가 필요합니다.';
    }
    if ($name === '') {
        $errors[] = '상품명이 필요합니다.';
    }

    if (!empty($errors)) {
        render_header('상품 등록', $me);
        echo '<h2>상품 등록</h2>';
        echo '<div class="error"><ul>';
        foreach ($errors as $e) echo '<li>'.h($e).'</li>';
        echo '</ul></div>';
        echo '<p><a class="btn" href="products.php?action=new">← 뒤로</a></p>';
        render_footer();
        exit;
    }

    db_exec(
        "INSERT INTO products (id, code, name, max_activations, valid_days, updated_at) VALUES (?,?,?,?,?, NOW())",
        'sssis',
        array(uuid_v4(), $code, $name, $max_activations, $valid_days)
    );

    header('Location: products.php');
    exit;
}

/* ===== 수정 처리 ===== */
if ($action==='update' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id    = $_POST['id'];
    $code  = isset($_POST['code']) ? trim($_POST['code']) : '';
    $name  = isset($_POST['name']) ? trim($_POST['name']) : '';
    $max_activations = isset($_POST['max_activations']) ? (int)$_POST['max_activations'] : 1;
    $valid_days      = isset($_POST['valid_days']) && $_POST['valid_days'] !== '' ? (int)$_POST['valid_days'] : null;

    $errors = array();
    if ($code === '') {
        $errors[] = '상품 코드가 필요합니다.';
    }
    if ($name === '') {
        $errors[] = '상품명이 필요합니다.';
    }

    if (!empty($errors)) {
        render_header('상품 수정', $me);
        echo '<h2>상품 수정</h2>';
        echo '<div class="error"><ul>';
        foreach ($errors as $e) echo '<li>'.h($e).'</li>';
        echo '</ul></div>';
        echo '<p><a class="btn" href="products.php?action=edit&id='.h($id).'">← 뒤로</a></p>';
        render_footer();
        exit;
    }

    db_exec(
        "UPDATE products SET code=?, name=?, max_activations=?, valid_days=?, updated_at=NOW() WHERE id=?",
        'ssiss',
        array($code, $name, $max_activations, $valid_days, $id)
    );

    header('Location: products.php');
    exit;
}

/* ===== 삭제 ===== */
if ($action==='delete' && $id) {
    db_exec("DELETE FROM products WHERE id=?", 's', array($id));
    header('Location: products.php');
    exit;
}

/* ===== 렌더링 ===== */
render_header('상품', $me);

if ($action==='new'){ ?>
<h2>상품 등록</h2>
<form method="post" action="products.php?action=create">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <div class="row"><label>코드 *:<br><input type="text" name="code" required></label></div>
  <div class="row"><label>이름 *:<br><input type="text" name="name" required></label></div>
  <div class="row"><label>최대 활성화 수:<br><input type="text" name="max_activations" value="1"></label></div>
  <div class="row"><label>유효 기간(일):<br><input type="text" name="valid_days"></label></div>
  <button class="btn primary" type="submit">저장</button>
  <a class="btn" href="products.php">목록</a>
</form>
<?php
} else if ($action==='edit' && $id){
    $r = get_product($id);
    if (!$r){ echo '<p class="error">존재하지 않는 상품</p>'; }
    else {
?>
<h2>상품 수정</h2>
<form method="post" action="products.php?action=update">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <input type="hidden" name="id" value="<?php echo h($r['id']); ?>">
  <div class="row"><label>코드 *:<br><input type="text" name="code" value="<?php echo h($r['code']); ?>" required></label></div>
  <div class="row"><label>이름 *:<br><input type="text" name="name" value="<?php echo h($r['name']); ?>" required></label></div>
  <div class="row"><label>최대 활성화 수:<br><input type="text" name="max_activations" value="<?php echo h($r['max_activations']); ?>"></label></div>
  <div class="row"><label>유효 기간(일):<br><input type="text" name="valid_days" value="<?php echo h($r['valid_days']); ?>"></label></div>
  <button class="btn primary" type="submit">저장</button>
  <a class="btn" href="products.php">목록</a>
</form>
<?php
    }
} else {
    $rows = db_all("SELECT id, code, name, max_activations, valid_days, updated_at FROM products ORDER BY name ASC LIMIT 200");
?>
<h2>상품 목록</h2>
<p><a class="btn primary" href="products.php?action=new">+ 등록</a></p>
<table class="table">
<tr><th>코드</th><th>이름</th><th>최대 활성화</th><th>유효일</th><th>수정일</th><th></th></tr>
<?php foreach($rows as $r){
    echo '<tr>';
    echo '<td>'.h($r['code']).'</td>';
    echo '<td>'.h($r['name']).'</td>';
    echo '<td>'.(int)$r['max_activations'].'</td>';
    echo '<td>'.h($r['valid_days']).'</td>';
    echo '<td>'.h($r['updated_at']).'</td>';
    echo '<td>
        <a class="btn" href="products.php?action=edit&id='.h($r['id']).'">수정</a>
        <a class="btn" href="products.php?action=delete&id='.h($r['id']).'" onclick="return confirm(\'삭제하시겠습니까?\')">삭제</a>
    </td>';
    echo '</tr>';
} ?>
</table>
<?php } render_footer(); ?>
