<?php
// 파일: /scrapping/admin/products.php
require_once __DIR__.'/auth.php';
require_login();
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

/* =========================================================
   공통 유틸 (정렬 아이콘/토글)
   ========================================================= */
if (!function_exists('icon_dir')) {
/**
 * @return mixed
 */
    function icon_dir($col, $sort, $dir){
        if ($col !== $sort) return '';
        return ($dir === 'ASC') ? '↑' : '↓';
    }
}
if (!function_exists('next_dir')) {
/**
 * @return mixed
 */
    function next_dir($dir){ return ($dir === 'ASC') ? 'DESC' : 'ASC'; }
}

/* =========================================================
   관리자 비밀번호 검증 + 실패 시 alert 처리
   - admins.password_hash 컬럼의 해시를 자동 판별(bcrypt/sha256/md5)
   ========================================================= */
if (!function_exists('hash_equals_safe')) {
/**
 * @return mixed
 */
    function hash_equals_safe($a, $b){
        if (function_exists('hash_equals')) return hash_equals($a,$b);
        $a = (string)$a; $b = (string)$b;
        if (strlen($a) !== strlen($b)) return false;
        $res = 0; $len = strlen($a);
        for ($i=0; $i<$len; $i++) $res |= ord($a[$i]) ^ ord($b[$i]);
        return $res === 0;
    }
}
if (!function_exists('verify_with_hash')) {
/**
 * @return mixed
 */
    function verify_with_hash($plain, $stored){
        $stored = (string)$stored;
        // bcrypt($2y/$2a)
        if (strpos($stored, '$2') === 0) {
            $calc = crypt($plain, $stored);
            return hash_equals_safe($stored, $calc);
        }
        // sha256(hex 64)
        if (preg_match('/^[0-9a-f]{64}$/i', $stored)) {
            $calc = hash('sha256', $plain);
            return hash_equals_safe(strtolower($stored), strtolower($calc));
        }
        // md5(hex 32)
        if (preg_match('/^[0-9a-f]{32}$/i', $stored)) {
            $calc = md5($plain);
            return hash_equals_safe(strtolower($stored), strtolower($calc));
        }
        // 그 외: 평문 비교(레거시 대비)
        return hash_equals_safe($stored, $plain);
    }
}
if (!function_exists('require_admin_password_or_alert')) {
/**
 * @return mixed
 */
    function require_admin_password_or_alert($plain){
        $me = current_admin();
        if (!$me) {
            echo "<script>alert('로그인이 필요합니다.');location.href='login.php';</script>";
            exit;
        }

        // 로그인 때와 동일한 컬럼(예: admins.password_hash)
        $row = db_one("SELECT password_hash FROM admins WHERE id=? LIMIT 1", 's', array($me['id']));
        if (!$row) {
            echo "<script>alert('관리자 정보를 찾을 수 없습니다.');history.back();</script>";
            exit;
        }
        $stored = $row['password_hash'];

        // 1) 로그인에서 쓰는 레거시 검증기를 우선 사용
        $ok = false;
        if (function_exists('password_verify_legacy')) {
            $ok = password_verify_legacy($plain, $stored);
        } else {
            // 2) 폴백: "salt:iter:sha256hex" 포맷 수동 검증
            $parts = explode(':', $stored);
            if (count($parts) === 3) {
                $salt = $parts[0];
                $iter = (int)$parts[1];
                $hashHex = $parts[2];

                // 레거시 알고리즘 재현
                $h = hash('sha256', $salt.$plain, true);
                for ($i=0; $i<$iter; $i++) $h = hash('sha256', $h.$plain, true);
                $calc = $salt.':'.$iter.':'.bin2hex($h);

                if (function_exists('hash_equals')) {
                    $ok = hash_equals($stored, $calc);
                } else {
                    // 혹시 폴리필이 없다면(위에서 넣었으니 일반적으론 안탐)
                    $ok = ($stored === $calc);
                }
            }
        }

        if (!$ok) {
            echo "<script>alert('관리자 비밀번호가 올바르지 않습니다.');history.back();</script>";
            exit;
        }
    }
}


