<?php
$stats = [
    'Products' => (int)val("SELECT COUNT(*) FROM products WHERE status='active'"),
    'Shops & bloggers' => (int)val("SELECT COUNT(*) FROM vendors WHERE status='active'"),
    'Orders' => (int)val('SELECT COUNT(*) FROM orders'),
];
$cats = rows('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.status="active" AND (p.category_id=c.id OR p.category_id IN (SELECT id FROM categories WHERE parent_id=c.id))) n FROM categories c WHERE c.parent_id IS NULL ORDER BY c.id');
$new = rows("SELECT p.*, c.name cat_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE p.status='active' ORDER BY p.id DESC LIMIT 8");
$shops = rows("SELECT v.*, u.name, (SELECT COUNT(*) FROM collection_items ci WHERE ci.vendor_id=v.id) n FROM vendors v JOIN users u ON u.id=v.user_id WHERE v.status='active' AND u.role IN ('vendor','admin') ORDER BY n DESC, v.id DESC LIMIT 4");
$icons = ['grid', 'zap', 'home', 'tag', 'users', 'truck'];

layout('Multivendor marketplace', function () use ($stats, $cats, $new, $shops, $icons) { ?>
<div class="wrap">
  <section class="hero">
    <div class="hero-orb"></div>
    <span class="eyebrow" style="color:#d9b25f">Marketplace · Dropshipping</span>
    <h1>Collect products. Share them. <em>Earn on every sale.</em></h1>
    <p>Pick goods from verified suppliers, build your own showcase and share it with your audience. Suppliers store and ship — you keep the commission.</p>
    <div class="cta">
      <a class="btn gold lg" href="/register">Start earning <?= ico('arrow', 18) ?></a>
      <a class="btn ghost lg" href="/products">Browse products</a>
    </div>
    <div class="hero-stats"><?php foreach ($stats as $k => $v): ?><div><b><?= num($v) ?></b><span><?= e($k) ?></span></div><?php endforeach; ?></div>
  </section>

  <section class="sec">
    <div class="sec-h"><div><span class="eyebrow">Categories</span><h2>Shop by category</h2></div><a class="btn sm" href="/products">All products</a></div>
    <div class="cats"><?php foreach ($cats as $i => $c): ?>
      <a class="cat" href="/products?cat=<?= $c['id'] ?>"><span class="ci"><?= ico($icons[$i % 6], 22) ?></span><b><?= e($c['name']) ?></b><small><?= num($c['n']) ?> products</small></a>
    <?php endforeach; ?></div>
  </section>

  <?php if ($new): ?>
  <section class="sec">
    <div class="sec-h"><div><span class="eyebrow">Just added</span><h2>New arrivals</h2></div><a class="btn sm" href="/products?sort=new">See all</a></div>
    <div class="pgrid"><?php foreach ($new as $p) echo product_card($p); ?></div>
  </section>
  <?php endif; ?>

  <section class="sec">
    <div class="sec-h"><div><span class="eyebrow">How it works</span><h2>Three steps to your own shop</h2></div></div>
    <div class="steps">
      <div class="step"><h3>Collect</h3><p>Browse the catalog and press <b>Collect</b> on products you like. They land in your personal collection.</p></div>
      <div class="step"><h3>Share</h3><p>Group items into sets and share a link or embed code — or send people to your own shop page.</p></div>
      <div class="step"><h3>Earn</h3><p>When a customer orders through your link, the supplier ships and your commission goes to your wallet.</p></div>
    </div>
  </section>

  <?php if ($shops): ?>
  <section class="sec">
    <div class="sec-h"><div><span class="eyebrow">Community</span><h2>Popular shops &amp; bloggers</h2></div><a class="btn sm" href="/blogs">All blogs</a></div>
    <div class="bloggers"><?php foreach ($shops as $s): ?>
      <a class="blogger" href="/shop/<?= e($s['slug']) ?>"><span class="av"><?= e(mb_strtoupper(mb_substr($s['shop_name'], 0, 1))) ?></span><div><b><?= e($s['shop_name']) ?></b><div class="muted sm"><?= e($s['blogging_type'] ?: 'Reseller') ?> · <?= $s['n'] ?> products</div></div></a>
    <?php endforeach; ?></div>
  </section>
  <?php endif; ?>

  <section class="sec">
    <div class="cta-band"><div><h2>Own products? Become a supplier.</h2><p style="margin:8px 0 0">List your goods once — hundreds of bloggers sell them for you.</p></div><a class="btn dark lg" href="/register?as=supplier">Become a supplier</a></div>
  </section>
</div>
<?php });
