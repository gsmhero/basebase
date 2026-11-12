<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
require ROOT . '/src/helpers.php';
require ROOT . '/src/migrate.php';
require ROOT . '/src/mail.php';

set_exception_handler(function (Throwable $e) {
    http_response_code(500);
    @file_put_contents(ROOT . '/storage/error.log', date('c') . ' ' . $e . "\n", FILE_APPEND);
    if (APP_DEBUG) { echo '<pre style="padding:20px;white-space:pre-wrap">' . htmlspecialchars((string)$e) . '</pre>'; return; }
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><body style="font-family:sans-serif;padding:40px"><h1>Something went wrong</h1><p>Please try again in a moment.</p>';
});

run_migrations();

$path = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$seg = $path === '' ? [] : explode('/', $path);
$page = $seg[0] ?? 'home';
$pages = ROOT . '/src/pages';

$simple = ['home', 'faq', 'blogs', 'login', 'register', 'logout', 'cart', 'watch', 'product', 'shop', 'set', 'do', 'blog', 'invite', 'embed', 'reset'];
$file = null;
if ($page === 'products') $file = "$pages/catalog.php";
elseif (in_array($page, $simple, true)) $file = "$pages/$page.php";
elseif ($page === 'cabinet') {
    $sub = $seg[1] ?? 'dash';
    if (in_array($sub, ['dash', 'products', 'collection', 'orders', 'stats', 'messenger', 'profile', 'settings', 'notifications', 'blog', 'team', 'agent'], true)) $file = "$pages/cabinet/$sub.php";
} elseif ($page === 'admin') {
    $sub = $seg[1] ?? 'users';
    if (in_array($sub, ['users', 'seed', 'mail', 'agents'], true)) $file = "$pages/admin/$sub.php";
}

if (!$file || !is_file($file)) {
    http_response_code(404);
    layout('Page not found', function () { ?>
        <section class="wrap empty"><div class="big404">404</div><h2>Page not found</h2><p>The page you are looking for does not exist.</p><a class="btn dark" href="/">Back to home</a></section>
    <?php });
    exit;
}
require $file;
