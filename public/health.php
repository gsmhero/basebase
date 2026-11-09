<?php
// Connection check: DB + SMTP reachability. Reveals no secrets.
require __DIR__ . '/../src/bootstrap.php';
header('Content-Type: application/json');
$r = ['php' => PHP_VERSION];
try { db()->query('SELECT 1'); $r['db'] = 'ok'; } catch (Throwable $e) { $r['db'] = 'fail'; }
try {
    $have = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $need = ['users', 'vendors', 'suppliers', 'products', 'orders', 'order_items'];
    $r['tables'] = array_values(array_diff($need, $have)) ?: 'ok';
} catch (Throwable $e) { $r['tables'] = 'fail'; }
$port = (int)env('SMTP_PORT', '465');
$s = @fsockopen(($port === 465 ? 'ssl://' : '') . env('SMTP_HOST'), $port, $en, $es, 5);
$r['smtp'] = $s ? 'ok' : 'fail';
if ($s) fclose($s);
$r['site_url'] = env('SITE_URL');
echo json_encode($r);
