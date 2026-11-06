<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }
verify_csrf();
$action = text_input('action'); $back = 'index.php'; $attachment = null;
try {
    db()->beginTransaction();
    switch ($action) {
        case 'logout':
            audit('logout','session',null); db()->commit();
            $_SESSION=[]; session_destroy(); session_id(''); session_start(); session_regenerate_id(true);
            flash('Logout Successful','../index.php?page=login');
        case 'review':
        case 'update_request':
        case 'save_document_content':
            $id = post_id(); $back='index.php?page=requests&open='.$id;
            $request = rows('SELECT * FROM document_requests WHERE id=? FOR UPDATE',[$id])[0] ?? null;
            assert_valid((bool)$request,'Request not found.');
            if ($action === 'review') {
                $status=text_input('status'); $reason=text_input('reason');
                assert_valid(in_array($status,['Approved','Rejected'],true) && ($reason==='' || valid_text($reason,3000)));
                run('UPDATE document_requests SET status=?, review_reason=? WHERE id=?',[$status,$reason,$id]);
                $changes=['status'=>['before'=>$request['status'],'after'=>$status],'reason'=>$reason];
                $message='Your request '.request_id($id).' is '.$status.'.'.($reason!==''?' Reason: '.$reason:'');
            } elseif ($action === 'save_document_content') {
                $back='index.php?page=editor&id='.$id;
                $content=clean_document(text_input('content')); assert_valid(trim(strip_tags($content))!=='','Enter document content.');
                $version=text_input('version');
                assert_valid(hash_equals(hash('sha256',($request['document_content'] ?? '').$request['updated_at']),$version),'Another update was saved. Reload the editor before saving.');
                run('UPDATE document_requests SET document_content=? WHERE id=?',[$content,$id]);
                $changes=['content_before'=>$request['document_content'],'content_after'=>$content,'mode'=>text_input('mode')];
                $message='The document for '.request_id($id).' has been updated.';
                if (text_input('mode')==='print') $back.='&print=1';
            } else {
                $status=text_input('status'); $processing=text_input('processing');
                assert_valid(in_array($status,REQUEST_STATUSES,true));
                assert_valid(in_array($processing,['','Processing','Cancel'],true));
                if ($processing==='Processing') $status='Processing';
                if ($processing==='Cancel') $status='Cancelled';
                $payment=text_input('payment_status'); $amount=text_input('payment_amount');
                $pickup=text_input('pickup_option'); $delivery=text_input('delivery_option');
                assert_valid(in_array($payment,['Paid','Not Paid'],true) && money_valid($amount) && in_array($pickup,['Pick up','Not needed'],true) && in_array($delivery,['Delivery','Not needed'],true));
                $values=[$status,$payment,$amount,date_input('pickup_date'),date_input('processing_date'),$pickup,$delivery,$id];
                run('UPDATE document_requests SET status=?, payment_status=?, payment_amount=?, pickup_date=?, processing_date=?, pickup_option=?, delivery_option=? WHERE id=?',$values);
                $changes=[];
                foreach (['status','payment_status','payment_amount','pickup_date','processing_date','pickup_option','delivery_option'] as $i=>$key) $changes[$key]=['before'=>$request[$key],'after'=>$values[$i]];
                $message='Request '.request_id($id).': '.$status.'. Payment: '.$payment.' (PHP '.$amount.'). Pickup: '.$pickup.($values[3]?' on '.$values[3]:'').'. Processing date: '.($values[4]??'Not set').'. Delivery: '.$delivery.'.';
            }
            audit($action,'document_request',$id,$changes);
            notify_resident((int)$request['user_id'],'Request Updated',$message,$id);
            $success=$action==='save_document_content'?(text_input('mode')==='print'?'Saved Successfully':'Saved Successfully'):($action==='review'?'Message Updated':'Updated Successfully');
            break;
        case 'registration':
            $back='index.php?page=residents'; $id=post_id(); $decision=text_input('status');
            assert_valid(in_array($decision,['Approved','Rejected'],true));
            $resident=rows('SELECT * FROM users WHERE id=? AND role=? FOR UPDATE',[$id,'resident'])[0]??null;
            assert_valid((bool)$resident && $resident['status']==='Pending','This registration is no longer pending.');
            $status=$decision==='Approved'?'Verified':'Rejected';
            run('UPDATE users SET status=? WHERE id=?',[$status,$id]);
            audit('registration','user',$id,['before'=>$resident['status'],'after'=>$status]);
            notify_resident($id,'Registration '.$decision,'Your registration has been '.strtolower($decision).'.');
            $back.='&status='.urlencode($decision); $success='Updated Successfully'; break;
        case 'save_document':
            $back='index.php?page=documents'; $id=text_input('id')===''?null:post_id();
            $name=text_input('name'); $description=text_input('description'); $purpose=text_input('purpose'); $fee=text_input('fee'); $requirements=text_input('requirements'); $time=text_input('processing_time');
            assert_valid(valid_text($name,150)&&valid_text($description,10000)&&valid_text($purpose,5000)&&money_valid($fee)&&valid_text($requirements,10000)&&valid_text($time,150));
            $old=$id ? (rows('SELECT * FROM documents WHERE id=? FOR UPDATE',[$id])[0]??null) : null;
            assert_valid(!$id || (bool)$old,'Document not found.');
            assert_valid(!rows('SELECT id FROM documents WHERE name=? AND id<>?',[$name,$id??0]),'Document name already exists.');
            if ($id) run('UPDATE documents SET name=?,description=?,purpose=?,fee=?,requirements=?,processing_time=? WHERE id=?',[$name,$description,$purpose,$fee,$requirements,$time,$id]);
            else { run('INSERT INTO documents(name,description,purpose,fee,requirements,processing_time) VALUES(?,?,?,?,?,?)',[$name,$description,$purpose,$fee,$requirements,$time]); $id=(int)db()->lastInsertId(); }
            audit('save_document','document',$id,['before'=>$old,'after'=>compact('name','description','purpose','fee','requirements','time')]);
            $success=$old?'Updated':'Added to Documents'; break;
        case 'deactivate':
        case 'delete':
        case 'activate':
            $back='index.php?page=documents'; $id=post_id();
            $old=rows('SELECT * FROM documents WHERE id=? FOR UPDATE',[$id])[0]??null;
            assert_valid((bool)$old,'Document not found.');
            if ($action==='delete') run('DELETE FROM documents WHERE id=?',[$id]);
            else run('UPDATE documents SET is_active=? WHERE id=?',[$action==='activate'?1:0,$id]);
            audit($action,'document',$id,['before'=>$old]);
            $success=['delete'=>'Deleted Successful','activate'=>'Updated','deactivate'=>'Deactivated Successful'][$action]; break;
        case 'send_notification':
            $back='index.php?page=notifications';
            $title=text_input('title'); $message=text_input('message'); $type=text_input('recipient_type'); $priority=post_id('priority_id');
            assert_valid(valid_text($title,150)&&valid_text($message,10000)&&in_array($type,['All','Specific','Custom'],true));
            assert_valid((bool)rows('SELECT id FROM notification_priorities WHERE id=?',[$priority]));
            $recipients=rows("SELECT id FROM users WHERE role='resident'");
            if ($type!=='All') {
                $selected=$_POST['recipients']??[]; assert_valid(is_array($selected)&&count($selected)>0&&count($selected)<=10000,'Choose at least one resident.');
                foreach ($selected as $value) assert_valid(is_string($value)&&ctype_digit($value));
                $selected=array_unique(array_map('intval',$selected));
                $validIds=array_map('intval',array_column($recipients,'id'));
                assert_valid(!array_diff($selected,$validIds),'A selected resident is unavailable.');
                $recipients=array_map(fn($id)=>['id'=>$id],$selected);
            }
            assert_valid(count($recipients)>0,'No resident recipients found.');
            $path=$filename=null;
            if (($_FILES['attachment']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                $file=$_FILES['attachment'];
                assert_valid($file['error']===UPLOAD_ERR_OK&&is_uploaded_file($file['tmp_name'])&&$file['size']>0&&$file['size']<=5*1024*1024,'Attach a PDF, JPG, PNG or DOCX up to 5 MB.');
                $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                $types=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx'];
                assert_valid(isset($types[$mime]),'Unsupported attachment type.');
                $filename=basename(str_replace('\\','/',(string)$file['name'])); assert_valid(valid_text($filename,255));
                $path='announcements/'.bin2hex(random_bytes(24)).'.'.$types[$mime];
                $directory=__DIR__.'/../uploads/announcements';
                if (!is_dir($directory)) mkdir($directory,0700,true);
                $attachment=__DIR__.'/../uploads/'.$path;
                if (!move_uploaded_file($file['tmp_name'],$attachment)) throw new RuntimeException('Upload failed.');
            }
            run('INSERT INTO announcements(admin_id,title,message,recipient_type,priority_id,attachment_path,attachment_name) VALUES(?,?,?,?,?,?,?)',[$admin['id'],$title,$message,$type,$priority,$path,$filename]);
            $id=(int)db()->lastInsertId();
            foreach ($recipients as $recipient) notify_resident((int)$recipient['id'],$title,$message.($path?"\nAn attachment is available in your notifications.":''),null,$id);
            audit('send_notification','announcement',$id,['recipient_ids'=>array_column($recipients,'id'),'priority_id'=>$priority,'title'=>$title]);
            $success='Notification Sent'; break;
        case 'read_report':
        case 'read_reports':
            $back='index.php?page=reports';
            if ($action==='read_report') {
                $id=post_id(); assert_valid((bool)rows('SELECT id FROM document_requests WHERE id=?',[$id]));
                run('INSERT IGNORE INTO admin_request_reads(admin_id,request_id) VALUES(?,?)',[$admin['id'],$id]);
                $back.='&open='.$id;
            } else {
                $ids=$_POST['ids']??[]; assert_valid(is_array($ids)&&count($ids)<=10000);
                foreach ($ids as $id) { assert_valid(is_string($id)&&ctype_digit($id)); run('INSERT IGNORE INTO admin_request_reads(admin_id,request_id) SELECT ?,id FROM document_requests WHERE id=?',[$admin['id'],(int)$id]); }
            }
            audit($action,'report',isset($id)?(int)$id:null); db()->commit(); redirect($back);
        case 'change_password':
            $back='index.php?page=settings';
            $current=is_string($_POST['current_password']??null)?$_POST['current_password']:'';
            $password=is_string($_POST['new_password']??null)?$_POST['new_password']:'';
            $confirm=is_string($_POST['confirm_password']??null)?$_POST['confirm_password']:'';
            assert_valid(password_verify($current,$admin['password_hash'])&&trim($password)!==''&&strlen($password)>=8&&strlen($password)<=72&&$password===$confirm);
            run('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$admin['id']]);
            audit('change_password','user',(int)$admin['id']); session_regenerate_id(true); $success='New Password Saved!'; break;
        case 'save_preferences':
            $back='index.php?page=settings'; $email=text_input('email_updates')==='1'?1:0; $inApp=text_input('in_app_notifications')==='1'?1:0;
            run('INSERT INTO user_preferences(user_id,email_updates,in_app_notifications) VALUES(?,?,?) ON DUPLICATE KEY UPDATE email_updates=VALUES(email_updates),in_app_notifications=VALUES(in_app_notifications)',[$admin['id'],$email,$inApp]);
            audit('save_preferences','user_preferences',(int)$admin['id'],['email_updates'=>$email,'in_app_notifications'=>$inApp]); $success='Save Success'; break;
        default: throw new InvalidArgumentException('Unknown action.');
    }
    db()->commit(); flash($success,$back);
} catch (Throwable $exception) {
    if (db()->inTransaction()) db()->rollBack();
    if ($attachment && is_file($attachment)) unlink($attachment);
    if (!$exception instanceof InvalidArgumentException) error_log((string)$exception);
    flash('Invalid',$back,$exception instanceof InvalidArgumentException?$exception->getMessage():'The change could not be saved. Please try again.');
}
