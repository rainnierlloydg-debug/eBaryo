<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_name('barangay_user_session');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");

require_once __DIR__.'/functions.php';

function db(): PDO
{
    static $connection;
    if ($connection instanceof PDO) return $connection;
    $config = require __DIR__.'/../config/database.php';
    $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4';
    $connection = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $connection;
}

set_exception_handler(function (Throwable $exception): void {
    error_log((string) $exception);
    http_response_code(500);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>System unavailable</title><h1>System unavailable</h1><p>Please try again shortly. For a new installation, import database/schema.sql and check config/database.php.</p></html>';
});
