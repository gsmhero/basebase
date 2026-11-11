<?php
$u = owner_only(require_role('vendor', 'supplier', 'admin'));
$vid = (int)$u['vid'];
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$from = $month . '-01'; $to = date('Y-m-d', strtotime("$from +1 month"));
$days = (int)date('t', strtotime($from));
$mine = "(oi.vendor_id = $vid OR oi.reseller_id = $vid)";
$profit = "CASE WHEN oi.vendor_id = $vid THEN oi.price*oi.qty-oi.commission-oi.reseller_fee ELSE oi.reseller_fee END";

$daily = array_fill(1, $days, 0.0);
foreach (rows("SELECT DAY(o.created_at) d, SUM($profit) pr FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $mine AND o.created_at >= ? AND o.created_at < ? GROUP BY d", [$from, $to]) as $r) $daily[(int)$r['d']] = (float)$r['pr'];
$max = max($daily) ?: 1;

$tot = row("SELECT COALESCE(SUM(oi.qty),0) sold, COALESCE(SUM(oi.price*oi.qty),0) rev, COALESCE(SUM($profit),0) pr, COUNT(DISTINCT oi.order_id) orders FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $mine AND o.created_at >= ? AND o.created_at < ?", [$from, $to]);
$role = row("SELECT COALESCE(SUM(CASE WHEN oi.vendor_id = $vid THEN oi.price*oi.qty-oi.commission-oi.reseller_fee ELSE 0 END),0) as supplier_pr, COALESCE(SUM(CASE WHEN oi.reseller_id = $vid THEN oi.reseller_fee ELSE 0 END),0) as reseller_pr FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $mine AND o.created_at >= ? AND o.created_at < ?", [$from, $to]);
$top = rows("SELECT oi.title, SUM(oi.qty) sold, SUM(oi.price*oi.qty) rev, SUM($profit) pr FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $mine AND o.created_at >= ? AND o.created_at < ? GROUP BY oi.product_id, oi.title ORDER BY pr DESC LIMIT 10", [$from, $to]);
$wallet = rows('SELECT * FROM wallet_tx WHERE user_id = ? ORDER BY id DESC LIMIT 8', [$u['id']]);

layout('Selling stats', function () use ($daily, $max, $tot, $role, $top, $month, $wallet) { ?>
<div class="card-h"><h2 style="margin:0">Selling stats</h2><form method="get"><input type="month" name="m" value="<?= e($month) ?>" onchange="this.form.submit()" style="width:auto"></form></div>
<div class="kpis">
  <div class="kpi"><small>Orders</small><b><?= num($tot['orders']) ?></b></div>
  <div class="kpi"><small>Items sold</small><b><?= num($tot['sold']) ?></b></div>
  <div class="kpi"><small>Revenue</small><b><?= money($tot['rev']) ?></b></div>
  <div class="kpi gold"><small>Profit</small><b><?= money($tot['pr']) ?></b></div>
</div>
<section class="card"><div class="card-h"><h3>Daily profit</h3><span class="muted sm">Supplier <?= money($role['supplier_pr']) ?> · Reseller <?= money($role['reseller_pr']) ?></span></div>
  <div class="bars"><?php foreach ($daily as $d => $v): ?><i style="height:<?= max(2, $v / $max * 100) ?>%" data-t="<?= $d ?>: <?= e(money($v)) ?>"></i><?php endforeach; ?></div>
  <div class="row" style="justify-content:space-between;flex-wrap:nowrap;margin-top:6px" class="muted sm"><span class="muted sm">1</span><span class="muted sm"><?= count($daily) ?></span></div></section>
<div class="dash-grid">
  <section class="card span-12"><div class="card-h"><h3>Top products</h3></div><div class="scroll"><table class="tbl"><thead><tr><th>Product</th><th class="num">Sold</th><th class="num">Revenue</th><th class="num">Profit</th></tr></thead><tbody>
    <?php foreach ($top as $t): ?><tr><td><?= e(mb_strimwidth($t['title'], 0, 40, '…')) ?></td><td class="num"><?= num($t['sold']) ?></td><td class="num"><?= money($t['rev']) ?></td><td class="num"><?= money($t['pr']) ?></td></tr><?php endforeach; ?>
    <?php if (!$top): ?><tr><td colspan="4" class="muted center" style="padding:30px">No sales in this month.</td></tr><?php endif; ?></tbody></table></div></section>
  <section class="card span-12"><div class="card-h"><h3>Wallet activity</h3><a class="link" href="/cabinet/profile" style="color:var(--accent-d)">Wallet →</a></div>
    <?php foreach ($wallet as $w): ?><div class="row" style="flex-wrap:nowrap;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line)"><span class="sm"><?= e(mb_strimwidth((string)$w['note'], 0, 32, '…')) ?><br><span class="muted"><?= ago($w['created_at']) ?></span></span><b style="flex:none;color:var(--<?= $w['amount'] >= 0 ? 'ok' : 'bad' ?>)"><?= ($w['amount'] >= 0 ? '+' : '') . money($w['amount']) ?></b></div><?php endforeach; ?>
    <?php if (!$wallet): ?><p class="muted">No transactions yet.</p><?php endif; ?></section>
</div>
<?php }, ['cabinet' => true]);
