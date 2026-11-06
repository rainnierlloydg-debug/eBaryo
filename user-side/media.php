<?php
declare(strict_types=1);
require __DIR__.'/includes/bootstrap.php';
$user = require_user();
$path = $user['profile_picture'];
if (!$path || !preg_match('#^profiles/[a-f0-9]{48}\.(jpg|png)$#D', $path)) { http_response_code(404); exit; }
$file = __DIR__.'/uploads/'.$path;
if (!is_file($file)) { http_response_code(404); exit; }
header('Content-Type: '.(str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg'));
header('Content-Length: '.filesize($file));
readfile($file);
