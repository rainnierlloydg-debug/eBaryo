<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$user=require_user(); $id=query_input('announcement');
if(!ctype_digit($id)) { http_response_code(404); exit; }
$sql='SELECT a.* FROM announcements a WHERE a.id=?'; $params=[(int)$id];
if(($user['role']??'resident')!=='admin') { $sql.=' AND EXISTS(SELECT 1 FROM notifications n WHERE n.announcement_id=a.id AND n.user_id=?)'; $params[]=$user['id']; }
$stmt=db()->prepare($sql); $stmt->execute($params); $item=$stmt->fetch();
$path=$item['attachment_path']??'';
if(!preg_match('#^announcements/[a-f0-9]{48}\.(pdf|jpg|png|docx)$#D',$path)||!is_file(__DIR__.'/uploads/'.$path)) { http_response_code(404); exit('Attachment not found.'); }
header('Content-Type: application/octet-stream');
header("Content-Disposition: attachment; filename=\"attachment.".pathinfo($path,PATHINFO_EXTENSION)."\"; filename*=UTF-8''".rawurlencode($item['attachment_name']));
readfile(__DIR__.'/uploads/'.$path);
