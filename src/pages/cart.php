<?php
$u = require_login();

function cart_lines(int $uid): array {
    $lines = rows('SELECT ci.*, p.title, p.image, p.price, p.promo_price, p.variants, p.stock, p.vendor_id, p.reseller_pct, p.status, p.id AS pid FROM cart_items ci JOIN products p ON p.id = ci.product_id WHERE ci.user_id = ? ORDER BY ci.id', [$uid]);
    foreach ($lines as &$l) {
        $l['unit'] = $l['price_override'] !== null ? (float)$l['price_override'] : eff_price($l, $l['variant']);
        $l['sum'] = $l['unit'] * $l['qty'];
        $l['id_'] = $l['pid'];
    }
    return $lines;
}

$err = '';
if (is_post()) {
    csrf_check();
    $lines = cart_lines((int)$u['id']);
    $f = array_map(fn($k) => trim((string)($_POST[$k] ?? '')), ['name', 'phone', 'address', 'delivery', 'notes']);
    [$name, $phone, $address, $delivery, $notes] = $f;
    if (!$lines) $err = 'Your cart is empty.';
    elseif ($name === '' || $phone === '' || $address === '') $err = 'Fill in name, phone and delivery address.';
    else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $total = 0.0; $ready = [];
            foreach ($lines as $l) {
                $p = row('SELECT * FROM products WHERE id = ? FOR UPDATE', [$l['pid']]);
                if (!$p || $p['status'] !== 'active') throw new RuntimeException('“' . $l['title'] . '” is no longer available.');
                $variants = json_decode($p['variants'] ?? '[]', true) ?: [];
                if ($variants) {
                    $ok = false;
                    foreach ($variants as &$v) if ($v['name'] === $l['variant']) { if ((int)$v['stock'] < $l['qty']) throw new RuntimeException('Not enough stock for “' . $l['title'] . '”.'); $v['stock'] = (int)$v['stock'] - $l['qty']; $ok = true; }
                    unset($v);
                    if (!$ok) throw new RuntimeException('Option no longer available for “' . $l['title'] . '”.');
                    q('UPDATE products SET variants = ?, stock = ? WHERE id = ?', [json_encode($variants, JSON_UNESCAPED_UNICODE), array_sum(array_column($variants, 'stock')), $p['id']]);
                } else {
                    if ((int)$p['stock'] < $l['qty']) throw new RuntimeException('Not enough stock for “' . $l['title'] . '”.');
                    q('UPDATE products SET stock = stock - ? WHERE id = ?', [$l['qty'], $p['id']]);
                }
                $total += $l['sum']; $ready[] = [$l, $p];
            }
            q('INSERT INTO orders (customer_id, total, status, name, phone, address, delivery_method, payment_method, notes) VALUES (?,?,?,?,?,?,?,?,?)',
              [$u['id'], $total, 'new', $name, $phone, $address, $delivery, 'cod', $notes]);
            $oid = (int)$pdo->lastInsertId();
            $notify = [];
            foreach ($ready as [$l, $p]) {
                $reseller = (int)$l['shop_id'];
                if ($reseller) {
                    $ru = (int)val('SELECT user_id FROM vendors WHERE id = ?', [$reseller]);
                    if (!$ru || $ru === (int)$u['id'] || $reseller === (int)$p['vendor_id']) $reseller = 0;
                }
                $pct = (float)val('SELECT commission_pct FROM vendors WHERE id = ?', [$p['vendor_id']]) ?: 10.0;
                $fee = round($l['sum'] * $pct / 100, 2);
                $rfee = $reseller ? round($l['sum'] * (float)$p['reseller_pct'] / 100, 2) : 0.0;
                q('INSERT INTO order_items (order_id, product_id, vendor_id, qty, price, commission, fulfillment, item_status, reseller_id, reseller_fee, title, variant) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$oid, $p['id'], $p['vendor_id'], $l['qty'], $l['unit'], $fee, $p['is_dropship'] ? 'dropship' : 'vendor', 'pending', $reseller ?: null, $rfee, $p['title'], $l['variant']]);
                $notify[(int)val('SELECT user_id FROM vendors WHERE id = ?', [$p['vendor_id']])][] = $p['title'];
                if ($reseller) $notify[(int)val('SELECT user_id FROM vendors WHERE id = ?', [$reseller])][] = $p['title'];
            }
            q('DELETE FROM cart_items WHERE user_id = ?', [$u['id']]);
            $pdo->commit();
            foreach ($notify as $uid => $titles) notify($uid, 'New order BB-' . (1000 + $oid) . ': ' . count($titles) . ' item(s)', '/cabinet/orders/' . $oid);
            flash('Order BB-' . (1000 + $oid) . ' placed! The seller will contact you shortly.');
            redirect('/cabinet/orders/' . $oid);
        } catch (RuntimeException $e) { $pdo->rollBack(); $err = $e->getMessage(); }
    }
}