/* =========================================================
   라우팅
   ========================================================= */
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'list';
switch ($action) {
    case 'new':    products_new();  break;
    case 'view':   products_view(); break;
    case 'save':   products_save(); break;
    case 'delete': products_delete(); break; // 삭제 추가
    default:       products_list(); break;
}
exit;

/* =========================================================
   목록
   ========================================================= */
/**
 * @return mixed
 */
function products_list(){
    $me = current_admin();

    $category_id = isset($_GET['category_id']) ? trim($_GET['category_id']) : '';
    $sort = isset($_GET['sort']) ? $_GET['sort'] : 'created_at';
    $dir  = isset($_GET['dir'])  ? $_GET['dir']  : 'DESC';

    $allow = array('created_at','code','name','category_name');
    if (!in_array($sort, $allow)) $sort = 'created_at';
    $dir = ($dir==='ASC') ? 'ASC' : 'DESC';

    $categories = db_all("SELECT id, name FROM categories ORDER BY name ASC");

    $types=''; $params=array(); $where=array();
    if ($category_id !== '') { $where[]='p.category_id=?'; $types.='s'; $params[]=$category_id; }

    $sql = "
        SELECT p.id, p.code, p.name, p.created_at,
               c.name AS category_name
          FROM products p
     LEFT JOIN categories c ON c.id=p.category_id
    ";
    if (!empty($where)) $sql .= " WHERE ".implode(' AND ',$where);
    $sql .= " ORDER BY ".$sort." ".$dir.", p.id DESC";

    $rows = db_all($sql, ($types===''?null:$types), ($types===''?null:$params));

    render_header('상품 목록', $me);
    if (function_exists('flash_render')) flash_render();
    ?>
    <h2>상품</h2>
    <p><a class="btn primary" href="products.php?action=new">상품 등록</a></p>

    <form method="get" action="products.php" id="filterForm" class="grid" style="margin-bottom:10px">
      <input type="hidden" name="action" value="list" />
      <div class="row">
        <label>카테고리</label>
        <select name="category_id" onchange="document.getElementById('filterForm').submit();">
          <option value="">-- 전체 --</option>
          <?php foreach($categories as $c): ?>
            <option value="<?php echo h($c['id']); ?>" <?php echo ($category_id===$c['id']?'selected="selected"':''); ?>>
              <?php echo h($c['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <table class="table">
      <thead>
        <tr>
          <?php
            $q = $_GET;
/**
 * @return mixed
 */
            function TH_SORT($col, $title, $sort, $dir, $q){
                $nd = ($sort===$col ? next_dir($dir) : 'ASC');
                $q['sort']=$col; $q['dir']=$nd;
                $href = 'products.php?'.http_build_query($q,'','&');
                echo '<th><a href="'.h($href).'">'.h($title).'</a> '.icon_dir($col,$sort,$dir).'</th>';
            }
          ?>
          <?php TH_SORT('created_at','등록일',$sort,$dir,$q); ?>
          <?php TH_SORT('category_name','카테고리',$sort,$dir,$q); ?>
          <?php TH_SORT('code','상품코드',$sort,$dir,$q); ?>
          <?php TH_SORT('name','상품명',$sort,$dir,$q); ?>
          <th>작업</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5" class="muted">데이터가 없습니다.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?php echo h($r['created_at']); ?></td>
            <td><?php echo h($r['category_name']); ?></td>
            <td><code><?php echo h($r['code']); ?></code></td>
            <td><?php echo h($r['name']); ?></td>
            <td>
              <a class="btn" href="products.php?action=view&id=<?php echo h($r['id']); ?>">보기</a>
              <form method="post" action="products.php" class="inline" onsubmit="return confirm('정말 삭제할까요? 이 작업은 되돌릴 수 없습니다.');" style="margin-left:6px">
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="id" value="<?php echo h($r['id']); ?>"/>
                <input type="password" name="admin_password" placeholder="관리자 비번" style="width:140px" />
                <button class="btn" type="submit">삭제</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <?php
    render_footer();
}

/* =========================================================
   등록
   ========================================================= */
/**
 * @return mixed
 */
function products_new(){
    $me = current_admin();
    $categories = db_all("SELECT id, name FROM categories ORDER BY name ASC");

    $form = array('category_id'=>'', 'code'=>'', 'name'=>'');
    $error_msg = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $form['category_id'] = isset($_POST['category_id']) ? trim($_POST['category_id']) : '';
        $form['code']        = isset($_POST['code']) ? trim($_POST['code']) : '';
        $form['name']        = isset($_POST['name']) ? trim($_POST['name']) : '';

        $errs = array();
        if ($form['category_id']==='') $errs[]='카테고리';
        if ($form['code']==='')        $errs[]='상품코드';
        if ($form['name']==='')        $errs[]='상품명';

        if (!empty($errs)) {
            $error_msg = '필수 항목 누락: '.implode(', ', $errs);
        } else {
            $dup = db_one("SELECT id FROM products WHERE category_id=? AND code=? LIMIT 1",
                          'ss', array($form['category_id'],$form['code']));
            if ($dup) {
                $error_msg = '해당 카테고리에 동일한 상품코드가 이미 있습니다.';
            } else {
                try {
                    db_exec("INSERT INTO products (id, category_id, code, name, created_at)
                                    VALUES (?, ?, ?, ?, NOW())",
                            'ssss', array(uuid_v4(), $form['category_id'], $form['code'], $form['name']));
                    redirect_with('products.php', '상품을 등록했습니다.', 'ok');
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), '1062') !== false) {
                        $error_msg = '해당 카테고리에 동일한 상품코드가 이미 있습니다.';
                    } else {
                        $error_msg = '등록 중 오류: '.$e->getMessage();
                    }
                }
            }
        }
    }

    render_header('상품 등록', $me);
    if ($error_msg!=='') echo '<div class="error" style="margin:10px 0">'.h($error_msg).'</div>';
    else if (function_exists('flash_render')) flash_render();
    ?>
    <h2>상품 등록</h2>
    <p><a class="btn" href="products.php">← 목록</a></p>

    <form method="post" action="products.php?action=new" class="grid" onsubmit="return confirm('등록할까요?');">
      <div class="row">
        <label>카테고리 <span class="error">*</span></label>
        <select name="category_id">
          <option value="">-- 선택 --</option>
          <?php foreach($categories as $c): ?>
            <option value="<?php echo h($c['id']); ?>" <?php echo ($form['category_id']===$c['id']?'selected="selected"':''); ?>>
              <?php echo h($c['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row">
        <label>상품코드 <span class="error">*</span></label>
        <input type="text" name="code" value="<?php echo h($form['code']); ?>" />
        <div class="muted">※ 같은 카테고리에서 중복 불가</div>
      </div>

      <div class="row">
        <label>상품명 <span class="error">*</span></label>
        <input type="text" name="name" value="<?php echo h($form['name']); ?>" />
      </div>

      <div class="row">
        <button class="btn primary" type="submit">등록</button>
      </div>
    </form>
    <?php
    render_footer();
}

/* =========================================================
   보기/수정
   ========================================================= */
/**
 * @return mixed
 */
function products_view(){
    $me = current_admin();
    $id = isset($_GET['id']) ? trim($_GET['id']) : '';
    if ($id==='') redirect_with('products.php','잘못된 요청입니다.','error');

    $row = db_one("
        SELECT p.*, c.name AS category_name
          FROM products p
     LEFT JOIN categories c ON c.id=p.category_id
         WHERE p.id=?
         LIMIT 1
    ", 's', array($id));
    if (!$row) redirect_with('products.php','해당 상품이 없습니다.','error');

    $categories = db_all("SELECT id, name FROM categories ORDER BY name ASC");

    render_header('상품 보기', $me);
    if (function_exists('flash_render')) flash_render();
    ?>
    <h2>상품 보기</h2>
    <p><a class="btn" href="products.php">← 목록</a></p>

    <table class="table">
      <tr><th>등록일</th><td><?php echo h($row['created_at']); ?></td></tr>
      <tr><th>카테고리</th><td><?php echo h($row['category_name']); ?></td></tr>
      <tr><th>상품코드</th><td><code><?php echo h($row['code']); ?></code></td></tr>
      <tr><th>상품명</th><td><?php echo h($row['name']); ?></td></tr>
    </table>

    <h3 style="margin-top:15px">수정</h3>
    <form method="post" action="products.php" class="grid" onsubmit="return confirm('저장할까요?');">
      <input type="hidden" name="action" value="save"/>
      <input type="hidden" name="id" value="<?php echo h($row['id']); ?>"/>

      <div class="row">
        <label>카테고리</label>
        <select name="category_id">
          <?php foreach($categories as $c): ?>
            <option value="<?php echo h($c['id']); ?>" <?php echo ($row['category_id']===$c['id']?'selected="selected"':''); ?>>
              <?php echo h($c['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="row">
        <label>상품코드</label>
        <input type="text" name="code" value="<?php echo h($row['code']); ?>"/>
        <div class="muted">※ 같은 카테고리에서 중복 불가</div>
      </div>

      <div class="row">
        <label>상품명</label>
        <input type="text" name="name" value="<?php echo h($row['name']); ?>"/>
      </div>

      <div class="row">
        <button class="btn primary" type="submit">저장</button>
      </div>
    </form>
    <?php
    render_footer();
}

/* =========================================================
   저장
   ========================================================= */
/**
 * @return mixed
 */
function products_save(){
    $id          = isset($_POST['id']) ? trim($_POST['id']) : '';
    $category_id = isset($_POST['category_id']) ? trim($_POST['category_id']) : '';
    $code        = isset($_POST['code']) ? trim($_POST['code']) : '';
    $name        = isset($_POST['name']) ? trim($_POST['name']) : '';

    if ($id==='' || $category_id==='' || $code==='' || $name==='') {
        redirect_with('products.php?action=view&id='.$id, '필수 항목 누락', 'error');
    }

    $dup = db_one("
        SELECT id FROM products
         WHERE category_id=? AND code=? AND id<>?
         LIMIT 1
    ", 'sss', array($category_id, $code, $id));
    if ($dup) {
        redirect_with('products.php?action=view&id='.$id, '해당 카테고리에 동일한 상품코드가 이미 있습니다.', 'error');
    }

    try {
        db_exec("UPDATE products SET category_id=?, code=?, name=? WHERE id=?",
                'ssss', array($category_id, $code, $name, $id));
        redirect_with('products.php?action=view&id='.$id, '상품 정보를 저장했습니다.', 'ok');
    } catch (Exception $e) {
        if (strpos($e->getMessage(), '1062') !== false) {
            redirect_with('products.php?action=view&id='.$id, '해당 카테고리에 동일한 상품코드가 이미 있습니다.', 'error');
        }
        redirect_with('products.php?action=view&id='.$id, '저장 중 오류: '.$e->getMessage(), 'error');
    }
}

/* =========================================================
   삭제(관리자 비번 확인 + 참조 검사)
   ========================================================= */
/**
 * @return mixed
 */
function products_delete(){
    $id   = isset($_POST['id']) ? trim($_POST['id']) : '';
    $pass = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';

    if ($id==='' || $pass==='') {
        echo "<script>alert('ID 또는 관리자 비밀번호가 없습니다.');history.back();</script>";
        exit;
    }

    // 비밀번호 확인 (틀리면 alert 후 back)
    require_admin_password_or_alert($pass);

    // 참조 검사
    $cnt = db_one("SELECT COUNT(*) c FROM license_keys WHERE product_id=?", 's', array($id));
    if ($cnt && (int)$cnt['c'] > 0) {
        echo "<script>alert('해당 상품을 사용하는 라이선스가 있어 삭제할 수 없습니다.');history.back();</script>";
        exit;
    }

    try {
        db_exec("DELETE FROM products WHERE id=?", 's', array($id));
        echo "<script>alert('상품을 삭제했습니다.');location.href='products.php';</script>";
        exit;
    } catch (Exception $e) {
        echo "<script>alert('삭제 중 오류: ".h($e->getMessage())."');history.back();</script>";
        exit;
    }
}
