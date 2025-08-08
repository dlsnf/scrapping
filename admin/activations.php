<?php
require_once __DIR__.'/auth.php';
require_login();
$me = current_admin();
csrf_check();

$license_id = isset($_GET['license_id']) ? $_GET['license_id'] : null;
$act = isset($_GET['act']) ? $_GET['act'] : null;
$id = isset($_GET['id']) ? $_GET['id'] : null;

if ($act==='deactivate' && $id && $license_id){
    $before = db_one("SELECT * FROM license_activations WHERE id=?", 's', array($id));
    db_exec("UPDATE license_activations SET deactivated_at=NOW() WHERE id=?", 's', array($id));
    $after = db_one("SELECT * FROM license_activations WHERE id=?", 's', array($id));
    log_event($license_id,'DEACTIVATE',$me['id'],$me['username'],$_SERVER['REMOTE_ADDR'], json_encode($before), json_encode($after));
    header('Location: activations.php?license_id='.urlencode($license_id)); exit;
}

$lic = db_one("SELECT `key` FROM license_keys WHERE id=?", 's', array($license_id));
$rows = db_all("SELECT * FROM license_activations WHERE license_id=? ORDER BY activated_at DESC", 's', array($license_id));

render_header('활성화 기록', $me);
?>
<h2>활성화 기록 - <?php echo h($lic['key']); ?></h2>
<p><a class="btn" href="licenses.php?action=view&id=<?php echo h($license_id); ?>">← 라이선스</a></p>
<table class="table">
<tr><th>Device</th><th>Host</th><th>IP</th><th>Activated</th><th>Deactivated</th><th>Active?</th><th></th></tr>
<?php foreach($rows as $r){
    $active = $r['is_active'] ? 'YES' : 'NO';
    echo '<tr>';
    echo '<td>'.h($r['device_fingerprint']).'</td>';
    echo '<td>'.h($r['hostname']).'</td>';
    echo '<td>'.h($r['ip']).'</td>';
    echo '<td>'.h($r['activated_at']).'</td>';
    echo '<td>'.h($r['deactivated_at']).'</td>';
    echo '<td>'.$active.'</td>';
    echo '<td>';
    if ($r['is_active']) {
        echo '<a class="btn" href="activations.php?act=deactivate&license_id='.h($license_id).'&id='.h($r['id']).'">비활성화</a>';
    }
    echo '</td>';
    echo '</tr>';
} ?>
</table>
<?php render_footer(); ?>
