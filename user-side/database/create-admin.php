<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/../includes/bootstrap.php';
$email=$argv[1]??'';
if (!filter_var($email,FILTER_VALIDATE_EMAIL)) exit("Usage: php database/create-admin.php existing-approved-email@example.com\n");
$stmt=db()->prepare("SELECT id FROM users WHERE email=? AND status='Verified'"); $stmt->execute([$email]); $id=$stmt->fetchColumn();
if(!$id) exit("Register an account and verify its identity first. Set that specific account's status to Verified using your database administrator.\n");
db()->beginTransaction();
$stmt=db()->prepare("UPDATE users SET role='admin' WHERE id=?"); $stmt->execute([$id]);
$stmt=db()->prepare("INSERT INTO admin_audit_logs(admin_id,action,entity_type,entity_id,changes) VALUES(?,'provision_admin','user',?,?)");
$stmt->execute([$id,$id,json_encode(['source'=>'local CLI','role'=>'admin'])]);
db()->commit(); echo "Admin access granted to the specified account. Sign in through the shared Login page.\n";
