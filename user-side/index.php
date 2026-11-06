<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';

$page = query_input('page') ?: 'landing';
$pages = [
    'landing' => 'Welcome', 'login' => 'Login', 'register' => 'Register',
    'dashboard' => 'Dashboard', 'request' => 'Request Document', 'history' => 'Request History',
    'notifications' => 'Notifications', 'profile' => 'My Profile', 'settings' => 'Settings',
];
if (!isset($pages[$page])) {
    http_response_code(404);
    exit('Page not found.');
}
$protected = !in_array($page, ['landing', 'login', 'register'], true);
$user = $protected ? require_user() : null;
$title = $pages[$page];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
require __DIR__.'/includes/header.php';
require __DIR__.'/pages/'.$page.'.php';
require __DIR__.'/includes/footer.php';
