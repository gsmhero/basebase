<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

function load_env(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v, " \t\"'\r");
    }
}
function env(string $key, string $default = ''): string {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : (string)$v;
}
function db(): PDO {
    static $pdo;
    return $pdo ??= new PDO(
        'mysql:host=' . env('DB_HOST') . ';dbname=' . env('DB_NAME') . ';charset=utf8mb4',
        env('DB_USER'), env('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

load_env(ROOT . '/.env');
define('APP_DEBUG', env('APP_DEBUG') === '1');
ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Moscow');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_name('bb');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
}
