<?php
require_once __DIR__.'/auth.php';
require_login();
$me = current_admin();

/* POST일 때만 CSRF 검사 */
csrf_check();

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id     = isset($_GET['id']) ? $_GET['id'] : null;

/* 단건 조회 */
function get_customer($id){
    return db_one("SELECT * FROM customers WHERE id=?", 's', array($id));
}

/* ===== 생성 처리 ===== */
if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') {
    $name  = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : null;
    $notes = isset($_POST['notes']) ? $_POST['notes'] : null;

    $errors = array();
    if ($name === '') {
        $errors[] = '고객 이름은 필수입니다.';
    }

    if (!empty($errors)) {
        render_header('고객 등록', $me);
        echo '<h2>고객 등록</h2>';
        echo '<div class="error"><ul>';
        foreach ($errors as $e) echo '<li>'.h($e).'</li>';
        echo '</ul></div>';
        echo '<p><a class="btn" href="customers.php?action=new">← 뒤로</a></p>';
        render_footer();
        exit;
    }

    // created_at은 테이블에서 TIMESTAMP 기본값, updated_at은 여기서 NOW()
    db_exec(
        "INSERT INTO customers (id, name, email, notes, updated_at) VALUES (?,?,?,?, NOW())",
        'ssss',
        array(uuid_v4(), $name, $email, $notes)
    );

    header('Location: customers.php');
    exit;
}

/* ===== 수정 처리 ===== */
if ($action==='update' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id    = $_POST['id'];
    $name  = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : null;
    $notes = isset($_POST['notes']) ? $_POST['notes'] : null;

    $errors = array();
    if ($name === '') {
        $errors[] = '고객 이름은 필수입니다.';
    }

    if (!empty($errors)) {
        render_header('고객 수정', $me);
        echo '<h2>고객 수정</h2>';
        echo '<div class="error"><ul>';
        foreach ($errors as $e) echo '<li>'.h($e).'</li>';
        echo '</ul></div>';
        echo '<p><a class="btn" href="customers.php?action=edit&id='.h($id).'">← 뒤로</a></p>';
        render_footer();
        exit;
    }

    db_exec(
        "UPDATE customers SET name=?, email=?, notes=?, updated_at=NOW() WHERE id=?",
        'ssss',
        array($name, $email, $notes, $id)
    );

    header('Location: customers.php');
    exit;
}

/* ===== 삭제 ===== */
if ($action==='delete' && $id) {
    db_exec("DELETE FROM customers WHERE id=?", 's', array($id));
    header('Location: customers.php');
    exit;
}

/* ===== 렌더링 ===== */
render_header('고객', $me);

if ($action==='new'){ ?>
<h2>고객 등록</h2>
<form method="post" action="customers.php?action=create">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <div class="row"><label>이름 *:<br><input type="text" name="name" required></label></div>
  <div class="row"><label>이메일:<br><input type="text" name="email"></label></div>
  <div class="row"><label>메모:<br><textarea name="notes" rows="3"></textarea></label></div>
  <button class="btn primary" type="submit">저장</button>
  <a class="btn" href="customers.php">목록</a>
</form>
<?php
} else if ($action==='edit' && $id){
    $r = get_customer($id);
    if (!$r){ echo '<p class="error">존재하지 않는 고객</p>'; }
    else {
?>
<h2>고객 수정</h2>
<form method="post" action="customers.php?action=update">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <input type="hidden" name="id" value="<?php echo h($r['id']); ?>">
  <div class="row"><label>이름 *:<br><input type="text" name="name" value="<?php echo h($r['name']); ?>" required></label></div>
  <div class="row"><label>이메일:<br><input type="text" name="email" value="<?php echo h($r['email']); ?>"></label></div>
  <div class="row"><label>메모:<br><textarea name="notes" rows="3"><?php echo h($r['notes']); ?></textarea></label></div>
  <button class="btn primary" type="submit">저장</button>
  <a class="btn" href="customers.php">목록</a>
</form>
<?php
    }
} else {
    $rows = db_all("SELECT id, name, email, created_at, updated_at FROM customers ORDER BY created_at DESC LIMIT 200");
?>
<h2>고객 목록</h2>
<p><a class="btn primary" href="customers.php?action=new">+ 등록</a></p>
<table class="table">
<tr><th>이름</th><th>이메일</th><th>등록일</th><th>수정일</th><th></th></tr>
<?php foreach($rows as $r){
    echo '<tr>';
    echo '<td>'.h($r['name']).'</td>';
    echo '<td>'.h($r['email']).'</td>';
    echo '<td>'.h($r['created_at']).'</td>';
    echo '<td>'.h($r['updated_at']).'</td>';
    echo '<td>
        <a class="btn" href="customers.php?action=edit&id='.h($r['id']).'">수정</a>
        <a class="btn" href="customers.php?action=delete&id='.h($r['id']).'" onclick="return confirm(\'삭제하시겠습니까?\')">삭제</a>
    </td>';
    echo '</tr>';
} ?>
</table>
<?php } render_footer(); ?>
