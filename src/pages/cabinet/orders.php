<?php
$u = require_perm(require_login(), 'orders');
$vid = (int)$u['vid'];
$isAdmin = $u['role'] === 'admin';
$statuses = ITEM_STATUSES;

/* ---------- order detail ---------- */
if (!empty($seg[2])) {
    $oid = (int)$seg[2];
    $o = row('SELECT o.*, u.email AS cust_email FROM orders o JOIN users u ON u.id = o.customer_id WHERE o.id = ?', [$oid]);
    $items = $o ? rows('SELECT oi.*, p.image, p.id AS pid FROM order_items oi LEFT JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?', [$oid]) : [];
    $isCust = $o && (int)$o['customer_id'] === (int)$u['id'];
    $mineItems = array_filter($items, fn($i) => $isAdmin || $isCust || ($vid && ((int)$i['vendor_id'] === $vid || (int)$i['reseller_id'] === $vid)));
    if (!$o || !$mineItems) { flash('Order not found.', 'warn'); redirect('/cabinet/orders'); }

    if (is_post()) {
        csrf_check();
        $do = $_POST['do'] ?? '';
        if ($do === 'status') {
            $it = row('SELECT * FROM order_items WHERE id = ? AND order_id = ?', [(int)$_POST['item'], $oid]);
            $new = $_POST['status'] ?? '';
            if ($it && isset($statuses[$new]) && ($isAdmin || (int)$it['vendor_id'] === $vid)) {
                apply_item_status($it, $new);
                flash('Status updated.');
            }
        } elseif ($do === 'pay') {
            if (!$isCust || $o['payment_status'] !== 'unpaid') flash('This order cannot be paid.', 'warn');
            elseif (preg_replace('/\D+/', '', (string)($_POST['card'] ?? '')) !== '4242424242424242') flash('Test payment declined. Use the test card 4242 4242 4242 4242.', 'bad');
            else {
                q("UPDATE orders SET payment_status = 'paid', payment_method = 'test' WHERE id = ? AND payment_status = 'unpaid'", [$oid]);
                foreach (array_unique(array_column($items, 'vendor_id')) as $sv) { $su = (int)val('SELECT user_id FROM vendors WHERE id = ?', [$sv]); if ($su) notify($su, 'Order BB-' . (1000 + $oid) . ' has been paid', '/cabinet/orders/' . $oid); }
                flash('Payment received (test payment, no real money).');
            }
        } elseif ($do === 'payment' && ($isAdmin || array_filter($items, fn($i) => (int)$i['vendor_id'] === $vid)) && in_array($_POST['pay'] ?? '', ['unpaid', 'paid', 'refunded'], true)) {
            q('UPDATE orders SET payment_status = ? WHERE id = ?', [$_POST['pay'], $oid]); flash('Payment status updated.');
        }
        redirect('/cabinet/orders/' . $oid);
    }

    $canEdit = fn($i) => $isAdmin || (int)$i['vendor_id'] === $vid;
    $canPay = $isAdmin || array_filter($items, fn($i) => (int)$i['vendor_id'] === $vid);
    layout('Order BB-' . (1000 + $oid), function () use ($o, $mineItems, $statuses, $canEdit, $canPay, $isCust, $vid, $isAdmin, $oid) {
        $sum = 0; foreach ($mineItems as $i) $sum += $i['price'] * $i['qty']; ?>
    <div class="card-h"><div><div class="crumbs" style="margin:0"><a href="/cabinet/orders">Orders</a> /</div><h2 style="margin:0">Order BB-<?= 1000 + $oid ?></h2></div><div><?= badge($o['payment_status']) ?> <span class="muted sm"><?= date('d.m.Y H:i', strtotime($o['created_at'])) ?></span></div></div>
    <div class="dash-grid">
      <section class="card span-8"><h3>Items</h3>
        <?php foreach ($mineItems as $i): ?><div class="line" style="grid-template-columns:64px 1fr auto"><a class="th" style="width:64px;height:64px" href="/product/<?= (int)$i['product_id'] ?>"><?= pimg(['id' => $i['product_id'], 'title' => $i['title'], 'image' => $i['image']]) ?></a>
          <div><b><?= e($i['title']) ?></b><?= $i['variant'] ? ' <span class="muted">· ' . e($i['variant']) . '</span>' : '' ?><div class="muted sm"><?= money($i['price']) ?> × <?= $i['qty'] ?><?php if ($isAdmin || (int)$i['vendor_id'] === $vid): ?> · you receive <?= money($i['price'] * $i['qty'] - $i['commission'] - $i['reseller_fee']) ?><?= $isAdmin ? ' · platform ' . money($i['commission'] - $i['agent_fee']) . ($i['agent_fee'] > 0 ? ' · agent ' . money($i['agent_fee']) : '') . ($i['reseller_fee'] > 0 ? ' · blogger ' . money($i['reseller_fee']) : '') : '' ?><?php elseif ((int)$i['reseller_id'] === $vid): ?> · your commission <b style="color:var(--ok)"><?= money($i['reseller_fee']) ?></b><?php endif; ?></div></div>
          <div class="right"><b><?= money($i['price'] * $i['qty']) ?></b><div style="margin-top:6px">
            <?php if ($canEdit($i)): ?><form method="post" class="share-box"><?= csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="item" value="<?= $i['id'] ?>"><select name="status" onchange="this.form.submit()" style="padding:6px 8px;font-size:13px"><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>" <?= $i['item_status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></form>
            <?php else: echo badge($i['item_status']); endif; ?></div></div></div><?php endforeach; ?>
        <div class="row" style="justify-content:flex-end;font:700 20px var(--fd);padding-top:14px"><?= money($sum) ?></div>
      </section>
      <section class="card span-4"><h3>Customer</h3>
        <p style="margin:0 0 4px"><b><?= e($o['name']) ?></b></p><p class="muted" style="margin:0"><?= e($o['phone']) ?></p><p class="muted"><?= e($o['cust_email']) ?></p>
        <h3 style="margin-top:20px">Delivery</h3><p style="margin:0"><?= e($o['delivery_method']) ?></p><p class="muted"><?= e($o['address']) ?></p>
        <?php if ($o['notes']): ?><h3 style="margin-top:20px">Notes</h3><p class="muted"><?= e($o['notes']) ?></p><?php endif; ?>
        <h3 style="margin-top:20px">Payment</h3><p class="muted" style="margin:0 0 8px"><?= $o['payment_method'] === 'test' ? 'Test payment (simulated)' : 'On delivery' ?></p>
        <?php if ($isCust && $o['payment_status'] === 'unpaid'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="pay"><input name="card" value="4242 4242 4242 4242" inputmode="numeric" placeholder="Test card number"><p class="muted sm" style="margin:6px 0 10px">Test payment — card 4242 4242 4242 4242 succeeds.</p><button class="btn gold block">Pay <?= money($sum) ?> now</button></form><?php endif; ?>
        <?php if ($canPay): ?><form method="post" class="share-box" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="do" value="payment"><select name="pay" onchange="this.form.submit()"><?php foreach (['unpaid', 'paid', 'refunded'] as $s): ?><option <?= $o['payment_status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?></select></form><?php else: echo badge($o['payment_status']); endif; ?>
        <?php if (!$isCust): ?><a class="btn block" style="margin-top:20px" href="/cabinet/messenger/<?= (int)$o['customer_id'] ?>"><?= ico('msg', 16) ?> Message customer</a><?php endif; ?>
      </section>
    </div>
    <?php }, ['cabinet' => true]);
    exit;
}

/* ---------- list ---------- */
$from = $_GET['from'] ?? ''; $to = $_GET['to'] ?? ''; $cat = (int)($_GET['cat'] ?? 0); $st = $_GET['status'] ?? 'all';
$mine = $vid ? "(oi.vendor_id = $vid OR oi.reseller_id = $vid OR o.customer_id = {$u['id']})" : "o.customer_id = {$u['id']}";
if ($isAdmin) $mine = '1';
$w = [$mine]; $a = [];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w[] = 'o.created_at >= ?'; $a[] = $from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $w[] = 'o.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $a[] = $to; }
if ($cat) { $w[] = 'oi.product_id IN (SELECT id FROM products WHERE category_id = ? OR category_id IN (SELECT id FROM categories WHERE parent_id = ?))'; array_push($a, $cat, $cat); }
if ($st === 'active') $w[] = "oi.item_status IN ('pending','prepare','shipped','on_delivery')";
elseif (isset($statuses[$st])) { $w[] = 'oi.item_status = ?'; $a[] = $st; }
$orders = rows('SELECT o.id, o.created_at, o.name, o.payment_status, o.delivery_method, o.notes, SUM(oi.qty) qty, SUM(oi.price*oi.qty) total, MIN(oi.item_status) st
   FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE ' . implode(' AND ', $w) . ' GROUP BY o.id, o.created_at, o.name, o.payment_status, o.delivery_method, o.notes ORDER BY o.id DESC LIMIT 100', $a);
$tree = categories_tree();
layout(can_supply($u) ? 'Orders manager' : 'My orders', function () use ($orders, $statuses, $from, $to, $cat, $st, $tree, $u) { ?>
<div class="card-h"><h2 style="margin:0"><?= can_supply($u) ? 'Orders manager' : 'My orders' ?></h2></div>
<form class="card row" method="get" style="align-items:flex-end">
  <label class="fld" style="margin:0"><span>From</span><input type="date" name="from" value="<?= e($from) ?>"></label>
  <label class="fld" style="margin:0"><span>To</span><input type="date" name="to" value="<?= e($to) ?>"></label>
  <label class="fld" style="margin:0"><span>Category</span><select name="cat"><option value="">All</option><?php foreach ($tree as $c): ?><option value="<?= $c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
  <label class="fld" style="margin:0"><span>Status</span><select name="status"><option value="all">All</option><option value="active" <?= $st === 'active' ? 'selected' : '' ?>>Active</option><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <button class="btn dark" style="flex:none">Filter</button>
</form>
<section class="card scroll"><table class="tbl"><thead><tr><th>Order ID</th><th>Order date</th><th>Customer</th><th class="num">Items</th><th class="num">Total amount</th><th>Order status</th><th>Payment</th><th>Delivery</th><th>Customer notes</th></tr></thead><tbody>
  <?php foreach ($orders as $o): ?><tr><td><a class="link" href="/cabinet/orders/<?= $o['id'] ?>"><b>BB-<?= 1000 + $o['id'] ?></b></a></td><td><?= date('d.m.Y', strtotime($o['created_at'])) ?></td><td><?= e($o['name']) ?></td><td class="num"><?= $o['qty'] ?></td><td class="num"><?= money($o['total']) ?></td><td><?= badge($o['st']) ?></td><td><?= badge($o['payment_status']) ?></td><td><?= e($o['delivery_method']) ?></td><td class="muted"><?= e($o['notes'] ?: '— none —') ?></td></tr><?php endforeach; ?>
  <?php if (!$orders): ?><tr><td colspan="9" class="center muted" style="padding:36px">No orders match.</td></tr><?php endif; ?></tbody></table></section>
<?php }, ['cabinet' => true]);
