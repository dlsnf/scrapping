<?php
require_once __DIR__.'/functions.php';
$username = 'owner';
$new = '새비밀번호여기에';

$row = db_one("SELECT id FROM admins WHERE username=?", 's', array($username));
if (!$row) die("사용자 없음");

$hash = password_hash_legacy($new);
db_exec("UPDATE admins SET password_hash=? WHERE id=?", 'ss', array($hash, $row['id']));
echo "비밀번호 변경 완료";
