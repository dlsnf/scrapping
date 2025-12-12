<?php
require_once __DIR__.'/functions.php';
require_once __DIR__.'/auth.php';

$err = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    $username = trim($_POST['username']);
    $password = (string)$_POST['password'];

    $row = db_one("SELECT * FROM admins WHERE username=? AND is_active=1", 's', array($username));
    if ($row && password_verify_legacy($password, $row['password_hash'])) {
        $_SESSION['admin'] = array(
            'id'=>$row['id'],
            'username'=>$row['username'],
            'role'=>$row['role']
        );
        db_exec("UPDATE admins SET last_login_at=NOW() WHERE id=?", 's', array($row['id']));
        header('Location: index.php'); exit;
    } else {
        $err = '아이디 또는 비밀번호가 올바르지 않습니다.';
    }
}

render_header('로그인');
?>
<h2>로그인</h2>
<?php if ($err): ?><p class="error"><?php echo h($err); ?></p><?php endif; ?>
<form method="post">
    <input type="hidden" name="<?php echo h(CSRF_TOKEN_NAME); ?>" value="<?php echo h(csrf_token()); ?>">
    <div class="row"><input type="text" name="username" placeholder="아이디"></div>
    <div class="row"><input type="password" name="password" placeholder="비밀번호"></div>
    <button class="btn primary" type="submit">로그인</button>
</form>
<?php render_footer(); ?>
