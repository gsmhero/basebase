<?php
$u = require_login();
if ($u['sub']) { foreach (['products' => '/cabinet/products', 'orders' => '/cabinet/orders', 'messages' => '/cabinet/messenger', 'blog' => '/cabinet/blog'] as $k => $href) if (has_perm($u, $k)) redirect($href); deny(); }
$vid = (int)$u['vid'];
$supply = can_supply($u); $resell = can_resell($u);

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$from = $month . '-01'; $to = date('Y-m-d', strtotime("$from +1 month"));
$topN = in_array((int)($_GET['n'] ?? 5), [5, 10, 20], true) ? (int)($_GET['n'] ?? 5) : 5;

$mine = $vid ? "(oi.vendor_id = $vid OR oi.reseller_id = $vid)" : '0';
$kp = $vid ? row("SELECT COALESCE(SUM(oi.price*oi.qty),0) rev, COALESCE(SUM(oi.qty),0) sold,
   COALESCE(SUM(CASE WHEN oi.vendor_id = $vid THEN oi.price*oi.qty-oi.commission-oi.reseller_fee ELSE oi.reseller_fee END),0) profit
   FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $mine AND o.created_at >= ? AND o.created_at < ?", [$from, $to]) : ['rev' => 0, 'sold' => 0, 'profit' => 0];

$stats = $vid ? rows("SELECT p.id, p.title, p.price, p.views, c.name cat, v.shop_name owner,
   COALESCE(SUM(oi.qty),0) sold, COALESCE(SUM(oi.price*oi.qty),0) rev,
   COALESCE(SUM(CASE WHEN oi.vendor_id = $vid THEN oi.price*oi.qty-oi.commission-oi.reseller_fee ELSE oi.reseller_fee END),0) profit
   FROM products p JOIN vendors v ON v.id = p.vendor_id LEFT JOIN categories c ON c.id = p.category_id
   LEFT JOIN order_items oi ON oi.product_id = p.id AND $mine AND oi.order_id IN (SELECT id FROM orders WHERE created_at >= ? AND created_at < ?)
   WHERE p.vendor_id = $vid OR p.id IN (SELECT product_id FROM collection_items WHERE vendor_id = $vid)
   GROUP BY p.id, p.title, p.price, p.views, c.name, v.shop_name ORDER BY rev DESC, p.views DESC LIMIT $topN", [$from, $to]) : [];

$trends = rows("SELECT c.name, COUNT(DISTINCT p.vendor_id) sellers, COALESCE(SUM(p.views),0) visits FROM categories c JOIN products p ON p.category_id IN (c.id) OR p.category_id IN (SELECT id FROM categories WHERE parent_id = c.id)
   WHERE c.parent_id IS NULL AND p.status = 'active' GROUP BY c.id ORDER BY visits DESC LIMIT 6");

$sf = $_GET['status'] ?? 'active';
$sw = $sf === 'active' ? "oi.item_status IN ('pending','prepare','shipped','on_delivery')" : ($sf === 'all' ? '1' : 'oi.item_status = ?');
$sa = in_array($sf, ['pending', 'prepare', 'shipped', 'on_delivery', 'delivered', 'archived', 'returned'], true) ? [$sf] : [];
$orders = rows("SELECT o.id, o.created_at, o.name, o.payment_status, o.delivery_method, o.notes, oi.item_status, SUM(oi.qty) qty, SUM(oi.price*oi.qty) total
   FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE ($mine OR o.customer_id = {$u['id']}) AND $sw GROUP BY o.id, oi.item_status ORDER BY o.id DESC LIMIT 6", $sa);

$stock = $supply ? rows("SELECT id, title, stock FROM products WHERE vendor_id = ? AND status <> 'archived' ORDER BY stock ASC LIMIT 5", [$vid]) : [];
$returns = $vid ? rows("SELECT o.id, oi.title, oi.qty, oi.price FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $mine AND oi.item_status = 'returned' ORDER BY o.id DESC LIMIT 5") : [];
$balance = wallet_balance((int)$u['id']);

// ABC / XYZ over last 90 days / 6 months
$abc = []; $xyz = [];
if ($vid) {
    $r = rows("SELECT oi.product_id id, oi.title, SUM(oi.price*oi.qty) rev FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $mine AND o.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY oi.product_id, oi.title ORDER BY rev DESC");
    $tot = array_sum(array_column($r, 'rev')); $cum = 0;
    foreach ($r as $x) { $cum += $x['rev']; $abc[] = $x + ['cls' => $tot ? ($cum - $x['rev'] < 0.8 * $tot ? 'A' : ($cum - $x['rev'] < 0.95 * $tot ? 'B' : 'C')) : 'C', 'share' => $tot ? $x['rev'] / $tot * 100 : 0]; }
    $m = rows("SELECT oi.product_id id, oi.title, DATE_FORMAT(o.created_at,'%Y-%m') mth, SUM(oi.qty) q FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $mine AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY oi.product_id, oi.title, mth");
    $by = []; foreach ($m as $x) { $by[$x['id']]['t'] = $x['title']; $by[$x['id']]['q'][$x['mth']] = (float)$x['q']; }
    $months = []; for ($i = 5; $i >= 0; $i--) $months[] = date('Y-m', strtotime("-$i month"));
    foreach ($by as $id => $x) {
        $series = array_map(fn($mm) => $x['q'][$mm] ?? 0.0, $months); $mean = array_sum($series) / 6;
        $sd = sqrt(array_sum(array_map(fn($v) => ($v - $mean) ** 2, $series)) / 6); $cv = $mean ? $sd / $mean : 9;
        $xyz[] = ['id' => $id, 'title' => $x['t'], 'cv' => $cv, 'cls' => $cv < 0.5 ? 'X' : ($cv < 1.0 ? 'Y' : 'Z')];
    }
    usort($xyz, fn($a, $b) => $a['cv'] <=> $b['cv']);
}

layout('Dashboard', function () use ($u, $kp, $stats, $trends, $orders, $stock, $returns, $balance, $abc, $xyz, $month, $topN, $sf, $supply, $resell, $vid) { ?>
<div class="kpis">
  <div class="kpi gold"><small>Wallet</small><b><?= money($balance) ?></b></div>
  <div class="kpi"><small>Revenue · <?= e($month) ?></small><b><?= money($kp['rev']) ?></b></div>
  <div class="kpi"><small>Profit · <?= e($month) ?></small><b><?= money($kp['profit']) ?></b></div>
  <div class="kpi"><small>Items sold</small><b><?= num($kp['sold']) ?></b></div>
</div>

<?php if ($vid): ?>
<div class="dash-grid">
  <section class="card span-7"><div class="card-h"><h3>Selling stats</h3>
    <form method="get" class="row" style="flex:none;flex-wrap:nowrap;gap:8px"><input type="month" name="m" value="<?= e($month) ?>" onchange="this.form.submit()" style="width:auto;padding:6px 10px">
      <select name="n" onchange="this.form.submit()" style="width:auto;padding:6px 10px"><?php foreach ([5, 10, 20] as $n): ?><option value="<?= $n ?>" <?= $topN === $n ? 'selected' : '' ?>>View <?= $n ?></option><?php endforeach; ?></select></form></div>
    <div class="scroll"><table class="tbl"><thead><tr><th>Product</th><th>Price</th><th>Category</th><th>Owner</th><th class="num">Visits</th><th class="num">Sold</th><th class="num">Revenue</th><th class="num">Profit</th></tr></thead><tbody>
      <?php foreach ($stats as $s): ?><tr><td><a class="link" href="/product/<?= $s['id'] ?>"><?= e(mb_strimwidth($s['title'], 0, 34, '…')) ?></a></td><td><?= money($s['price']) ?></td><td><?= e($s['cat'] ?: '—') ?></td><td><?= e($s['owner']) ?></td><td class="num"><?= num($s['views']) ?></td><td class="num"><?= num($s['sold']) ?></td><td class="num"><?= money($s['rev']) ?></td><td class="num"><?= money($s['profit']) ?></td></tr><?php endforeach; ?>
      <?php if (!$stats): ?><tr><td colspan="8" class="muted center">No products yet — collect some from the catalog.</td></tr><?php endif; ?>
    </tbody></table></div><p style="margin:12px 0 0"><a class="link" href="/cabinet/stats" style="color:var(--accent-d)">Full statistics →</a></p></section>

  <section class="card span-5"><div class="card-h"><h3>Selling trends</h3><span class="muted sm">Top categories</span></div>
    <table class="tbl"><thead><tr><th>Category</th><th class="num">Sellers</th><th class="num">Visits</th></tr></thead><tbody>
      <?php foreach ($trends as $t): ?><tr><td><?= e($t['name']) ?></td><td class="num"><?= $t['sellers'] ?></td><td class="num"><?= num($t['visits']) ?></td></tr><?php endforeach; ?>
      <?php if (!$trends): ?><tr><td colspan="3" class="muted center">No data yet.</td></tr><?php endif; ?></tbody></table></section>
</div>
<?php endif; ?>

<section class="card"><div class="card-h"><h3>Orders</h3>
  <form method="get"><select name="status" onchange="this.form.submit()" style="width:auto;padding:6px 10px"><?php foreach (['active' => 'Active', 'all' => 'All', 'pending' => 'Pending', 'prepare' => 'Prepare', 'shipped' => 'Shipped', 'on_delivery' => 'On delivery', 'delivered' => 'Delivered', 'archived' => 'Archived'] as $k => $l): ?><option value="<?= $k ?>" <?= $sf === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form></div>
  <div class="scroll"><table class="tbl"><thead><tr><th>Order ID</th><th>Date</th><th>Customer</th><th class="num">Items</th><th class="num">Total</th><th>Status</th><th>Payment</th><th>Delivery</th><th>Notes</th></tr></thead><tbody>
    <?php foreach ($orders as $o): ?><tr><td><a class="link" href="/cabinet/orders/<?= $o['id'] ?>"><b>BB-<?= 1000 + $o['id'] ?></b></a></td><td><?= date('d.m.Y', strtotime($o['created_at'])) ?></td><td><?= e($o['name']) ?></td><td class="num"><?= $o['qty'] ?></td><td class="num"><?= money($o['total']) ?></td><td><?= badge($o['item_status']) ?></td><td><?= badge($o['payment_status']) ?></td><td><?= e($o['delivery_method']) ?></td><td class="muted"><?= e($o['notes'] ?: '— none —') ?></td></tr><?php endforeach; ?>
    <?php if (!$orders): ?><tr><td colspan="9" class="muted center">No orders here yet.</td></tr><?php endif; ?></tbody></table></div>
  <p style="margin:12px 0 0"><a class="link" href="/cabinet/orders" style="color:var(--accent-d)">All orders →</a></p></section>

<?php if ($vid): ?>
<div class="dash-grid">
  <section class="card span-6"><div class="card-h"><h3>Warehouse stock</h3><span class="muted sm">Lowest first</span></div>
    <?php if ($supply): foreach ($stock as $s): $pct = min(100, $s['stock'] / 2); ?><div style="margin-bottom:12px"><div class="row" style="flex-wrap:nowrap;justify-content:space-between"><a class="link" href="/cabinet/products/<?= $s['id'] ?>"><?= e(mb_strimwidth($s['title'], 0, 40, '…')) ?></a><b style="flex:none"><?= num($s['stock']) ?> pc</b></div><div class="bar-h"><i style="width:<?= $pct ?>%"></i></div></div><?php endforeach; if (!$stock) echo '<p class="muted">No products listed.</p>'; ?>
    <?php else: ?><p class="muted">Warehouse is available for supplier accounts. Your suppliers hold the stock for products you collect.</p><?php endif; ?></section>
  <section class="card span-6"><div class="card-h"><h3>Returns</h3></div>
    <?php foreach ($returns as $r): ?><div class="row" style="flex-wrap:nowrap;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line)"><span><a class="link" href="/cabinet/orders/<?= $r['id'] ?>">BB-<?= 1000 + $r['id'] ?></a> · <?= e(mb_strimwidth($r['title'], 0, 30, '…')) ?></span><b style="flex:none">× <?= $r['qty'] ?></b></div><?php endforeach; if (!$returns) echo '<p class="muted">No returns. 🎉</p>'; ?></section>
  <section class="card span-6"><div class="card-h"><h3>ABC analysis by SKU</h3><span class="muted sm">Revenue, 90 days</span></div>
    <table class="tbl"><tbody><?php foreach (array_slice($abc, 0, 6) as $a): ?><tr><td><span class="abc <?= $a['cls'] ?>"><?= $a['cls'] ?></span></td><td><?= e(mb_strimwidth($a['title'], 0, 34, '…')) ?></td><td class="num"><?= number_format($a['share'], 1) ?>%</td></tr><?php endforeach; if (!$abc) echo '<tr><td class="muted">Not enough sales yet.</td></tr>'; ?></tbody></table></section>
  <section class="card span-6"><div class="card-h"><h3>XYZ analysis by SKU</h3><span class="muted sm">Demand stability, 6 months</span></div>
    <table class="tbl"><tbody><?php foreach (array_slice($xyz, 0, 6) as $a): ?><tr><td><span class="abc <?= $a['cls'] ?>"><?= $a['cls'] ?></span></td><td><?= e(mb_strimwidth($a['title'], 0, 34, '…')) ?></td><td class="num">CV <?= number_format($a['cv'], 2) ?></td></tr><?php endforeach; if (!$xyz) echo '<tr><td class="muted">Not enough sales yet.</td></tr>'; ?></tbody></table></section>
</div>
<?php endif; ?>
<?php }, ['cabinet' => true]);
