<?php
require_once __DIR__.'/auth.php';
require_login();
$me = current_admin();

/* POST일 때만 CSRF 검사 */
csrf_check();

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id     = isset($_GET['id']) ? $_GET['id'] : null;

/**
 * @return mixed
 */
function get_category($id){
    return db_one("SELECT * FROM categories WHERE id=?", 's', array($id));
}

/* 생성 */
if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description = isset($_POST['description']) ? $_POST['description'] : null;

    $errors = array();
    if ($name==='') $errors[] = '카테고리 이름은 필수입니다.';

    if (!empty($errors)) {
        render_header('카테고리 등록', $me);
        echo '<h2>카테고리 등록</h2><div class="error"><ul>';
        foreach($errors as $e) echo '<li>'.h($e).'</li>';
        echo '</ul></div><p><a class="btn" href="categories.php?action=new">← 뒤로</a></p>';
        render_footer(); exit;
    }

    db_exec("INSERT INTO categories (id, name, description) VALUES (?,?,?)",
        'sss', array(uuid_v4(), $name, $description));
    header('Location: categories.php'); exit;
}

/* 수정 저장 */
if ($action==='update' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id   = $_POST['id'];
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description = isset($_POST['description']) ? $_POST['description'] : null;

    $errors = array();
    if ($name==='') $errors[] = '카테고리 이름은 필수입니다.';

    if (!empty($errors)) {
        render_header('카테고리 수정', $me);
        echo '<h2>카테고리 수정</h2><div class="error"><ul>';
        foreach($errors as $e) echo '<li>'.h($e).'</li>';
        echo '</ul></div><p><a class="btn" href="categories.php?action=edit&id='.h($id).'">← 뒤로</a></p>';
        render_footer(); exit;
    }

    db_exec("UPDATE categories SET name=?, description=? WHERE id=?",
        'sss', array($name, $description, $id));
    header('Location: categories.php'); exit;
}

/* 삭제 */
if ($action==='delete' && $id) {
    // 외래키 참조가 있다면 삭제가 막힐 수 있음(의도)
    db_exec("DELETE FROM categories WHERE id=?", 's', array($id));
    header('Location: categories.php'); exit;
}

/* 렌더링 */
render_header('카테고리', $me);

if ($action==='new'){ ?>
<h2>카테고리 등록</h2>
<form method="post" action="categories.php?action=create">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <div class="row"><label>이름 *:<br><input type="text" name="name" required></label></div>
  <div class="row"><label>설명:<br><textarea name="description" rows="3"></textarea></label></div>
  <button class="btn primary" type="submit">저장</button>
  <a class="btn" href="categories.php">목록</a>
</form>
<?php
} else if ($action==='edit' && $id){
    $r = get_category($id);
    if (!$r) { echo '<p class="error">존재하지 않는 카테고리</p>'; }
    else { ?>
<h2>카테고리 수정</h2>
<form method="post" action="categories.php?action=update">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <input type="hidden" name="id" value="<?php echo h($r['id']); ?>">
  <div class="row"><label>이름 *:<br><input type="text" name="name" value="<?php echo h($r['name']); ?>" required></label></div>
  <div class="row"><label>설명:<br><textarea name="description" rows="3"><?php echo h($r['description']); ?></textarea></label></div>
  <button class="btn primary" type="submit">저장</button>
  <a class="btn" href="categories.php">목록</a>
</form>
<?php } } else {
    $rows = db_all("SELECT * FROM categories ORDER BY name ASC");
?>
<h2>카테고리 목록</h2>
<p><a class="btn primary" href="categories.php?action=new">+ 등록</a></p>
<div class="table-wrap">
<table class="table">
<tr><th>이름</th><th>설명</th><th></th></tr>
<?php foreach($rows as $r){
    echo '<tr>';
    echo '<td>'.h($r['name']).'</td>';
    echo '<td><pre style="white-space:pre-wrap;margin:0">'.h($r['description']).'</pre></td>';
    echo '<td>
      <a class="btn" href="categories.php?action=edit&id='.h($r['id']).'">수정</a>
      <a class="btn" href="categories.php?action=delete&id='.h($r['id']).'" onclick="return confirm(\'삭제하시겠습니까?\')">삭제</a>
    </td>';
    echo '</tr>';
} ?>
</table>
</div>
<?php } render_footer(); ?>
