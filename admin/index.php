<?php
require_once __DIR__.'/auth.php';
require_login();
$me = current_admin();

/* ---------- 간단 카운트 헬퍼 (PHP 5.3 호환) ---------- */
function cnt($sql){
    $r = db_one($sql);
    return isset($r['c']) ? (int)$r['c'] : 0;
}

/* ---------- 상단 요약 ---------- */
$counts = array();
$counts['licenses']  = cnt("SELECT COUNT(*) c FROM license_keys");
$counts['active']    = cnt("SELECT COUNT(*) c FROM license_keys WHERE status='ACTIVE'");
$counts['revoked']   = cnt("SELECT COUNT(*) c FROM license_keys WHERE status='REVOKED'");
$counts['expired']   = cnt("SELECT COUNT(*) c FROM license_keys WHERE status='EXPIRED'");
$counts['customers'] = cnt("SELECT COUNT(*) c FROM customers");
$counts['products']  = cnt("SELECT COUNT(*) c FROM products");

/* ---------- 카테고리별 통계 ---------- */
$sql = "
SELECT
  cg.id,
  cg.name,
  COUNT(lk.id)                                         AS total_cnt,
  SUM(lk.status='ACTIVE')                              AS active_cnt,
  SUM(lk.status='ISSUED')                              AS issued_cnt,
  SUM(lk.status='REVOKED')                             AS revoked_cnt,
  SUM(lk.status='EXPIRED')                             AS expired_cnt,
  (SELECT COUNT(*) FROM products  p  WHERE p.category_id=cg.id)   AS product_cnt,
  (SELECT COUNT(*) FROM customers cu WHERE cu.category_id=cg.id)  AS customer_cnt
FROM categories cg
LEFT JOIN license_keys lk ON lk.category_id=cg.id
GROUP BY cg.id, cg.name
ORDER BY cg.name ASC";
$cat_stats = db_all($sql);

render_header('대시보드', $me);
?>
<h2>대시보드</h2>

<ul>
  <li>전체 라이선스: <?php echo (int)$counts['licenses']; ?></li>
  <li>ACTIVE: <?php echo (int)$counts['active']; ?> / REVOKED: <?php echo (int)$counts['revoked']; ?> / EXPIRED: <?php echo (int)$counts['expired']; ?></li>
  <li>고객: <?php echo (int)$counts['customers']; ?> / 상품: <?php echo (int)$counts['products']; ?></li>
</ul>

<h3>카테고리별 통계</h3>
<div class="table-wrap">
<table class="table">
  <tr>
    <th>카테고리</th>
    <th>전체</th>
    <th>사용</th>
    <th>미사용</th>
    <th>폐기</th>
    <th>만료</th>
    <th>상품 수</th>
    <th>고객 수</th>
  </tr>
  <?php
  if (!empty($cat_stats)) {
      foreach ($cat_stats as $r) {
          echo '<tr>';
          echo '<td>'.h($r['name']).'</td>';
          echo '<td>'.(int)$r['total_cnt'].'</td>';
          echo '<td>'.(int)$r['active_cnt'].'</td>';
          echo '<td>'.(int)$r['issued_cnt'].'</td>';
          echo '<td>'.(int)$r['revoked_cnt'].'</td>';
          echo '<td>'.(int)$r['expired_cnt'].'</td>';
          echo '<td>'.(int)$r['product_cnt'].'</td>';
          echo '<td>'.(int)$r['customer_cnt'].'</td>';
          echo '</tr>';
      }
  } else {
      echo '<tr><td colspan="8" class="muted">카테고리가 없습니다.</td></tr>';
  }
  ?>
</table>
</div>

<?php render_footer(); ?>
