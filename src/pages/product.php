<?php
$id = (int)($seg[1] ?? 0);
$p = row('SELECT p.*, v.shop_name, v.slug, v.bio, v.blogging_type, v.user_id AS owner_uid FROM products p JOIN vendors v ON v.id = p.vendor_id WHERE p.id = ?', [$id]);
$u = me();
$isOwner = $u && $p && ((int)$p['owner_uid'] === (int)$u['id'] || $u['role'] === 'admin');
if (!$p || ($p['status'] !== 'active' && !$isOwner)) {
    http_response_code(404);
    layout('Not found', fn() => print('<section class="wrap empty"><h2>Product not found</h2><a class="btn dark" href="/products">Back to catalog</a></section>'));
    exit;
}
if (empty($_SESSION['seen'][$id])) { q('UPDATE products SET views = views + 1 WHERE id = ?', [$id]); $_SESSION['seen'][$id] = 1; }

$shop = (int)($_GET['shop'] ?? 0);
$variants = json_decode($p['variants'] ?? '[]', true) ?: [];
$gallery = json_decode($p['gallery'] ?? '[]', true) ?: [];
$attrs = json_decode($p['attrs'] ?? '[]', true) ?: [];
$stock = $variants ? array_sum(array_column($variants, 'stock')) : (int)$p['stock'];
$promo = false; // promo price is the supplier price and is never shown to customers
$ref = $shop ? row("SELECT shop_name, slug, user_id FROM vendors WHERE id = ? AND id <> ?", [$shop, $p["vendor_id"]]) : null;
$path = category_path($p['category_id'] ? (int)$p['category_id'] : null);
[$rn, $ravg] = rating_of($id);
$revs = rows('SELECT r.*, u.name FROM reviews r JOIN users u ON u.id = r.user_id WHERE r.product_id = ? ORDER BY r.id DESC LIMIT 30', [$id]);
$collected = $u && $u['vid'] ? (bool)val('SELECT 1 FROM collection_items WHERE vendor_id = ? AND product_id = ?', [$u['vid'], $id]) : false;
$myReview = $u ? row('SELECT * FROM reviews WHERE product_id = ? AND user_id = ?', [$id, $u['id']]) : null;
$shareUrl = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'www.basebase.ru') . '/product/' . $id . ($u && can_resell($u) && $u['vid'] ? '?shop=' . $u['vid'] : '');
$groups = [];
foreach ($attrs as $a) $groups[$a['section'] ?: 'Specifications'][] = $a;

