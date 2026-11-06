<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$page = query_input('page') ?: 'dashboard';
$pages = ['dashboard'=>'Admin Dashboard','requests'=>'Document Request','residents'=>'Manage Residents','documents'=>'Manage Documents','notifications'=>'Notification','reports'=>'Reports','settings'=>'Admin Settings','editor'=>'Document Editor'];
if (!isset($pages[$page])) { http_response_code(404); exit('Page not found.'); }
$title = $pages[$page];
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
require __DIR__.'/includes/header.php';
require __DIR__.'/pages/'.$page.'.php';
require __DIR__.'/includes/footer.php';
