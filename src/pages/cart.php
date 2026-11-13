<?php
$u = owner_only(require_login());

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
    elseif (($pay = ($_POST['pay'] ?? 'cod') === 'test' ? 'test' : 'cod') === 'test' && preg_replace('/\D+/', '', (string)($_POST['card'] ?? '')) !== '4242424242424242') $err = 'Test payment declined. Use the test card 4242 4242 4242 4242.';
    else {
        try {
            $lines = array_map(fn($l) => ['pid' => $l['pid'], 'qty' => (int)$l['qty'], 'variant' => $l['variant'], 'unit' => (float)$l['unit'], 'shop_id' => (int)$l['shop_id'], 'title' => $l['title']], $lines);
            $oid = place_order((int)$u['id'], $lines, compact('name', 'phone', 'address', 'delivery', 'notes'), $pay);
            q('DELETE FROM cart_items WHERE user_id = ?', [$u['id']]);
            $mailed = mail_order($oid);
            flash('Order BB-' . (1000 + $oid) . ($pay === 'test' ? ' paid with test payment (no real money). Mark items as Delivered to credit wallets.' : ' placed! The seller will contact you shortly.'));
            redirect('/cabinet/orders/' . $oid);
        } catch (RuntimeException $e) { $err = $e->getMessage(); }
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
      <?php $pm = $_POST['pay'] ?? 'cod'; ?>
      <div class="fld"><span>Payment</span>
        <label class="payopt"><input type="radio" name="pay" value="cod" <?= $pm !== 'test' ? 'checked' : '' ?>> On delivery</label>
        <label class="payopt"><input type="radio" name="pay" value="test" <?= $pm === 'test' ? 'checked' : '' ?>> Test payment <span class="chip d">no real money</span></label>
        <div id="test-card" <?= $pm === 'test' ? '' : 'hidden' ?>><input name="card" value="<?= e($_POST['card'] ?? '4242 4242 4242 4242') ?>" inputmode="numeric" placeholder="Test card number"><p class="muted sm" style="margin:6px 0 0">Test card 4242 4242 4242 4242 succeeds; any other number is declined. The order is marked Paid.</p></div>
      </div>
      <script>document.querySelectorAll('input[name=pay]').forEach(function(r){r.addEventListener('change',function(){document.getElementById('test-card').hidden=document.querySelector('input[name=pay]:checked').value!=='test'})})</script>
      <div class="r tot"><span>Total</span><span><?= money($total) ?></span></div>
      <button class="btn gold block lg" style="margin-top:16px">Place order</button>
    </form>
  </div>
  <?php else: ?><div class="card empty" style="margin-top:24px"><h2>Your cart is empty</h2><p>Find something you like in the catalog.</p><a class="btn dark" href="/products">Browse products</a></div><?php endif; ?>
</div>
<?php });
