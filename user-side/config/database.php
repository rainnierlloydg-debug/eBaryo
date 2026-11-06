<?php
declare(strict_types=1);

return [
    'host' => getenv('BARANGAY_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('BARANGAY_DB_PORT') ?: '3306',
    'database' => getenv('BARANGAY_DB_NAME') ?: 'barangay_user_system',
    'username' => getenv('BARANGAY_DB_USER') ?: 'root',
    'password' => getenv('BARANGAY_DB_PASSWORD') ?: '',
];
