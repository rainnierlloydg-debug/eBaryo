<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
verify_csrf();
$action = text_input('action');

if ($action === 'register') {
    $values = [];
    foreach (['first_name', 'last_name', 'email', 'contact_number', 'barangay', 'sitio'] as $key) $values[$key] = text_input($key);
    remember_form('register', $values);
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $confirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';
    $valid = valid_text($values['first_name'], 75) && valid_text($values['last_name'], 75)
        && filter_var($values['email'], FILTER_VALIDATE_EMAIL) && strlen($values['email']) <= 255
        && valid_phone($values['contact_number']) && valid_text($values['barangay'], 100) && valid_text($values['sitio'], 100)
        && trim($password) !== '' && strlen($password) >= 8 && strlen($password) <= 72 && $password === $confirmation;
    if (!$valid) flash('Invalid', 'index.php?page=register', 'Complete every field, use a valid email and contact number, and matching passwords of 8–72 characters.');
    $check = db()->prepare('SELECT id FROM users WHERE email = :email');
    $check->execute(['email' => $values['email']]);
    if ($check->fetch()) flash('Invalid', 'index.php?page=register', 'This email address is already registered.');
    $front = $back = null;
    try {
        $front = upload_image('national_id_front', 'national-ids', 5 * 1024 * 1024);
        $back = upload_image('national_id_back', 'national-ids', 5 * 1024 * 1024);
        db()->beginTransaction();
        $insert = db()->prepare('INSERT INTO users (first_name, last_name, email, contact_number, barangay, sitio, national_id_front, national_id_back, password_hash, status) VALUES (:first_name, :last_name, :email, :contact_number, :barangay, :sitio, :front, :back, :password_hash, :status)');
        $insert->execute($values + ['front' => $front, 'back' => $back, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => 'Pending']);
        $preferences = db()->prepare('INSERT INTO user_preferences (user_id, email_updates, in_app_notifications) VALUES (:id, 1, 1)');
        $preferences->execute(['id' => (int) db()->lastInsertId()]);
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        remove_upload($front);
        remove_upload($back);
        if (!$exception instanceof InvalidArgumentException) error_log((string) $exception);
        $detail = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Registration could not be saved. Please try again with a unique email.';
        flash('Invalid', 'index.php?page=register', $detail);
    }
    unset($_SESSION['forms']['register']);
    flash('Registration Successful', 'index.php?page=login', 'Your account is Pending until the admin verifies your National ID.', 'index.php?page=register');
}

if ($action === 'login') {
    $email = text_input('email');
    remember_form('login', ['email' => $email]);
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || trim($password) === '') flash('Invalid', 'index.php?page=login');
    $statement = db()->prepare('SELECT * FROM users WHERE email = :email');
    $statement->execute(['email' => $email]);
    $account = $statement->fetch();
    if (!$account || !password_verify($password, $account['password_hash']) || $account['status'] !== 'Verified') flash('Invalid', 'index.php?page=login');
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $account['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    unset($_SESSION['forms'], $_SESSION['request_draft']);
    if (($account['role'] ?? 'resident') === 'admin') redirect('admin/index.php');
    flash('Login Success', 'index.php?page=dashboard', '', 'index.php?page=login');
}

$user = require_user();
$userId = (int) $user['id'];

if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    session_id('');
    session_start();
    session_regenerate_id(true);
    flash('Logout Successful', 'index.php?page=login');
}

if ($action === 'review_request') {
    $documentId = (int) text_input('document_id');
    $purpose = text_input('purpose');
    $additional = text_input('additional_information');
    remember_form('request', ['document_id' => $documentId, 'purpose' => $purpose, 'additional_information' => $additional]);
    $back = 'index.php?page=request&document='.$documentId;
    if (!valid_text($purpose, 2000)) flash('Invalid Input', $back.'&focus=purpose');
    if (!valid_text($additional, 3000)) flash('Invalid Input', $back.'&focus=additional_information');
    $document = fetch_document($documentId);
    if (!$document) flash("Document Doesn't Exist", 'index.php?page=request');
    $_SESSION['request_draft'] = ['user_id' => $userId, 'document' => $document, 'purpose' => $purpose, 'additional_information' => $additional, 'key' => bin2hex(random_bytes(32))];
    redirect($back.'&stage=summary');
}

