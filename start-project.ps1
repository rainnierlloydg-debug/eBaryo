$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot

$entryPoint = @'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
'@

if (-not (Test-Path -LiteralPath 'public/index.php')) {
    try {
        Set-Content -LiteralPath 'public/index.php' -Value $entryPoint -Encoding ASCII
    } catch {
        throw 'Cannot restore public/index.php. Avast quarantined this file as IDP.Generic. Release this specific file in Avast Quarantine, then run this script again.'
    }
}

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP was not found. Run this script from the Laragon terminal.'
}

if (-not (Test-Path -LiteralPath 'vendor/autoload.php')) {
    throw 'Dependencies are missing. Run composer install first.'
}

$routerPath = 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
if (-not (Test-Path -LiteralPath $routerPath)) {
    $router = @'
<?php

$publicPath = getcwd();
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}

require_once $publicPath.'/index.php';
'@
    Set-Content -LiteralPath $routerPath -Value $router -Encoding ASCII
}

php -l public/index.php
if ($LASTEXITCODE -ne 0) { throw 'The PHP entry point has a syntax error.' }

php artisan config:clear
if ($LASTEXITCODE -ne 0) { throw 'Laravel could not load its configuration.' }

if (-not (Test-Path -LiteralPath 'public/build/manifest.json')) {
    npm.cmd run build
    if ($LASTEXITCODE -ne 0) { throw 'The frontend build failed.' }
}

Write-Host 'Open http://127.0.0.1:8000 after the server starts.'
php artisan serve --host=127.0.0.1 --port=8000
