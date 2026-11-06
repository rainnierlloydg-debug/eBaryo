<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/bootstrap.php';
require_once __DIR__.'/functions.php';
$admin = current_user();
if (!$admin || ($admin['role'] ?? '') !== 'admin' || $admin['status'] !== 'Verified') {
    redirect('../index.php?page=login');
}
