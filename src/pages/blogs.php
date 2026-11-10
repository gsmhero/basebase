<?php
$view = ($_GET['view'] ?? '') === 'authors' ? 'authors' : 'posts';
$pg = max(1, (int)($_GET['page'] ?? 1)); $per = 12;
$posts = $authors = []; $total = 0;
if ($view === 'posts') {
    $total = (int)val("SELECT COUNT(*) FROM posts WHERE status = 'published'");
    $posts = rows("SELECT po.*, v.shop_name, v.slug, (SELECT COUNT(*) FROM post_items WHERE post_id = po.id) n FROM posts po JOIN vendors v ON v.id = po.vendor_id WHERE po.status = 'published' ORDER BY po.id DESC LIMIT $per OFFSET " . (($pg - 1) * $per));
} else {
    $authors = rows("SELECT v.*, u.name, u.role, (SELECT COUNT(*) FROM collection_items ci WHERE ci.vendor_id = v.id) n, (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id AND p.status='active') own, (SELECT COUNT(*) FROM posts po WHERE po.vendor_id = v.id AND po.status='published') pc FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.status = 'active' AND u.role <> 'customer' ORDER BY pc DESC, n DESC, own DESC, v.id DESC LIMIT 60");
}
$u = me();
layout('Blogs', function () use ($view, $posts, $authors, $total, $pg, $per, $u) { ?>
<div class="wrap">
  <div class="page-h"><div class="crumbs"><a href="/">Home</a> / Blogs</div>
    <div class="row" style="align-items:flex-end;justify-content:space-between"><h1 style="flex:none">Blogs</h1>
      <?php if ($u && $u['role'] !== 'customer'): ?><a class="btn gold" style="flex:none" href="/cabinet/blog/new"><?= ico('plus', 16) ?> Write a post</a><?php endif; ?></div>
    <p class="muted">Stories and picks from creators — with products, sets and groups you can buy right in the post.</p></div>
  <div class="tabs" style="margin-top:18px"><a class="<?= $view === 'posts' ? 'on' : '' ?>" href="/blogs">Posts</a><a class="<?= $view === 'authors' ? 'on' : '' ?>" href="/blogs?view=authors">Authors &amp; shops</a></div>

  <?php if ($view === 'posts'): ?>
    <?php if ($posts): ?><div class="posts"><?php foreach ($posts as $po): ?>
      <a class="post-card" href="/blog/<?= $po['id'] ?>"><div class="post-cover"><?= $po['cover'] ? '<img src="' . e($po['cover']) . '" alt="" loading="lazy">' : pimg(['id' => $po['id'] + 3, 'title' => $po['title']]) ?></div>
        <div class="post-body"><h3><?= e($po['title']) ?></h3><p><?= e(mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string)$po['body'])), 0, 120, '…')) ?></p>
          <div class="post-meta"><span class="av"><?= e(mb_strtoupper(mb_substr($po['shop_name'], 0, 1))) ?></span><span><?= e($po['shop_name']) ?> · <?= date('d.m.Y', strtotime($po['created_at'])) ?></span><span style="margin-left:auto"><?= ico('box', 14) ?> <?= $po['n'] ?></span></div></div></a>
    <?php endforeach; ?></div><?= pager($total, $per, $pg) ?>
    <?php else: ?><div class="card empty"><h2>No posts yet</h2><p>Be the first to write one.</p><?php if ($u && $u['role'] !== 'customer'): ?><a class="btn gold" href="/cabinet/blog/new">Write a post</a><?php else: ?><a class="btn dark" href="/register">Create an account</a><?php endif; ?></div><?php endif; ?>
  <?php else: ?>
    <?php if ($authors): ?><div class="bloggers"><?php foreach ($authors as $s):
      $soc = json_decode($s['socials'] ?? '{}', true) ?: []; $f = 0; foreach ($soc as $x) $f += (int)($x['followers'] ?? 0); ?>
      <a class="blogger" href="/shop/<?= e($s['slug']) ?>">
        <div class="row" style="flex-wrap:nowrap;align-items:center"><span class="av"><?= e(mb_strtoupper(mb_substr($s['shop_name'], 0, 1))) ?></span><div><b><?= e($s['shop_name']) ?></b><div class="muted sm"><?= e($s['blogging_type'] ?: ($s['role'] === 'supplier' ? 'Supplier' : 'Reseller')) ?></div></div></div>
        <p class="muted sm" style="margin:0;min-height:2.8em"><?= e(mb_strimwidth((string)$s['bio'], 0, 110, '…')) ?></p>
        <div class="chips" style="margin:0"><span class="chip d"><?= $s['n'] + $s['own'] ?> products</span><?php if ($s['pc']): ?><span class="chip d"><?= $s['pc'] ?> posts</span><?php endif; ?><?php if ($f): ?><span class="chip d"><?= num($f) ?> followers</span><?php endif; ?></div>
      </a><?php endforeach; ?></div>
    <?php else: ?><div class="card empty"><h2>No shops yet</h2></div><?php endif; ?>
  <?php endif; ?>
</div>
<?php });
