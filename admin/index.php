<?php
require_once __DIR__.'/auth.php';
require_login();
$me = current_admin();

$counts = array();

$row = db_one("SELECT COUNT(*) c FROM license_keys");
$counts['licenses'] = $row['c'];

$row = db_one("SELECT COUNT(*) c FROM license_keys WHERE status='ACTIVE'");
$counts['active'] = $row['c'];

$row = db_one("SELECT COUNT(*) c FROM license_keys WHERE status='REVOKED'");
$counts['revoked'] = $row['c'];

$row = db_one("SELECT COUNT(*) c FROM license_keys WHERE status='EXPIRED'");
$counts['expired'] = $row['c'];

$row = db_one("SELECT COUNT(*) c FROM customers");
$counts['customers'] = $row['c'];

$row = db_one("SELECT COUNT(*) c FROM products");
$counts['products'] = $row['c'];

render_header('대시보드', $me);
?>
<h2>대시보드</h2>
<ul>
  <li>전체 라이선스: <?php echo (int)$counts['licenses']; ?></li>
  <li>ACTIVE: <?php echo (int)$counts['active']; ?> / REVOKED: <?php echo (int)$counts['revoked']; ?> / EXPIRED: <?php echo (int)$counts['expired']; ?></li>
  <li>고객: <?php echo (int)$counts['customers']; ?> / 상품: <?php echo (int)$counts['products']; ?></li>
</ul>
<p><a class="btn primary" href="licenses.php?action=new">라이선스 발급</a></p>
<?php render_footer(); ?>
