<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$id=query_input('resident'); $side=query_input('side');
if(!ctype_digit($id)||!in_array($side,['front','back'],true)) { http_response_code(404); exit; }
$resident=rows("SELECT national_id_front,national_id_back FROM users WHERE id=? AND role='resident'",[(int)$id])[0]??null;
$path=$resident['national_id_'.$side]??'';
if(!preg_match('#^national-ids/[a-f0-9]{48}\.(jpg|png)$#D',$path)||!is_file(__DIR__.'/../uploads/'.$path)) { http_response_code(404); exit('ID image not available.'); }
audit('view_national_id','user',(int)$id,['side'=>$side]);
header('Content-Type: '.(str_ends_with($path,'.png')?'image/png':'image/jpeg'));
header('Content-Disposition: inline'); readfile(__DIR__.'/../uploads/'.$path);
