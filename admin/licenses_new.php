<?php
$me = current_admin();

// 선택지: 카테고리/상품/고객 (등록일-시간 포함 내림차순)
$categories = db_all("SELECT id,name FROM categories ORDER BY name ASC");

$products  = db_all("
    SELECT id, code, name, category_id, created_at
      FROM products
  ORDER BY created_at DESC, id DESC
");

$customers = db_all("
    SELECT id, name, notes, category_id, created_at
      FROM customers
  ORDER BY created_at DESC, id DESC
");

// 폼 상태(유지)
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

// 랜덤 키 생성기(10자리 A-Z0-9)
function _gen_key10() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $out = '';
    for ($i=0; $i<10; $i++) { $out .= $chars[mt_rand(0, strlen($chars)-1)]; }
    return $out;
}
function _generate_unique_key() {
    for ($i=0; $i<50; $i++) {
        $k = _gen_key10();
        $dup = db_one("SELECT id FROM license_keys WHERE `key`=? LIMIT 1", 's', array($k));
        if (!$dup) return $k;
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 입력값 수집
    $form['category_id'] = isset($_POST['category_id']) ? trim($_POST['category_id']) : '';
    $form['product_id']  = isset($_POST['product_id'])  ? trim($_POST['product_id'])  : '';
    $form['customer_id'] = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';
    $form['key']         = isset($_POST['key'])         ? strtoupper(trim($_POST['key'])) : '';
    $form['status']      = isset($_POST['status'])      ? trim($_POST['status']) : 'ACTIVE';
    $form['expires_at']  = isset($_POST['expires_at'])  ? trim($_POST['expires_at']) : '';
    $form['max_activations']       = isset($_POST['max_activations']) ? trim($_POST['max_activations']) : '';
    $form['daily_max_activations'] = isset($_POST['daily_max_activations']) ? trim($_POST['daily_max_activations']) : '';
    $form['notes']       = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    $errs = array();
    if ($form['category_id']==='') $errs[]='카테고리';
    if ($form['product_id']==='')  $errs[]='상품';
    if ($form['customer_id']==='') $errs[]='고객';

    // 키: 비어 있으면 자동생성
    if ($form['key'] === '') {
        $gen = _generate_unique_key();
        if ($gen === false) $errs[]='라이선스 키 자동생성 실패';
        else $form['key'] = $gen;
    } else {
        if (!preg_match('/^[A-Z0-9]{10}$/', $form['key'])) {
            $errs[]='라이선스 키(대문자/숫자 10자리 형식)';
        }
    }

    if (!empty($errs)) {
        $error_msg = '필수 항목 누락/형식 오류: '.implode(', ', $errs);
    } else {
        // 만료일
        if ($form['expires_at']==='') {
            $expires_at = '9999-12-31 23:59:59';
        } else if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['expires_at'])) {
            $expires_at = $form['expires_at'].' 23:59:59';
        } else {
            $expires_at = $form['expires_at'];
        }

        // 최대치: 빈값 → NULL
        $max_total = ($form['max_activations']==='' ? null : (int)$form['max_activations']);
        $max_daily = ($form['daily_max_activations']==='' ? null : (int)$form['daily_max_activations']);
        if ($max_total!==null && $max_total<0) $max_total=0;
        if ($max_daily!==null && $max_daily<0) $max_daily=0;

        // 동일 카테고리+상품+고객 중복 방지
        $dup = db_one(
            "SELECT id FROM license_keys WHERE category_id=? AND product_id=? AND customer_id=? LIMIT 1",
            'sss',
            array($form['category_id'], $form['product_id'], $form['customer_id'])
        );
        if ($dup) {
            $error_msg = '동일한 카테고리/상품/고객 조합이 이미 존재합니다.';
        } else {
            // 키 중복 이중확인
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
                    $types  = 'ssssssss';
                    $params = array(
                        $id, $form['key'], $form['status'],
                        $form['product_id'], $form['customer_id'], $form['category_id'],
                        $expires_at, $form['notes']
                    );
                    if ($max_total!==null) { $types.='i'; $params[]=$max_total; }
                    if ($max_daily!==null) { $types.='i'; $params[]=$max_daily; }
                    $types.='s'; $params[] = $creator_id;

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
  function fillProducts(sel, list, current){
    while(sel.options.length) sel.remove(0);
    for (var i=0;i<list.length;i++){
      var o = document.createElement('option');
      o.value = list[i]['id'];
      var name = list[i]['name'] ? (' - ' + list[i]['name']) : '';
      o.text  = list[i]['code'] + name; // 코드 - 이름
      if (current && current === list[i]['id']) o.selected = true;
      sel.appendChild(o);
    }
  }
  function fillCustomers(sel, list, current){
    while(sel.options.length) sel.remove(0);
    for (var i=0;i<list.length;i++){
      var o = document.createElement('option');
      o.value = list[i]['id'];
      var memo = list[i]['notes'] ? (' (' + list[i]['notes'] + ')') : '';
      o.text  = list[i]['name'] + memo; // 이름 (메모)
      if (current && current === list[i]['id']) o.selected = true;
      sel.appendChild(o);
    }
  }

  var catSel = document.getElementById('n_category');
  var pSel   = document.getElementById('n_product');
  var cSel   = document.getElementById('n_customer');

  function refresh(){
    var cat = catSel.value || null;
    var ps = cat ? filterByCat(PRODUCTS,  cat, 'category_id') : [];
    var cs = cat ? filterByCat(CUSTOMERS, cat, 'category_id') : [];
    fillProducts(pSel, ps, CUR_PROD);
    fillCustomers(cSel, cs, CUR_CUST);
  }
  catSel.onchange = function(){ CUR_PROD = null; CUR_CUST = null; refresh(); };
  if (CUR_CAT) catSel.value = CUR_CAT;
  refresh();
})();
</script>
<?php render_footer(); ?>
