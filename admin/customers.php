<?php
require_once __DIR__.'/auth.php';
require_login();
$me = current_admin();
csrf_check();

function fetch_categories(){ return db_all("SELECT id, name FROM categories ORDER BY name ASC"); }
function get_customer($id){ return db_one("SELECT * FROM customers WHERE id=?", 's', array($id)); }

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id     = isset($_GET['id']) ? $_GET['id'] : null;

/* 생성 */
if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') {
    $name  = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : null;
    $notes = isset($_POST['notes']) ? $_POST['notes'] : null;
    $catid = isset($_POST['category_id']) && $_POST['category_id']!=='' ? $_POST['category_id'] : null;

    $errors = array();
    if ($name==='') $errors[]='고객 이름은 필수입니다.';
    if ($catid===null) $errors[]='카테고리를 선택하세요.';

    if (!empty($errors)) {
        $cats = fetch_categories();
        render_header('고객 등록', $me);
        echo '<h2>고객 등록</h2><div class="error"><ul>';
        foreach($errors as $e) echo '<li>'.h($e).'</li>'; echo '</ul></div>';
        echo '<p><a class="btn" href="customers.php?action=new">← 뒤로</a></p>'; render_footer(); exit;
    }

    db_exec("INSERT INTO customers (id, name, email, notes, category_id) VALUES (?,?,?,?,?)",
        'sssss', array(uuid_v4(), $name, $email, $notes, $catid));
    header('Location: customers.php'); exit;
}

/* 수정 저장 */
if ($action==='update' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id    = $_POST['id'];
    $name  = isset($_POST['name']) ? trim($_POST['name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : null;
    $notes = isset($_POST['notes']) ? $_POST['notes'] : null;
    $catid = isset($_POST['category_id']) && $_POST['category_id']!=='' ? $_POST['category_id'] : null;

    $errors = array();
    if ($name==='') $errors[]='고객 이름은 필수입니다.';
    if ($catid===null) $errors[]='카테고리를 선택하세요.';

    if (!empty($errors)) {
        render_header('고객 수정', $me);
        echo '<h2>고객 수정</h2><div class="error"><ul>';
        foreach($errors as $e) echo '<li>'.h($e).'</li>'; echo '</ul></div>';
        echo '<p><a class="btn" href="customers.php?action=edit&id='.h($id).'">← 뒤로</a></p>'; render_footer(); exit;
    }

    db_exec("UPDATE customers SET name=?, email=?, notes=?, category_id=? WHERE id=?",
        'sssss', array($name, $email, $notes, $catid, $id));
    header('Location: customers.php'); exit;
}

/* 삭제 */
if ($action==='delete' && $id) {
    db_exec("DELETE FROM customers WHERE id=?", 's', array($id));
    header('Location: customers.php'); exit;
}

/* 렌더 */
render_header('고객', $me);

if ($action==='new'){
    $cats = fetch_categories(); ?>
<h2>고객 등록</h2>
<form method="post" action="customers.php?action=create">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <div class="row"><label>이름 *:<br><input type="text" name="name" required></label></div>
  <div class="row"><label>이메일:<br><input type="text" name="email"></label></div>
  <div class="row"><label>카테고리 *:<br>
    <select name="category_id" required>
      <option value="">(선택)</option>
      <?php foreach($cats as $c) echo '<option value="'.h($c['id']).'">'.h($c['name']).'</option>'; ?>
    </select></label></div>
  <div class="row"><label>메모:<br><textarea name="notes" rows="3"></textarea></label></div>
  <button class="btn primary" type="submit">저장</button>
  <a class="btn" href="customers.php">목록</a>
</form>
<?php
} else if ($action==='edit' && $id){
    $r = get_customer($id);
    $cats = fetch_categories();
    if (!$r){ echo '<p class="error">존재하지 않는 고객</p>'; }
    else { ?>
<h2>고객 수정</h2>
<form method="post" action="customers.php?action=update">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <input type="hidden" name="id" value="<?php echo h($r['id']); ?>">
  <div class="row"><label>이름 *:<br><input type="text" name="name" value="<?php echo h($r['name']); ?>" required></label></div>
  <div class="row"><label>이메일:<br><input type="text" name="email" value="<?php echo h($r['email']); ?>"></label></div>
  <div class="row"><label>카테고리 *:<br>
    <select name="category_id" required>
      <option value="">(선택)</option>
      <?php foreach($cats as $c){ $sel=($c['id']==$r['category_id'])?' selected':''; echo '<option value="'.h($c['id']).'"'.$sel.'>'.h($c['name']).'</option>'; } ?>
    </select></label></div>
  <div class="row"><label>메모:<br><textarea name="notes" rows="3"><?php echo h($r['notes']); ?></textarea></label></div>
  <button class="btn primary" type="submit">저장</button>
  <a class="btn" href="customers.php">목록</a>
</form>
<?php } } else {
    $cats = fetch_categories();
    $filter_cat = isset($_GET['category_id']) ? $_GET['category_id'] : '';

    $where = array(); $types=''; $params=array();
    if ($filter_cat!==''){ $where[]='cu.category_id=?'; $types.='s'; $params[]=$filter_cat; }

    $sql = "SELECT cu.id, cu.name, cu.email, cu.notes, cu.created_at, ca.name AS category
            FROM customers cu LEFT JOIN categories ca ON cu.category_id=ca.id";
    if (!empty($where)) $sql .= " WHERE ".implode(' AND ',$where);

    $allowed = array(
        'name'      => 'cu.name',
        'email'     => 'cu.email',
        'category'  => 'ca.name',
        'created'   => 'cu.created_at'
    );
    list($order_by,$s,$d) = get_sort_sql($allowed, 'created', 'DESC');
    $sql .= " ".$order_by." LIMIT 500";

    $rows = empty($types) ? db_all($sql) : db_all($sql, $types, $params);
?>
<h2>고객 목록</h2>

<form method="get" class="row" style="margin-bottom:10px">
  <input type="hidden" name="action" value="list">
  <label>카테고리:
    <select name="category_id" onchange="this.form.submit()">
      <option value="">(전체)</option>
      <?php foreach($cats as $c){ $sel=($filter_cat==$c['id'])?' selected':''; echo '<option value="'.h($c['id']).'"'.$sel.'>'.h($c['name']).'</option>'; } ?>
    </select>
  </label>
</form>

<p><a class="btn primary" href="customers.php?action=new">+ 등록</a></p>
<div class="table-wrap">
<table class="table">
<tr>
  <th><?php echo sort_link('이름','name','customers.php'); ?></th>
  <th><?php echo sort_link('이메일','email','customers.php'); ?></th>
  <th><?php echo sort_link('카테고리','category','customers.php'); ?></th>
  <th>메모</th>
  <th><?php echo sort_link('등록일','created','customers.php'); ?></th>
  <th></th>
</tr>
<?php foreach($rows as $r){
    echo '<tr>';
    echo '<td>'.h($r['name']).'</td>';
    echo '<td>'.h($r['email']).'</td>';
    echo '<td>'.h($r['category']).'</td>';
    echo '<td><pre style="white-space:pre-wrap;margin:0">'.h($r['notes']).'</pre></td>';
    echo '<td>'.h($r['created_at']).'</td>';
    echo '<td>
        <a class="btn" href="customers.php?action=edit&id='.h($r['id']).'">수정</a>
        <a class="btn" href="customers.php?action=delete&id='.h($r['id']).'" onclick="return confirm(\'삭제하시겠습니까?\')">삭제</a>
    </td>';
    echo '</tr>';
} ?>
</table>
</div>
<?php } render_footer(); ?>
