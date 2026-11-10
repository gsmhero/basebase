<?php
$id = (int)($seg[1] ?? 0);
$set = row('SELECT s.*, v.shop_name, v.slug FROM product_sets s JOIN vendors v ON v.id = s.vendor_id WHERE s.id = ?', [$id]);
if (!$set) { http_response_code(404); layout('Not found', fn() => print('<section class="wrap empty"><h2>Set not found</h2></section>')); exit; }
$items = rows('SELECT p.*, si.qty AS set_qty FROM set_items si JOIN products p ON p.id = si.product_id WHERE si.set_id = ?', [$id]);
$sum = 0; foreach ($items as $p) $sum += eff_price($p) * $p['set_qty'];
layout($set['title'], function () use ($set, $items, $sum, $id) { ?>
<div class="wrap">
  <div class="page-h"><div class="crumbs"><a href="/shop/<?= e($set['slug']) ?>"><?= e($set['shop_name']) ?></a> / Set</div><h1><?= e($set['title']) ?></h1></div>
  <div class="cart">
    <div class="card"><?php foreach ($items as $p): ?><div class="line" style="grid-template-columns:84px 1fr auto"><a class="th" href="/product/<?= $p['id'] ?>?shop=<?= (int)$set['vendor_id'] ?>"><?= pimg($p) ?></a><div><a href="/product/<?= $p['id'] ?>?shop=<?= (int)$set['vendor_id'] ?>"><b><?= e($p['title']) ?></b></a><div class="muted sm">× <?= $p['set_qty'] ?></div></div><div class="price"><?= money(eff_price($p) * $p['set_qty']) ?></div></div><?php endforeach; ?></div>
    <div class="card sum">
      <div class="r"><span class="muted">Products</span><span><?= count($items) ?></span></div>
      <div class="r tot"><span>Set price</span><span><?= money($sum) ?></span></div>
      <form method="post" action="/do/set-add" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn gold block lg"><?= ico('cart', 18) ?> Add set to cart</button></form>
    </div>
  </div>
</div>
<?php });