if ($action === 'submit_request') {
    $draft = $_SESSION['request_draft'] ?? null;
    if (!$draft || $draft['user_id'] !== $userId || !hash_equals($draft['key'], text_input('submission_key'))) flash('Invalid Input', 'index.php?page=request');
    $document = $draft['document'];
    if (!fetch_document((int) $document['id'])) {
        unset($_SESSION['request_draft']);
        flash("Document Doesn't Exist", 'index.php?page=request');
    }
    try {
        db()->beginTransaction();
        $insert = db()->prepare('INSERT INTO document_requests (user_id, document_id, document_name, description, requirements, fee, processing_time, purpose, additional_information, status, submission_key) VALUES (:user_id, :document_id, :document_name, :description, :requirements, :fee, :processing_time, :purpose, :additional_information, :status, :submission_key)');
        $insert->execute([
            'user_id' => $userId, 'document_id' => $document['id'], 'document_name' => $document['name'],
            'description' => $document['description'], 'requirements' => $document['requirements'], 'fee' => $document['fee'],
            'processing_time' => $document['processing_time'], 'purpose' => $draft['purpose'], 'additional_information' => $draft['additional_information'],
            'status' => 'Pending', 'submission_key' => $draft['key'],
        ]);
        $requestId = (int) db()->lastInsertId();
        $notify = db()->prepare('INSERT INTO notifications (user_id, request_id, title, message) VALUES (:user_id, :request_id, :title, :message)');
        $notify->execute(['user_id' => $userId, 'request_id' => $requestId, 'title' => 'Request Submitted', 'message' => 'Your '.$document['name'].' request '.request_id($requestId).' has been submitted. Status: Pending.']);
        db()->commit();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        error_log((string) $exception);
        flash('Invalid Input', 'index.php?page=request&document='.(int) $document['id'].'&stage=summary', 'The request could not be saved. Please try again.');
    }
    unset($_SESSION['request_draft'], $_SESSION['forms']['request']);
    flash('Request Submitted', 'index.php?page=history');
}

if ($action === 'open_notification') {
    $statement = db()->prepare('SELECT id, title, message FROM notifications WHERE id = :id AND user_id = :user_id');
    $statement->execute(['id' => (int) text_input('notification_id'), 'user_id' => $userId]);
    $notification = $statement->fetch();
    if (!$notification) { http_response_code(404); exit('Notification not found.'); }
    $update = db()->prepare('UPDATE notifications SET read_at = COALESCE(read_at, CURRENT_TIMESTAMP) WHERE id = :id AND user_id = :user_id');
    $update->execute(['id' => $notification['id'], 'user_id' => $userId]);
    flash($notification['title'], 'index.php?page=notifications', $notification['message']);
}

if ($action === 'read_all') {
    $statement = db()->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = :user_id AND read_at IS NULL');
    $statement->execute(['user_id' => $userId]);
    redirect('index.php?page=notifications');
}

if ($action === 'save_profile') {
    $first = text_input('first_name');
    $last = text_input('last_name');
    $email = text_input('email');
    $phone = text_input('contact_number');
    remember_form('profile', ['first_name' => $first, 'last_name' => $last, 'email' => $email, 'contact_number' => $phone]);
    if (!valid_text($first, 75) || !valid_text($last, 75) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255 || !valid_phone($phone)) flash('Invalid', 'index.php?page=profile');
    $check = db()->prepare('SELECT id FROM users WHERE email = :email AND id <> :id');
    $check->execute(['email' => $email, 'id' => $userId]);
    if ($check->fetch()) flash('Invalid', 'index.php?page=profile');
    $newPhoto = null;
    try {
        $photoError = $_FILES['profile_picture']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($photoError !== UPLOAD_ERR_NO_FILE) $newPhoto = upload_image('profile_picture', 'profiles', 2 * 1024 * 1024);
        $statement = db()->prepare('UPDATE users SET first_name = :first_name, last_name = :last_name, email = :email, contact_number = :contact_number, profile_picture = :photo WHERE id = :id');
        $statement->execute(['first_name' => $first, 'last_name' => $last, 'email' => $email, 'contact_number' => $phone, 'photo' => $newPhoto ?? $user['profile_picture'], 'id' => $userId]);
    } catch (Throwable $exception) {
        remove_upload($newPhoto);
        if (!$exception instanceof InvalidArgumentException) error_log((string) $exception);
        flash('Invalid', 'index.php?page=profile');
    }
    if ($newPhoto) remove_upload($user['profile_picture']);
    unset($_SESSION['forms']['profile']);
    flash('Save Success', 'index.php?page=profile');
}

if ($action === 'change_password') {
    $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    if (trim($current) === '' || !password_verify($current, $user['password_hash']) || trim($password) === '' || strlen($password) < 8 || strlen($password) > 72 || $password !== $confirm) {
        flash('Invalid', 'index.php?page=settings&change_password=1');
    }
    $statement = db()->prepare('UPDATE users SET password_hash = :password WHERE id = :id');
    $statement->execute(['password' => password_hash($password, PASSWORD_DEFAULT), 'id' => $userId]);
    session_regenerate_id(true);
    flash('New Password Saved!', 'index.php?page=settings');
}

if ($action === 'save_preferences') {
    $email = text_input('email_updates') === '1' ? 1 : 0;
    $inApp = text_input('in_app_notifications') === '1' ? 1 : 0;
    $statement = db()->prepare('INSERT INTO user_preferences (user_id, email_updates, in_app_notifications) VALUES (:user_id, :email_updates, :in_app) ON DUPLICATE KEY UPDATE email_updates = VALUES(email_updates), in_app_notifications = VALUES(in_app_notifications)');
    $statement->execute(['user_id' => $userId, 'email_updates' => $email, 'in_app' => $inApp]);
    flash('Saved Preferences', 'index.php?page=settings');
}

http_response_code(400);
echo 'Invalid action.';
