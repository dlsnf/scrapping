<?php
require_once __DIR__.'/auth.php';
require_login();
require_role(array('OWNER','ADMIN','OPERATOR','READONLY'));
$me = current_admin();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

render_header('감사 로그', $me);

if ($q) {
    $rows = db_all("SELECT e.*, lk.`key`
        FROM license_events e
        JOIN license_keys lk ON e.license_id=lk.id
        WHERE lk.`key` LIKE CONCAT('%',?,'%') OR e.actor LIKE CONCAT('%',?,'%')
        ORDER BY e.created_at DESC LIMIT 500", 'ss', array($q,$q));
} else {
    $rows = db_all("SELECT e.*, lk.`key`
        FROM license_events e
        JOIN license_keys lk ON e.license_id=lk.id
        ORDER BY e.created_at DESC LIMIT 500");
}
?>
<h2>감사 로그</h2>
<form method="get">
  <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="키/사용자 검색">
  <button class="btn">검색</button>
</form>
<table class="table">
<tr><th>시간</th><th>키</th><th>이벤트</th><th>액터</th><th>IP</th><th>Before</th><th>After</th></tr>
<?php foreach($rows as $r){
    echo '<tr>';
    echo '<td>'.h($r['created_at']).'</td>';
    echo '<td><code>'.h($r['key']).'</code></td>';
    echo '<td>'.h($r['event_type']).'</td>';
    echo '<td>'.h($r['actor']).'</td>';
    echo '<td>'.h($r['request_ip']).'</td>';
    echo '<td><pre>'.h($r['before']).'</pre></td>';
    echo '<td><pre>'.h($r['after']).'</pre></td>';
    echo '</tr>';
} ?>
</table>
<?php render_footer(); ?>
