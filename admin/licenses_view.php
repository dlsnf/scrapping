<?php
$me = current_admin();
$id = isset($_GET['id']) ? trim($_GET['id']) : '';

$r = db_one("
    SELECT lk.*,
           c.name  AS category_name,
           p.code  AS product_code,
           cu.name AS customer_name
      FROM license_keys lk
 LEFT JOIN categories c ON c.id=lk.category_id
 LEFT JOIN products   p ON p.id=lk.product_id
 LEFT JOIN customers cu ON cu.id=lk.customer_id
     WHERE lk.id=?
     LIMIT 1
", 's', array($id));
if (!$r) redirect_with('licenses.php', '해당 라이선스가 없습니다.', 'error');

// 필터/선택용 목록(등록일-시간 포함 내림차순)
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
    <div class="muted">※ 일일 카운트를 수정하면 기준일을 오늘로 갱신</div>
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
    <div class="muted">비우면 9999-12-31 23:59:59</div>
  </div>

  <div class="row">
    <label>총 최대 활성화</label>
    <input type="text" name="max_activations" value="<?php echo ($r['max_activations']===null?'':(int)$r['max_activations']); ?>" />
    <div class="muted">비우면 무한</div>
  </div>

  <div class="row">
    <label>일일 최대 활성화</label>
    <input type="text" name="daily_max_activations" value="<?php echo ($r['daily_max_activations']===null?'':(int)$r['daily_max_activations']); ?>" />
    <div class="muted">비우면 무한</div>
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
        $pmap[$p['category_id']][] = array('id'=>$p['id'], 'code'=>$p['code'], 'name'=>$p['name']);
    }
    $cmap = array();
    foreach ($all_customers as $cu) {
        if (!isset($cmap[$cu['category_id']])) $cmap[$cu['category_id']] = array();
        $cmap[$cu['category_id']][] = array('id'=>$cu['id'], 'name'=>$cu['name'], 'notes'=>$cu['notes']);
    }
    echo "PRODUCTS_BY_CAT = ".json_encode($pmap).";\n";
    echo "CUSTOMERS_BY_CAT = ".json_encode($cmap).";\n";
    echo "var CUR_CAT = ".json_encode($r['category_id']).";\n";
    echo "var CUR_PROD = ".json_encode($r['product_id']).";\n";
    echo "var CUR_CUST = ".json_encode($r['customer_id']).";\n";
  ?>

/**
 * @return mixed
 */
  function fillSelectProducts(sel, items, current){
    while (sel.options.length) sel.remove(0);
    for (var i=0;i<items.length;i++){
      var opt = document.createElement('option');
      opt.value = items[i]['id'];
      var nm = items[i]['name'] ? (' - ' + items[i]['name']) : '';
      opt.text = items[i]['code'] + nm;
      if (current && current === items[i]['id']) opt.selected = true;
      sel.appendChild(opt);
    }
  }
/**
 * @return mixed
 */
  function fillSelectCustomers(sel, items, current){
    while (sel.options.length) sel.remove(0);
    for (var i=0;i<items.length;i++){
      var opt = document.createElement('option');
      opt.value = items[i]['id'];
      var memo = items[i]['notes'] ? (' (' + items[i]['notes'] + ')') : '';
      opt.text = items[i]['name'] + memo;
      if (current && current === items[i]['id']) opt.selected = true;
      sel.appendChild(opt);
    }
  }

/**
 * @return mixed
 */
  function refreshLists(){
    var cat = document.getElementById('f_category').value;
    var pSel = document.getElementById('f_product');
    var cSel = document.getElementById('f_customer');
    var prods = PRODUCTS_BY_CAT[cat] || [];
    var custs = CUSTOMERS_BY_CAT[cat] || [];
    fillSelectProducts(pSel, prods, CUR_PROD);
    fillSelectCustomers(cSel, custs, CUR_CUST);
  }

  document.getElementById('f_category').onchange = function(){
    CUR_PROD = null; CUR_CUST = null; refreshLists();
  };

  refreshLists();
})();
</script>
<?php render_footer(); ?>
