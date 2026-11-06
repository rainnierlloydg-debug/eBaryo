<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require __DIR__.'/../config/database.php';
$pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$applied = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
if ($applied) exit("Admin migration already started or applied. Check all tables against admin.sql before rerunning.\n");
$pdo->exec(file_get_contents(__DIR__.'/admin.sql'));
echo "Admin schema ready. Existing accounts remain residents.\n";