$lines = cart_lines((int)$u['id']);
$total = array_sum(array_column($lines, 'sum'));
layout('Cart', function () use ($lines, $total, $u, $err) { ?>
<div class="wrap">
  <div class="page-h"><div class="crumbs"><a href="/">Home</a> / Cart</div><h1>Cart</h1></div>
  <?php if ($err): ?><div class="flash bad" style="margin-top:16px"><?= e($err) ?></div><?php endif; ?>
  <?php if ($lines): ?>
  <div class="cart">
    <div class="card">
      <?php foreach ($lines as $l): ?>
      <div class="line">
        <a class="th" href="/product/<?= $l['pid'] ?>"><?= pimg(['id' => $l['pid'], 'title' => $l['title'], 'image' => $l['image']]) ?></a>
        <div><a href="/product/<?= $l['pid'] ?>"><b><?= e($l['title']) ?></b></a><?php if ($l['variant'] !== ''): ?><div class="muted sm"><?= e($l['variant']) ?></div><?php endif; ?><div class="muted sm"><?= money($l['unit']) ?> each</div></div>
        <div class="row" style="flex-wrap:nowrap;align-items:center;justify-content:flex-end">
          <form method="post" action="/do/cart-set" data-autosubmit><?= csrf_field() ?><input type="hidden" name="id" value="<?= $l['id'] ?>"><div class="qty"><button type="button" data-d="-">−</button><input name="qty" type="number" value="<?= $l['qty'] ?>" min="1"><button type="button" data-d="+">+</button></div></form>
          <b style="min-width:90px;text-align:right"><?= money($l['sum']) ?></b>
          <form method="post" action="/do/cart-del"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $l['id'] ?>"><button class="icon-btn" title="Remove"><?= ico('trash', 18) ?></button></form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <form class="card sum" method="post"><?= csrf_field() ?>
      <h3>Checkout</h3>
      <?= field('Full name', 'name', $_POST['name'] ?? $u['name'], 'text', 'required') ?>
      <?= field('Phone', 'phone', $_POST['phone'] ?? ($u['phone'] ?? ''), 'tel', 'required') ?>
      <?= field('Delivery address', 'address', $_POST['address'] ?? '', 'text', 'required') ?>
      <label class="fld"><span>Delivery method</span><select name="delivery"><?php foreach (['CDEK', 'OZON Shipping', 'Russian Post', 'Pickup'] as $d): ?><option <?= ($_POST['delivery'] ?? '') === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></label>
      <?= field('Notes for the seller', 'notes', $_POST['notes'] ?? '') ?>
      <div class="r"><span class="muted">Payment</span><span>On delivery</span></div>
      <div class="r tot"><span>Total</span><span><?= money($total) ?></span></div>
      <button class="btn gold block lg" style="margin-top:16px">Place order</button>
    </form>
  </div>
  <?php else: ?><div class="card empty" style="margin-top:24px"><h2>Your cart is empty</h2><p>Find something you like in the catalog.</p><a class="btn dark" href="/products">Browse products</a></div><?php endif; ?>
</div>
<?php });