layout($p['title'], function () use ($p, $variants, $gallery, $groups, $stock, $promo, $ref, $path, $rn, $ravg, $revs, $collected, $myReview, $u, $isOwner, $shop, $shareUrl, $id) { ?>
<div class="wrap">
  <div class="crumbs" style="margin-top:24px"><a href="/products">Products</a><?php foreach ($path as $c): ?> / <a href="/products?cat=<?= $c['id'] ?>"><?= e($c['name']) ?></a><?php endforeach; ?></div>
  <div class="pdp">
    <div>
      <div class="gal-main"><?= pimg($p) ?></div>
      <?php if ($gallery): ?><div class="gal-th">
        <?php if ($p['image']): ?><button class="on" data-src="<?= e($p['image']) ?>"><img src="<?= e($p['image']) ?>" alt=""></button><?php endif; ?>
        <?php foreach ($gallery as $g): ?><button data-src="<?= e($g) ?>"><img src="<?= e($g) ?>" alt="" loading="lazy"></button><?php endforeach; ?>
      </div><?php endif; ?>
      <div class="buybar" style="margin-top:18px">
        <button class="btn sm" data-copy="<?= e($shareUrl) ?>"><?= ico('share', 16) ?> Share product</button>
        <?php $emb = preg_replace('~/product/(\d+)~', '/embed/product/$1', $shareUrl); $embHost = strstr($shareUrl, '/product/', true); ?>
        <button class="btn sm" title="Paste into any blog or site: buyers purchase without leaving it" data-copy="<?= e('<iframe src="' . $emb . '" width="100%" height="260" style="border:0;max-width:720px" loading="lazy" title="basebase.ru"></iframe><script async src="' . $embHost . '/assets/embed.js"></script>') ?>"><?= ico('code', 16) ?> Embed</button>
      </div>

      <div style="margin-top:34px;border-top:1px solid var(--line);padding-top:22px">
        <div class="card-h"><h3>Product reviews</h3><span class="rating-pill"><?= stars($ravg, 16) ?> <?= $rn ?></span></div>
        <?php if ($u): ?>
        <form method="post" action="/do/review" class="card" style="margin-bottom:10px"><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= $id ?>">
          <div class="row"><label class="fld" style="max-width:150px"><span>Your rating</span><select name="rating"><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>" <?= ($myReview['rating'] ?? 5) == $i ? 'selected' : '' ?>><?= $i ?> ★</option><?php endfor; ?></select></label>
          <label class="fld" style="flex:3"><span>Review</span><input name="body" maxlength="1000" value="<?= e($myReview['body'] ?? '') ?>" placeholder="Share your experience"></label></div>
          <button class="btn sm dark"><?= $myReview ? 'Update review' : 'Post review' ?></button>
        </form>
        <?php else: ?><p class="muted"><a class="link" href="/login">Sign in</a> to leave a review.</p><?php endif; ?>
        <?php foreach ($revs as $r): ?><div class="review"><b><?= e($r['name'] ?: 'Customer') ?> <span class="muted sm" style="font-weight:400">· <?= ago($r['created_at']) ?></span></b><?= stars((float)$r['rating'], 14) ?><p style="margin:6px 0 0"><?= nl2br(e($r['body'])) ?></p></div><?php endforeach; ?>
        <?php if (!$revs): ?><p class="muted">No reviews yet.</p><?php endif; ?>
      </div>
    </div>

    <div>
      <div class="row" style="align-items:flex-start;justify-content:space-between;flex-wrap:nowrap"><h1><?= e($p['title']) ?></h1>
        <?php if ($isOwner): ?><a class="btn sm" style="flex:none" href="/cabinet/products/<?= $id ?>"><?= ico('edit', 15) ?> Edit</a><?php endif; ?></div>
      <?php if ($p['status'] !== 'active'): ?><p><?= badge($p['status']) ?> <span class="muted sm">Visible only to you</span></p><?php endif; ?>
      <?php if ($p['short_desc']): ?><p class="muted" style="font-size:16px"><?= e($p['short_desc']) ?></p><?php endif; ?>

      <div class="prices">
        <div class="r"><span class="muted">Price</span><span class="<?= $promo ? 'old' : 'big' ?>"><?= money($p['price']) ?></span></div>
        <div class="r"><span class="muted">Stock</span><b><?= $stock > 0 ? num($stock) . ' pc' : '<span style="color:var(--bad)">Out of stock</span>' ?></b></div>
        <?php if (can_resell($u) && (int)$p['owner_uid'] !== (int)$u['id']): ?><div class="r"><span class="muted">You earn per sale</span><b style="color:var(--ok)"><?= money(reseller_earn($p)) ?></b></div><?php endif; ?>
        <?php if ($isOwner && (float)$p['promo_price'] > 0): ?><div class="r"><span class="muted">You receive (supplier price)</span><b><?= money($p['promo_price']) ?></b></div><?php endif; ?>
      </div>

      <?php if ($ref): /* opened from a blog, shop or shared link: show the blogger, hide the product author */ ?>
      <div class="owner" style="margin-bottom:18px">
        <span class="av"><?= e(mb_strtoupper(mb_substr($ref['shop_name'], 0, 1))) ?></span>
        <div style="flex:1;min-width:0"><div class="muted sm">Recommended by</div><a class="link" href="/shop/<?= e($ref['slug']) ?>"><b><?= e($ref['shop_name']) ?></b></a></div>
        <a class="btn sm" href="/shop/<?= e($ref['slug']) ?>" style="flex:none">Visit shop</a>
      </div>
      <?php else: ?>
      <div class="owner" style="margin-bottom:18px">
        <span class="av"><?= e(mb_strtoupper(mb_substr($p['shop_name'], 0, 1))) ?></span>
        <div style="flex:1;min-width:0"><div class="muted sm">Product author</div><a class="link" href="/shop/<?= e($p['slug']) ?>"><b><?= e($p['shop_name']) ?></b></a>
          <?php if ($p['blogging_type']): ?><span class="muted sm"> · <?= e($p['blogging_type']) ?></span><?php endif; ?></div>
        <a class="btn sm" href="/shop/<?= e($p['slug']) ?>" style="flex:none">Visit shop</a>
      </div>
      <?php endif; ?>

      <form method="post" action="/do/cart-add" class="buybar-form">
        <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= $id ?>"><input type="hidden" name="shop" value="<?= $shop ?>">
        <?php if ($variants): ?><div style="margin-bottom:16px"><div class="muted sm" style="margin-bottom:8px;font-weight:600">Option</div><div class="opts">
          <?php foreach ($variants as $i => $v): ?><label><input type="radio" name="variant" value="<?= e($v['name']) ?>" <?= $i === 0 ? 'checked' : '' ?> <?= (int)$v['stock'] < 1 ? 'disabled' : '' ?>><span><?= e($v['name']) ?><?= (float)($v['price'] ?? 0) > 0 ? ' · ' . money($v['price']) : '' ?></span></label><?php endforeach; ?>
        </div></div><?php endif; ?>
        <div class="buybar">
          <div class="qty"><button type="button" data-d="-">−</button><input name="qty" type="number" value="1" min="1" max="<?= max(1, $stock) ?>"><button type="button" data-d="+">+</button></div>
          <button class="btn gold lg" <?= $stock < 1 ? 'disabled' : '' ?>><?= ico('cart', 18) ?> Add to cart</button>
        </div>
      </form>
      <div class="buybar" style="margin-top:12px">
        <?php if (can_resell($u) && (int)$p['owner_uid'] !== (int)$u['id']): ?>
          <form method="post" action="/do/collect"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn <?= $collected ? 'on' : 'dark' ?>" data-on="Collected ✓ — remove" data-off="Collect"><?= ico('layers', 16) ?> <span class="lbl"><?= $collected ? 'Collected ✓ — remove' : 'Collect' ?></span></button></form>
        <?php elseif (!$u): ?><a class="btn" href="/register?as=vendor"><?= ico('layers', 16) ?> Collect &amp; earn</a><?php endif; ?>
        <form method="post" action="/do/wish"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn<?= wished($id) ? ' on' : '' ?>" data-on="Saved ✓" data-off="Watch later"><?= ico('clock', 16) ?> <span class="lbl"><?= wished($id) ? 'Saved ✓' : 'Watch later' ?></span></button></form>
        <?php $askUid = $ref ? (int)$ref['user_id'] : (int)$p['owner_uid']; if ($u && $askUid !== (int)$u['id']): ?><a class="btn" href="/cabinet/messenger/<?= $askUid ?>?product=<?= $id ?>"><?= ico('msg', 16) ?> Ask <?= $ref ? e($ref['shop_name']) : 'seller' ?></a><?php endif; ?>
      </div>

      <?php if ($p['description']): ?><div class="attrs"><h4>Description</h4><div><?= nl2br(e($p['description'])) ?></div></div><?php endif; ?>
      <?php foreach ($groups as $sec => $list): ?><div class="attrs"><h4><?= e($sec) ?></h4><dl><?php foreach ($list as $a): ?><dt><?= e($a['name']) ?></dt><dd><?= e($a['value']) ?></dd><?php endforeach; ?></dl></div><?php endforeach; ?>
      <?php if ($p['sku']): ?><p class="muted sm" style="margin-top:18px">SKU: <?= e($p['sku']) ?></p><?php endif; ?>
    </div>
  </div>
</div>
<?php }, ['desc' => $p['short_desc'] ?: $p['title']]);
