<?php
// 파일: /scrapping/admin/licenses_list.php
$me = current_admin();

// 필터 파라미터
$category_id = isset($_GET['category_id']) ? trim($_GET['category_id']) : '';
$product_id  = isset($_GET['product_id'])  ? trim($_GET['product_id'])  : '';
$customer_id = isset($_GET['customer_id']) ? trim($_GET['customer_id']) : '';
$status      = isset($_GET['status'])      ? trim($_GET['status'])      : '';

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'issued_at';
$dir  = isset($_GET['dir'])  ? $_GET['dir']  : 'DESC';

$allow_cols = array(
  'issued_at','status','key',
  'category_name',              // ← 카테고리명 정렬 허용 추가
  'product_code','customer_name',
  'total_activations','daily_activations',
  'max_activations','daily_max_activations'
);
if (!in_array($sort, $allow_cols)) $sort = 'issued_at';
$dir = ($dir==='ASC') ? 'ASC' : 'DESC';

// 필터용 데이터
$categories    = db_all("SELECT id, name FROM categories ORDER BY name ASC");
$all_products  = db_all("
    SELECT id, code, name, category_id, created_at
      FROM products
  ORDER BY created_at DESC, id DESC
");
$all_customers = db_all("
    SELECT id, name, notes, category_id, created_at
      FROM customers
  ORDER BY created_at DESC, id DESC
");

// 목록 쿼리
$where = array(); $types=''; $params=array();
if ($category_id !== '') { $where[]='lk.category_id=?'; $types.='s'; $params[]=$category_id; }
if ($product_id  !== '') { $where[]='lk.product_id=?';  $types.='s'; $params[]=$product_id; }
if ($customer_id !== '') { $where[]='lk.customer_id=?'; $types.='s'; $params[]=$customer_id; }
if ($status      !== '') { $where[]='lk.status=?';      $types.='s'; $params[]=$status; }

$sql = "
    SELECT lk.id, lk.`key`, lk.status, lk.issued_at,
           lk.total_activations, lk.daily_activations,
           lk.max_activations, lk.daily_max_activations,
           c.name AS category_name,
           p.code AS product_code,
           cu.name AS customer_name
      FROM license_keys lk
 LEFT JOIN categories c ON c.id=lk.category_id
 LEFT JOIN products   p ON p.id=lk.product_id
 LEFT JOIN customers cu ON cu.id=lk.customer_id
";
if (!empty($where)) $sql .= " WHERE ".implode(' AND ',$where);
$sql .= " ORDER BY ".$sort." ".$dir;

$list = db_all($sql, ($types===''?null:$types), ($types===''?null:$params));

render_header('라이선스 목록', $me);
if (function_exists('flash_render')) { flash_render(); }
?>
<h2>라이선스</h2>
<p>
  <a class="btn primary" href="licenses.php?action=new">라이선스 발급</a>
</p>

<form method="get" action="licenses.php" class="grid" id="filterForm">
  <input type="hidden" name="action" value="list" />
  <div class="row">
    <label>카테고리</label>
    <select name="category_id" id="f_cat">
      <option value="">-- 전체 --</option>
      <?php foreach($categories as $c): ?>
        <option value="<?php echo h($c['id']); ?>" <?php echo ($category_id===$c['id']?'selected="selected"':''); ?>>
          <?php echo h($c['name']); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="row">
    <label>상품</label>
    <select name="product_id" id="f_prod"></select>
  </div>
  <div class="row">
    <label>고객</label>
    <select name="customer_id" id="f_cust"></select>
  </div>
  <div class="row">
    <label>상태</label>
    <select name="status" onchange="document.getElementById('filterForm').submit();">
      <option value="">-- 전체 --</option>
      <?php
        $st = array('ACTIVE'=>'사용','ISSUED'=>'미사용','REVOKED'=>'폐기','EXPIRED'=>'만료');
        foreach($st as $k=>$v){
            echo '<option value="'.h($k).'" '.($status===$k?'selected="selected"':'').'>'.h($v).'</option>';
        }
      ?>
    </select>
  </div>
</form>

<table class="table">
  <thead>
    <tr>
      <?php
        function HREF_S($col, $title, $sort, $dir, $q){
            $nd = ($sort===$col ? next_dir($dir) : 'ASC');
            $q['sort']=$col; $q['dir']=$nd;
            $href = 'licenses.php?'.http_build_query($q,'','&');
            echo '<th><a href="'.h($href).'">'.h($title).'</a> '.icon_dir($col,$sort,$dir).'</th>';
        }
        $q = $_GET;
      ?>
      <?php HREF_S('issued_at','발급일',$sort,$dir,$q); ?>
      <?php HREF_S('category_name','카테고리',$sort,$dir,$q); ?>  <!-- 추가 -->
      <?php HREF_S('status','상태',$sort,$dir,$q); ?>
      <?php HREF_S('key','키',$sort,$dir,$q); ?>
      <?php HREF_S('product_code','상품',$sort,$dir,$q); ?>
      <?php HREF_S('customer_name','고객',$sort,$dir,$q); ?>
      <?php HREF_S('total_activations','총 접근',$sort,$dir,$q); ?>
      <?php HREF_S('daily_activations','일일 접근',$sort,$dir,$q); ?>
      <?php HREF_S('max_activations','총 최대',$sort,$dir,$q); ?>
      <?php HREF_S('daily_max_activations','일일 최대',$sort,$dir,$q); ?>
      <th>보기</th>
    </tr>
  </thead>
  <tbody>
    <?php if (!$list): ?>
      <tr><td colspan="11" class="muted">데이터가 없습니다.</td></tr>
    <?php else: foreach($list as $row): ?>
      <tr>
        <td><?php echo h($row['issued_at']); ?></td>
        <td><?php echo h($row['category_name']); ?></td> <!-- 추가 -->
        <td><?php
          $map = array('ACTIVE'=>'사용','ISSUED'=>'미사용','REVOKED'=>'폐기','EXPIRED'=>'만료');
          echo h(isset($map[$row['status']])?$map[$row['status']]:$row['status']);
        ?></td>
        <td><code><?php echo h($row['key']); ?></code></td>
        <td><?php echo h($row['product_code']); ?></td>
        <td><?php echo h($row['customer_name']); ?></td>
        <td><?php echo (int)$row['total_activations']; ?></td>
        <td><?php echo (int)$row['daily_activations']; ?></td>
        <td><?php echo ($row['max_activations']===null?'∞':(int)$row['max_activations']); ?></td>
        <td><?php echo ($row['daily_max_activations']===null?'∞':(int)$row['daily_max_activations']); ?></td>
        <td><a class="btn" href="licenses.php?action=view&id=<?php echo h($row['id']); ?>">보기</a></td>
      </tr>
    <?php endforeach; endif; ?>
  </tbody>
</table>

<script>
(function(){
  // 카테고리 → 상품/고객 동적
  var PRODUCTS_BY_CAT = {};
  var CUSTOMERS_BY_CAT = {};
  <?php
    $pmap = array();
    foreach ($all_products as $p) {
        if (!isset($pmap[$p['category_id']])) $pmap[$p['category_id']] = array();
        $pmap[$p['category_id']][] = array('id'=>$p['id'], 'code'=>$p['code'], 'name'=>$p['name']);
    }
    $cmap = array();
    foreach ($all_customers as $cu) {
        if (!isset($cmap[$cu['category_id']])) $cmap[$cu['category_id']] = array();
        $cmap[$cu['category_id']][] = array('id'=>$cu['id'], 'name'=>$cu['name'], 'notes'=>$cu['notes']);
    }
    echo "PRODUCTS_BY_CAT = ".json_encode($pmap).";\n";
    echo "CUSTOMERS_BY_CAT = ".json_encode($cmap).";\n";
    echo "var CUR_CAT = ".json_encode($category_id).";\n";
    echo "var CUR_PROD = ".json_encode($product_id).";\n";
    echo "var CUR_CUST = ".json_encode($customer_id).";\n";
  ?>

  function fillSelectProducts(sel, items, cur){
    while (sel.options.length) sel.remove(0);
    var o0 = document.createElement('option'); o0.value=''; o0.text='-- 전체 --'; sel.appendChild(o0);
    for (var i=0;i<items.length;i++){
      var op = document.createElement('option');
      op.value = items[i]['id'];
      var nm = items[i]['name'] ? (' - ' + items[i]['name']) : '';
      op.text = items[i]['code'] + nm; // 코드 - 이름
      if (cur && cur === items[i]['id']) op.selected = true;
      sel.appendChild(op);
    }
  }
  function fillSelectCustomers(sel, items, cur){
    while (sel.options.length) sel.remove(0);
    var o0 = document.createElement('option'); o0.value=''; o0.text='-- 전체 --'; sel.appendChild(o0);
    for (var i=0;i<items.length;i++){
      var op = document.createElement('option');
      op.value = items[i]['id'];
      var memo = items[i]['notes'] ? (' (' + items[i]['notes'] + ')') : '';
      op.text = items[i]['name'] + memo; // 이름 (메모)
      if (cur && cur === items[i]['id']) op.selected = true;
      sel.appendChild(op);
    }
  }

  var catSel = document.getElementById('f_cat');
  var pSel   = document.getElementById('f_prod');
  var cSel   = document.getElementById('f_cust');

  function refresh(){
    var cat = catSel.value || null;
    var prods = PRODUCTS_BY_CAT[cat] || [];
    var custs = CUSTOMERS_BY_CAT[cat] || [];
    fillSelectProducts(pSel, prods, CUR_PROD);
    fillSelectCustomers(cSel, custs, CUR_CUST);
  }

  catSel.onchange = function(){ CUR_PROD=null; CUR_CUST=null; refresh(); document.getElementById('filterForm').submit(); };
  pSel.onchange = function(){ document.getElementById('filterForm').submit(); };
  cSel.onchange = function(){ document.getElementById('filterForm').submit(); };

  if (CUR_CAT) catSel.value = CUR_CAT;
  refresh();
})();
</script>
<?php render_footer(); ?>
