<?php
$list = rows("SELECT v.*, u.name, u.role, (SELECT COUNT(*) FROM collection_items ci WHERE ci.vendor_id = v.id) n, (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.status='active') own FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.status = 'active' AND u.role <> 'customer' ORDER BY n DESC, own DESC, v.id DESC LIMIT 60");
layout('Blogs & shops', function () use ($list) { ?>
<div class="wrap">
  <div class="page-h"><div class="crumbs"><a href="/">Home</a> / Blogs</div><h1>Blogs &amp; shops</h1><p class="muted">Creators who curate products from our suppliers — and suppliers themselves.</p></div>
  <?php if ($list): ?><div class="bloggers" style="margin-top:24px"><?php foreach ($list as $s):
    $soc = json_decode($s['socials'] ?? '{}', true) ?: []; $f = 0; foreach ($soc as $x) $f += (int)($x['followers'] ?? 0); ?>
    <a class="blogger" href="/shop/<?= e($s['slug']) ?>">
      <div class="row" style="flex-wrap:nowrap;align-items:center"><span class="av"><?= e(mb_strtoupper(mb_substr($s['shop_name'], 0, 1))) ?></span><div><b><?= e($s['shop_name']) ?></b><div class="muted sm"><?= e($s['blogging_type'] ?: ($s['role'] === 'supplier' ? 'Supplier' : 'Reseller')) ?></div></div></div>
      <p class="muted sm" style="margin:0;min-height:2.8em"><?= e(mb_strimwidth((string)$s['bio'], 0, 110, '…')) ?></p>
      <div class="chips" style="margin:0"><span class="chip d"><?= $s['n'] + $s['own'] ?> products</span><?php if ($f): ?><span class="chip d"><?= num($f) ?> followers</span><?php endif; ?></div>
    </a><?php endforeach; ?></div>
  <?php else: ?><div class="card empty" style="margin-top:24px"><h2>No shops yet</h2><p>Be the first — <a class="link" href="/register">create your shop</a>.</p></div><?php endif; ?>
</div>
<?php });
