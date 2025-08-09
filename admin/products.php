<?php
require_once __DIR__.'/auth.php';
require_login();
$me = current_admin();
csrf_check();

function fetch_categories(){ return db_all("SELECT id, name FROM categories ORDER BY name ASC"); }
function get_product($id){ return db_one("SELECT * FROM products WHERE id=?", 's', array($id)); }

$action = isset($_GET['action']) ? $_GET['action'] : 'list';
$id     = isset($_GET['id']) ? $_GET['id'] : null;

/* 생성 */
if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') {
    $code  = isset($_POST['code']) ? trim($_POST['code']) : '';
    $name  = isset($_POST['name']) ? trim($_POST['name']) : '';
    $catid = isset($_POST['category_id']) && $_POST['category_id']!=='' ? $_POST['category_id'] : null;

    $errors = array();
    if ($code==='') $errors[]='상품 코드는 필수입니다.';
    if ($name==='') $errors[]='상품명은 필수입니다.';
    if ($catid===null) $errors[]='카테고리를 선택하세요.';

    if (!empty($errors)) {
        $cats = fetch_categories();
        render_header('상품 등록', $me);
        echo '<h2>상품 등록</h2><div class="error"><ul>';
        foreach($errors as $e) echo '<li>'.h($e).'</li>'; echo '</ul></div>';
        echo '<p><a class="btn" href="products.php?action=new">← 뒤로</a></p>'; render_footer(); exit;
    }

    db_exec("INSERT INTO products (id, code, name, category_id) VALUES (?,?,?,?)",
        'ssss', array(uuid_v4(), $code, $name, $catid));
    header('Location: products.php'); exit;
}

/* 수정 저장 */
if ($action==='update' && $_SERVER['REQUEST_METHOD']==='POST') {
    $id    = $_POST['id'];
    $code  = isset($_POST['code']) ? trim($_POST['code']) : '';
    $name  = isset($_POST['name']) ? trim($_POST['name']) : '';
    $catid = isset($_POST['category_id']) && $_POST['category_id']!=='' ? $_POST['category_id'] : null;

    $errors = array();
    if ($code==='') $errors[]='상품 코드는 필수입니다.';
    if ($name==='') $errors[]='상품명은 필수입니다.';
    if ($catid===null) $errors[]='카테고리를 선택하세요.';

    if (!empty($errors)) {
        render_header('상품 수정', $me);
        echo '<h2>상품 수정</h2><div class="error"><ul>';
        foreach($errors as $e) echo '<li>'.h($e).'</li>'; echo '</ul></div>';
        echo '<p><a class="btn" href="products.php?action=edit&id='.h($id).'">← 뒤로</a></p>'; render_footer(); exit;
    }

    db_exec("UPDATE products SET code=?, name=?, category_id=? WHERE id=?",
        'ssss', array($code, $name, $catid, $id));
    header('Location: products.php'); exit;
}

/* 삭제 */
if ($action==='delete' && $id) {
    db_exec("DELETE FROM products WHERE id=?", 's', array($id));
    header('Location: products.php'); exit;
}

/* 렌더 */
render_header('상품', $me);

if ($action==='new'){
    $cats = fetch_categories(); ?>
<h2>상품 등록</h2>
<form method="post" action="products.php?action=create">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <div class="row"><label>코드 *:<br><input type="text" name="code" required></label></div>
  <div class="row"><label>이름 *:<br><input type="text" name="name" required></label></div>
  <div class="row"><label>카테고리 *:<br>
    <select name="category_id" required>
      <option value="">(선택)</option>
      <?php foreach($cats as $c) echo '<option value="'.h($c['id']).'">'.h($c['name']).'</option>'; ?>
    </select></label></div>
  <button class="btn primary" type="submit">저장</button>
  <a class="btn" href="products.php">목록</a>
</form>
<?php
} else if ($action==='edit' && $id){
    $r = get_product($id);
    $cats = fetch_categories();
    if (!$r){ echo '<p class="error">존재하지 않는 상품</p>'; }
    else { ?>
<h2>상품 수정</h2>
<form method="post" action="products.php?action=update">
  <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
  <input type="hidden" name="id" value="<?php echo h($r['id']); ?>">
  <div class="row"><label>코드 *:<br><input type="text" name="code" value="<?php echo h($r['code']); ?>" required></label></div>
  <div class="row"><label>이름 *:<br><input type="text" name="name" value="<?php echo h($r['name']); ?>" required></label></div>
  <div class="row"><label>카테고리 *:<br>
    <select name="category_id" required>
      <option value="">(선택)</option>
      <?php foreach($cats as $c){ $sel=($c['id']==$r['category_id'])?' selected':''; echo '<option value="'.h($c['id']).'"'.$sel.'>'.h($c['name']).'</option>'; } ?>
    </select></label></div>
  <button class="btn primary" type="submit">저장</button>
  <a class="btn" href="products.php">목록</a>
</form>
<?php } } else {
    $cats = fetch_categories();
    $filter_cat = isset($_GET['category_id']) ? $_GET['category_id'] : '';

    $where=array(); $types=''; $params=array();
    if ($filter_cat!==''){ $where[]='p.category_id=?'; $types.='s'; $params[]=$filter_cat; }

    $sql = "SELECT p.id, p.code, p.name, c.name AS category
            FROM products p LEFT JOIN categories c ON p.category_id=c.id";
    if (!empty($where)) $sql .= " WHERE ".implode(' AND ',$where);

    $allowed = array(
        'code'     => 'p.code',
        'name'     => 'p.name',
        'category' => 'c.name'
    );
    list($order_by,$s,$d) = get_sort_sql($allowed, 'name', 'ASC');
    $sql .= " ".$order_by." LIMIT 500";

    $rows = empty($types) ? db_all($sql) : db_all($sql, $types, $params);
?>
<h2>상품 목록</h2>

<form method="get" class="row" style="margin-bottom:10px">
  <input type="hidden" name="action" value="list">
  <label>카테고리:
    <select name="category_id" onchange="this.form.submit()">
      <option value="">(전체)</option>
      <?php foreach($cats as $c){ $sel=($filter_cat==$c['id'])?' selected':''; echo '<option value="'.h($c['id']).'"'.$sel.'>'.h($c['name']).'</option>'; } ?>
    </select>
  </label>
</form>

<p><a class="btn primary" href="products.php?action=new">+ 등록</a></p>
<div class="table-wrap">
<table class="table">
<tr>
  <th><?php echo sort_link('코드','code','products.php'); ?></th>
  <th><?php echo sort_link('이름','name','products.php'); ?></th>
  <th><?php echo sort_link('카테고리','category','products.php'); ?></th>
  <th></th>
</tr>
<?php foreach($rows as $r){
    echo '<tr>';
    echo '<td>'.h($r['code']).'</td>';
    echo '<td>'.h($r['name']).'</td>';
    echo '<td>'.h($r['category']).'</td>';
    echo '<td>
        <a class="btn" href="products.php?action=edit&id='.h($r['id']).'">수정</a>
        <a class="btn" href="products.php?action=delete&id='.h($r['id']).'" onclick="return confirm(\'삭제하시겠습니까?\')">삭제</a>
    </td>';
    echo '</tr>';
} ?>
</table>
</div>
<?php } render_footer(); ?>
