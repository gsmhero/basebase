<?php
declare(strict_types=1);
/**
 * Start from zero: deletes every user except administrators (and the owners of Agent / partner-site accounts, so the connection to
 * Sysfera sites keeps working), with all their products, orders, carts, messages, wallets and reviews.
 * Categories, settings, agents and administrators stay. Uploaded image files are not touched.
 *
 *   php database/wipe-data.php          dry run: shows what would be deleted
 *   php database/wipe-data.php --yes    really deletes. DUMP THE DATABASE FIRST: this cannot be undone.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT', dirname(__DIR__));
require ROOT . '/src/bootstrap.php';

$go = in_array('--yes', $argv, true);
$pdo = db();
$has = fn(string $t): bool => (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($t))->fetchColumn();
$keep = array_map('intval', $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN));
if ($has('agents')) $keep = array_merge($keep, array_map('intval', $pdo->query('SELECT user_id FROM agents WHERE user_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)));
$keep = array_values(array_unique($keep));
if (!$keep) { fwrite(STDERR, "No administrator found: refusing to continue.\n"); exit(1); }
$k = implode(',', $keep);
$mine = "SELECT id FROM vendors WHERE user_id NOT IN ($k)";

$jobs = [   // table => where (null = everything)
    'order_items' => null, 'orders' => null, 'cart_items' => null, 'wishlist' => null, 'collection_items' => null,
    'post_items' => null, 'set_items' => null, 'product_sets' => null, 'reviews' => null, 'products' => null,
    'wallet_tx' => "user_id NOT IN ($k)", 'messages' => "from_id NOT IN ($k) OR to_id NOT IN ($k)", 'notifications' => "user_id NOT IN ($k)", 'invites' => null, 'login_tokens' => null, 'webhook_events' => null,
    'vendors' => "user_id NOT IN ($k)", 'users' => "id NOT IN ($k)",
];
echo $go ? "DELETING:\n" : "Dry run (add --yes to delete). Users kept (administrators and agent owners): " . implode(', ', $keep) . "\n";
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($jobs as $t => $w) {
    if (!$has($t)) continue;
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `$t`" . ($w ? " WHERE $w" : ''))->fetchColumn();
        if ($go && $n) $pdo->exec("DELETE FROM `$t`" . ($w ? " WHERE $w" : ''));
        printf("  %-18s %d\n", $t, $n);
    } catch (Throwable $e) { printf("  %-18s skipped (%s)\n", $t, $e->getMessage()); }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo $go ? "Done.\n" : "Nothing was changed.\n";
