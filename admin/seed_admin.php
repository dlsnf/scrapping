<?php
require_once __DIR__.'/functions.php';

$username = 'owner';             // 원하는 아이디
$password = 'change_me_please';  // 원하는 초기 비밀번호
$role     = 'OWNER';

$exists = db_one("SELECT id FROM admins WHERE username=?", 's', array($username));
if ($exists) { die('이미 존재하는 사용자'); }

$id = uuid_v4();
$hash = password_hash_legacy($password);

db_exec("INSERT INTO admins (id,username,password_hash,role,is_active) VALUES (?,?,?,?,1)",
    'ssss', array($id,$username,$hash,$role));

echo "생성 완료: $username / $password (role=$role)\n";
