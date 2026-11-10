<?php
$u = owner_only(require_login());
$items = rows('SELECT p.*, c.name cat_name FROM wishlist w JOIN products p ON p.id = w.product_id LEFT JOIN categories c ON c.id = p.category_id WHERE w.user_id = ? ORDER BY w.created_at DESC', [$u['id']]);
layout('Watch later', function () use ($items) { ?>
<div class="wrap">
  <div class="page-h"><div class="crumbs"><a href="/">Home</a> / Watch later</div><h1>Watch later</h1></div>
  <?php if ($items): ?><div class="pgrid" style="margin-top:24px"><?php foreach ($items as $p) echo product_card($p); ?></div>
  <?php else: ?><div class="card empty" style="margin-top:24px"><h2>Nothing saved</h2><p>Tap the clock icon on any product to save it here.</p><a class="btn dark" href="/products">Browse products</a></div><?php endif; ?>
</div>
<?php });
