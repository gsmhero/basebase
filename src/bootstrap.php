<?php
declare(strict_types=1);

function load_env(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v, " \t\"'");
    }
}
function env(string $key, string $default = ''): string {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}
function db(): PDO {
    static $pdo;
    return $pdo ??= new PDO(
        'mysql:host=' . env('DB_HOST') . ';dbname=' . env('DB_NAME') . ';charset=utf8mb4',
        env('DB_USER'), env('DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}
load_env(__DIR__ . '/../.env');
