<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = require __DIR__.'/../config/database.php';
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $config['database'])) throw new RuntimeException('Use a database name containing only letters, digits and underscores.');
$pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = str_replace('barangay_user_system', $config['database'], file_get_contents(__DIR__.'/schema.sql'));
$pdo->exec($sql);
echo 'Database schema and sample documents are ready: '.$config['database'].PHP_EOL;
