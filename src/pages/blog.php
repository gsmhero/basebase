<?php
$id = (int)($seg[1] ?? 0);
$post = row('SELECT po.*, v.shop_name, v.slug, v.blogging_type, v.user_id AS owner_uid FROM posts po JOIN vendors v ON v.id = po.vendor_id WHERE po.id = ?', [$id]);
$u = me();
$isOwner = $u && $post && ((int)$post['owner_uid'] === (int)$u['id'] || $u['role'] === 'admin');
if (!$post || ($post['status'] !== 'published' && !$isOwner)) {
    http_response_code(404);
    layout('Not found', fn() => print('<section class="wrap empty"><h2>Post not found</h2><a class="btn dark" href="/blogs">All posts</a></section>'));
    exit;
}
if (empty($_SESSION['seen_post'][$id])) { q('UPDATE posts SET views = views + 1 WHERE id = ?', [$id]); $_SESSION['seen_post'][$id] = 1; }

$vid = (int)$post['vendor_id'];
$blocks = [];
foreach (rows('SELECT * FROM post_items WHERE post_id = ? ORDER BY pos, id', [$id]) as $it) {
    if ($it['kind'] === 'product') {
        $p = row("SELECT p.*, c.name cat_name FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ? AND p.status = 'active'", [$it['ref']]);
        if ($p) $blocks[] = ['product', $p];
    } elseif ($it['kind'] === 'set') {
        $s = row('SELECT s.*, (SELECT COUNT(*) FROM set_items WHERE set_id = s.id) n, ' . SET_SUM_SQL . ' AS sum FROM product_sets s WHERE s.id = ?', [$it['ref']]);
        if ($s) $blocks[] = ['set', $s];
    } else {
        $list = rows("SELECT p.*, c.name cat_name FROM collection_items ci JOIN products p ON p.id = ci.product_id LEFT JOIN categories c ON c.id = p.category_id WHERE ci.vendor_id = ? AND ci.grp = ? AND p.status = 'active'", [$vid, $it['label']]);
        if ($list) $blocks[] = ['group', $it['label'], $list];
    }
}
$more = rows("SELECT id, title FROM posts WHERE vendor_id = ? AND status = 'published' AND id <> ? ORDER BY id DESC LIMIT 4", [$vid, $id]);

layout($post['title'], function () use ($post, $blocks, $more, $isOwner, $vid, $id) { ?>
<div class="wrap">
  <article class="article" style="margin-top:28px">
    <div class="crumbs"><a href="/blogs">Blogs</a> / <a href="/shop/<?= e($post['slug']) ?>"><?= e($post['shop_name']) ?></a></div>
    <div class="row" style="align-items:flex-start;flex-wrap:nowrap"><h1><?= e($post['title']) ?></h1><?php if ($isOwner): ?><a class="btn sm" style="flex:none" href="/cabinet/blog/<?= $id ?>"><?= ico('edit', 15) ?> Edit</a><?php endif; ?></div>
    <div class="owner" style="margin-bottom:6px"><span class="av"><?= e(mb_strtoupper(mb_substr($post['shop_name'], 0, 1))) ?></span><div style="flex:1;min-width:0"><div class="muted sm">Author</div><a class="link" href="/shop/<?= e($post['slug']) ?>"><b><?= e($post['shop_name']) ?></b></a><span class="muted sm"> · <?= date('d.m.Y', strtotime($post['created_at'])) ?></span></div><a class="btn sm" style="flex:none" href="/shop/<?= e($post['slug']) ?>">Visit shop</a></div>
    <?php if ($post['status'] !== 'published'): ?><p><?= badge('draft') ?> <span class="muted sm">Visible only to you</span></p><?php endif; ?>
    <?php if ($post['cover']): ?><div class="article-cover"><img src="<?= e($post['cover']) ?>" alt=""></div><?php endif; ?>
    <div class="lead" style="margin-top:20px"><?= nl2br(e($post['body'])) ?></div>
  </article>

  <?php foreach ($blocks as $b): ?>
    <?php if ($b[0] === 'product'): ?>
      <section class="sec" style="margin:36px 0 0"><div class="pgrid" style="grid-template-columns:minmax(0,300px)"><?= product_card($b[1], $vid) ?></div></section>
    <?php elseif ($b[0] === 'set'): ?>
      <section class="sec" style="margin:36px 0 0"><a class="card owner" href="/set/<?= $b[1]['id'] ?>" style="max-width:640px;margin:0 auto;padding:22px"><span class="tool" style="background:var(--accent-soft);color:var(--accent-d);flex:none"><?= ico('layers', 20) ?></span><div style="flex:1"><span class="eyebrow" style="margin:0">Set · <?= $b[1]['n'] ?> products</span><h3 style="margin:2px 0 0"><?= e($b[1]['title']) ?></h3></div><div class="price"><?= money($b[1]['sum']) ?></div></a></section>
    <?php else: ?>
      <section class="sec" style="margin:36px 0 0"><div class="sec-h"><div><span class="eyebrow">Group</span><h2 style="font-size:24px"><?= e($b[1]) ?></h2></div></div><div class="pgrid"><?php foreach ($b[2] as $p) echo product_card($p, $vid); ?></div></section>
    <?php endif; ?>
  <?php endforeach; ?>

  <?php if ($more): ?><section class="sec" style="margin-top:56px"><div class="sec-h"><h3>More from <?= e($post['shop_name']) ?></h3></div><div class="pill-nav"><?php foreach ($more as $m): ?><a class="btn" href="/blog/<?= $m['id'] ?>"><?= e(mb_strimwidth($m['title'], 0, 50, '…')) ?></a><?php endforeach; ?></div></section><?php endif; ?>
</div>
<?php }, ['desc' => mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string)$post['body'])), 0, 160, '…') ?: $post['title']]);
