<?php
$key = (string)($seg[1] ?? '');
$v = row('SELECT v.*, u.name, u.role FROM vendors v JOIN users u ON u.id = v.user_id WHERE v.slug = ? OR v.id = ?', [$key, (int)$key]);
if (!$v) { http_response_code(404); layout('Shop not found', fn() => print('<section class="wrap empty"><h2>Shop not found</h2><a class="btn dark" href="/blogs">All shops</a></section>')); exit; }
$u = me();
$mine = $u && (int)$u['vid'] === (int)$v['id'];
$group = trim((string)($_GET['group'] ?? ''));
$pg = max(1, (int)($_GET['page'] ?? 1)); $per = 24;

$w = "p.status='active' AND (p.id IN (SELECT product_id FROM collection_items WHERE vendor_id = ?" . ($group !== '' ? ' AND grp = ?' : '') . ')' . ($group !== '' ? '' : ' OR p.vendor_id = ?') . ')';
$args = $group !== '' ? [$v['id'], $group] : [$v['id'], $v['id']];
$total = (int)val("SELECT COUNT(*) FROM products p WHERE $w", $args);
$items = rows("SELECT p.*, c.name cat_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE $w ORDER BY p.id DESC LIMIT $per OFFSET " . (($pg - 1) * $per), $args);
$groups = rows("SELECT grp, COUNT(*) n FROM collection_items WHERE vendor_id = ? AND grp <> '' GROUP BY grp ORDER BY grp", [$v['id']]);
$sets = rows('SELECT s.*, (SELECT COUNT(*) FROM set_items WHERE set_id = s.id) n, ' . SET_SUM_SQL . ' AS sum FROM product_sets s WHERE vendor_id = ? ORDER BY id DESC', [$v['id']]);
$posts = rows("SELECT po.*, (SELECT COUNT(*) FROM post_items WHERE post_id = po.id) n FROM posts po WHERE po.vendor_id = ? AND po.status = 'published' ORDER BY po.id DESC LIMIT 6", [$v['id']]);
$postCount = (int)val("SELECT COUNT(*) FROM posts WHERE vendor_id = ? AND status = 'published'", [$v['id']]);
$soc = json_decode($v['socials'] ?? '{}', true) ?: [];
$followers = 0; foreach ($soc as $s) $followers += (int)($s['followers'] ?? 0);

layout($v['shop_name'], function () use ($v, $posts, $postCount, $items, $total, $groups, $sets, $soc, $followers, $mine, $group, $pg, $per) { ?>
<div class="wrap">
  <section class="shop-hero">
    <span class="av lg"><?= e(mb_strtoupper(mb_substr($v['shop_name'], 0, 1))) ?></span>
    <div style="position:relative;z-index:1">
      <h1><?= e($v['shop_name']) ?></h1>
      <div class="chips"><?php if ($v['blogging_type']): ?><span class="chip"><?= e($v['blogging_type']) ?></span><?php endif; ?>
        <?php if ($followers): ?><span class="chip"><?= num($followers) ?> followers</span><?php endif; ?>
        <span class="chip"><?= num($total) ?> products</span>
        <?php if ($postCount): ?><a class="chip" href="#blog">Blog · <?= $postCount ?> post<?= $postCount === 1 ? '' : 's' ?></a><?php endif; ?>
        <?php foreach ($soc as $name => $s): if (!empty($s['url'])): ?><a class="chip" href="<?= e($s['url']) ?>" rel="nofollow noopener" target="_blank"><?= e($name) ?></a><?php endif; endforeach; ?></div>
      <?php if ($v['bio']): ?><p><?= e($v['bio']) ?></p><?php endif; ?>
    </div>
    <?php if ($mine): ?><div style="margin-left:auto;position:relative;z-index:1"><a class="btn" href="/cabinet/profile"><?= ico('edit', 16) ?> Edit profile</a></div><?php endif; ?>
  </section>

  <?php if ($posts): ?><section class="sec" id="blog" style="margin:40px 0 0;scroll-margin-top:90px"><div class="sec-h"><div><span class="eyebrow">Blog</span><h2>Posts by <?= e($v['shop_name']) ?></h2></div><a class="btn sm" href="/blogs?author=<?= e($v['slug']) ?>">All posts (<?= $postCount ?>)</a></div>
    <div class="posts"><?php foreach ($posts as $po): ?>
      <a class="post-card" href="/blog/<?= $po['id'] ?>"><div class="post-cover"><?= $po['cover'] ? '<img src="' . e($po['cover']) . '" alt="" loading="lazy">' : pimg(['id' => $po['id'] + 3, 'title' => $po['title']]) ?></div>
        <div class="post-body"><h3><?= e($po['title']) ?></h3><p><?= e(mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string)$po['body'])), 0, 110, '…')) ?></p>
          <div class="post-meta"><span><?= date('d.m.Y', strtotime($po['created_at'])) ?></span><span style="margin-left:auto"><?= ico('box', 14) ?> <?= $po['n'] ?></span></div></div></a>
    <?php endforeach; ?></div></section><?php endif; ?>

  <?php if ($sets): ?><section class="sec" style="margin:40px 0 0"><div class="sec-h"><h2>Sets</h2></div>
    <div class="mini-grid"><?php foreach ($sets as $s): ?><a class="card" href="/set/<?= $s['id'] ?>"><span class="badge info"><?= $s['n'] ?> items</span><h3 style="margin:10px 0 4px"><?= e($s['title']) ?></h3><div class="price"><?= money($s['sum']) ?></div></a><?php endforeach; ?></div></section><?php endif; ?>

  <section class="sec" style="margin-top:40px">
    <div class="sec-h"><h2><?= $group !== '' ? e($group) : 'Products' ?></h2>
      <div class="pill-nav"><a class="btn sm <?= $group === '' ? 'dark' : '' ?>" href="/shop/<?= e($v['slug']) ?>">All</a><?php foreach ($groups as $g): ?><a class="btn sm <?= $group === $g['grp'] ? 'dark' : '' ?>" href="/shop/<?= e($v['slug']) ?>?group=<?= urlencode($g['grp']) ?>"><?= e($g['grp']) ?> · <?= $g['n'] ?></a><?php endforeach; ?></div></div>
    <?php if ($items): ?><div class="pgrid"><?php foreach ($items as $p) echo product_card($p, (int)$v['id']); ?></div><?= pager($total, $per, $pg, $group !== '' ? ['group' => $group] : []) ?>
    <?php else: ?><div class="card empty"><h2>No products yet</h2><p>This shop is just getting started.</p></div><?php endif; ?>
  </section>
</div>
<?php });
