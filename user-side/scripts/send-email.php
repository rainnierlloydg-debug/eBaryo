<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/../includes/bootstrap.php';
$sender=getenv('BARANGAY_MAIL_FROM')?:'';
if(!filter_var($sender,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$sender)) exit("Configure BARANGAY_MAIL_FROM and PHP's SMTP/sendmail settings before running this worker.\n");
// One worker at a time: MySQL advisory lock also covers multiple machines.
if(!(int)db()->query("SELECT GET_LOCK('barangay_email_worker',0)")->fetchColumn()) exit("Another worker is active.\n");
try {
    $items=db()->query('SELECT * FROM email_outbox WHERE sent_at IS NULL AND attempts<5 ORDER BY id LIMIT 100')->fetchAll();
    foreach($items as $item) {
        $update=db()->prepare('UPDATE email_outbox SET attempts=attempts+1,last_error=? WHERE id=?');
        $preferences=db()->prepare('SELECT email_updates FROM user_preferences WHERE user_id=?'); $preferences->execute([$item['user_id']]);
        if($preferences->fetchColumn()===0) { $update->execute(['Email preference disabled before delivery.',$item['id']]); continue; }
        $subject='=?UTF-8?B?'.base64_encode($item['subject']).'?=';
        try { $sent=mail($item['recipient'],$subject,$item['body'],['From'=>$sender,'Content-Type'=>'text/plain; charset=UTF-8']); }
        catch(Throwable $e) { $sent=false; error_log((string)$e); }
        $update->execute([$sent?null:'Mail transport did not accept this email.',$item['id']]);
        if($sent) { $update=db()->prepare('UPDATE email_outbox SET sent_at=CURRENT_TIMESTAMP WHERE id=?'); $update->execute([$item['id']]); }
    }
    echo "Processed ".count($items)." queued emails.\n";
} finally { db()->query("SELECT RELEASE_LOCK('barangay_email_worker')"); }
