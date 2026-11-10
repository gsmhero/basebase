<?php
$u = require_role('vendor', 'supplier', 'admin');
$vid = (int)$u['vid'];
$isAdmin = $u['role'] === 'admin';
$sub = $seg[2] ?? '';

/* ---------- list ---------- */
if ($sub === '') {
    if (is_post() && ($_POST['do'] ?? '') === 'delete') {
        csrf_check();
        q('DELETE FROM posts WHERE id = ? AND (vendor_id = ? OR ?)', [(int)$_POST['id'], $vid, $isAdmin ? 1 : 0]);
        flash('Post deleted.'); redirect('/cabinet/blog');
    }
    $list = rows('SELECT po.*, (SELECT COUNT(*) FROM post_items WHERE post_id = po.id) n FROM posts po WHERE po.vendor_id = ? OR ? ORDER BY po.id DESC', [$vid, $isAdmin ? 1 : 0]);
    layout('My blog', function () use ($list) { ?>
    <div class="card-h"><div><h2 style="margin:0">My blog</h2><span class="muted"><?= count($list) ?> posts</span></div><a class="btn gold" href="/cabinet/blog/new"><?= ico('plus', 16) ?> New post</a></div>
    <?php if ($list): ?><section class="card scroll"><table class="tbl"><thead><tr><th>Post</th><th>Status</th><th class="num">Attached</th><th class="num">Views</th><th>Date</th><th></th></tr></thead><tbody>
      <?php foreach ($list as $po): ?><tr><td><a class="link" href="/blog/<?= $po['id'] ?>"><b><?= e(mb_strimwidth($po['title'], 0, 56, '…')) ?></b></a></td><td><?= badge($po['status'] === 'published' ? 'active' : 'draft') ?></td><td class="num"><?= $po['n'] ?></td><td class="num"><?= num($po['views']) ?></td><td class="muted"><?= date('d.m.Y', strtotime($po['created_at'])) ?></td>
        <td class="right" style="white-space:nowrap"><a class="icon-btn" style="display:inline-grid" href="/cabinet/blog/<?= $po['id'] ?>" title="Edit"><?= ico('edit', 18) ?></a>
          <form method="post" data-confirm="Delete this post?" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $po['id'] ?>"><button class="icon-btn" title="Delete"><?= ico('trash', 18) ?></button></form></td></tr><?php endforeach; ?></tbody></table></section>
    <?php else: ?><div class="card empty"><h2>Your blog is empty</h2><p>Write a post and attach products, sets or groups — readers can buy straight from it.</p><a class="btn gold" href="/cabinet/blog/new">Write the first post</a></div><?php endif; ?>
    <?php }, ['cabinet' => true]);
    exit;
}

/* ---------- add / edit ---------- */
$post = $sub === 'new' ? null : row('SELECT * FROM posts WHERE id = ?', [(int)$sub]);
if ($sub !== 'new' && (!$post || ((int)$post['vendor_id'] !== $vid && !$isAdmin))) { flash('Post not found.', 'warn'); redirect('/cabinet/blog'); }
$ownerVid = $post ? (int)$post['vendor_id'] : $vid;

// what can be attached: own + collected products, own sets, groups of the collection
$products = rows("SELECT p.id, p.title, p.price FROM products p WHERE p.status = 'active' AND (p.vendor_id = ? OR p.id IN (SELECT product_id FROM collection_items WHERE vendor_id = ?)) ORDER BY p.title", [$ownerVid, $ownerVid]);
$sets = rows('SELECT s.id, s.title, ' . SET_SUM_SQL . ' AS sum FROM product_sets s WHERE s.vendor_id = ? ORDER BY s.id DESC', [$ownerVid]);
$groups = rows("SELECT grp, COUNT(*) n FROM collection_items WHERE vendor_id = ? AND grp <> '' GROUP BY grp ORDER BY grp", [$ownerVid]);

if (is_post()) {
    csrf_check();
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
    $body = trim((string)($_POST['body'] ?? ''));
    if ($title === '') flash('Give your post a title.', 'bad');
    else {
        $status = ($_POST['status'] ?? '') === 'draft' ? 'draft' : 'published';
        $cover = $post['cover'] ?? null;
        if (!empty($_FILES['cover']['name']) && ($up = save_upload($_FILES['cover']))) $cover = $up;
        if (!empty($_POST['remove_cover'])) $cover = null;
        if ($post) { q('UPDATE posts SET title = ?, body = ?, cover = ?, status = ? WHERE id = ?', [$title, $body, $cover, $status, $post['id']]); $pid = (int)$post['id']; }
        else { q('INSERT INTO posts (vendor_id, title, body, cover, status) VALUES (?,?,?,?,?)', [$vid, $title, $body, $cover, $status]); $pid = (int)db()->lastInsertId(); }

        q('DELETE FROM post_items WHERE post_id = ?', [$pid]);
        $okP = array_column($products, 'id'); $okS = array_column($sets, 'id'); $okG = array_column($groups, 'grp'); $pos = 0;
        foreach ((array)($_POST['products'] ?? []) as $x) if (in_array((int)$x, array_map('intval', $okP), true)) q("INSERT INTO post_items (post_id, kind, ref, pos) VALUES (?, 'product', ?, ?)", [$pid, (int)$x, $pos++]);
        foreach ((array)($_POST['sets'] ?? []) as $x) if (in_array((int)$x, array_map('intval', $okS), true)) q("INSERT INTO post_items (post_id, kind, ref, pos) VALUES (?, 'set', ?, ?)", [$pid, (int)$x, $pos++]);
        foreach ((array)($_POST['groups'] ?? []) as $x) if (in_array((string)$x, $okG, true)) q("INSERT INTO post_items (post_id, kind, label, pos) VALUES (?, 'group', ?, ?)", [$pid, (string)$x, $pos++]);
        flash($post ? 'Post saved.' : 'Post published.');
        redirect('/blog/' . $pid);
    }
}

$sel = ['product' => [], 'set' => [], 'group' => []];
if ($post) foreach (rows('SELECT * FROM post_items WHERE post_id = ?', [$post['id']]) as $it) $sel[$it['kind']][] = $it['kind'] === 'group' ? $it['label'] : (int)$it['ref'];
$vals = ['title' => $_POST['title'] ?? ($post['title'] ?? ''), 'body' => $_POST['body'] ?? ($post['body'] ?? ''), 'status' => $_POST['status'] ?? ($post['status'] ?? 'published')];

layout($post ? 'Edit post' : 'New post', function () use ($post, $vals, $products, $sets, $groups, $sel) { ?>
<div class="card-h"><div><div class="crumbs" style="margin:0"><a href="/cabinet/blog">My blog</a> /</div><h2 style="margin:0"><?= $post ? 'Edit post' : 'New post' ?></h2></div></div>
<form method="post" enctype="multipart/form-data" class="dash-grid"><?= csrf_field() ?>
  <section class="card span-8">
    <?= field('Title', 'title', $vals['title'], 'text', 'required maxlength="255"') ?>
    <label class="fld"><span>Text</span><textarea name="body" rows="14" placeholder="Tell your story, review a product, share a routine…"><?= e($vals['body']) ?></textarea></label>
    <label class="fld"><span>Cover image</span><input type="file" name="cover" accept="image/*" data-preview="#pv-cover"></label>
    <div class="upl" id="pv-cover"><?php if (!empty($post['cover'])): ?><img src="<?= e($post['cover']) ?>" alt=""><?php endif; ?></div>
    <?php if (!empty($post['cover'])): ?><label class="muted sm" style="display:block;margin-top:8px"><input type="checkbox" name="remove_cover" value="1"> Remove cover</label><?php endif; ?>
  </section>
  <section class="span-4" style="display:grid;gap:22px;align-content:start">
    <div class="card"><h3>Publishing</h3>
      <label class="fld"><span>Status</span><select name="status"><option value="published" <?= $vals['status'] === 'published' ? 'selected' : '' ?>>Published</option><option value="draft" <?= $vals['status'] === 'draft' ? 'selected' : '' ?>>Draft — only you</option></select></label>
      <button class="btn gold block lg"><?= $post ? 'Save post' : 'Publish post' ?></button></div>
    <div class="card picks"><h3>Attach to post</h3><p class="muted sm" style="margin:-6px 0 0">Readers will see these under your text and can buy through your blog.</p>
      <div><div class="muted sm" style="font-weight:600;margin-bottom:6px">Products</div><div class="pick-box"><?php foreach ($products as $p): ?><label><input type="checkbox" name="products[]" value="<?= $p['id'] ?>" <?= in_array((int)$p['id'], $sel['product'], true) ? 'checked' : '' ?>><span><?= e($p['title']) ?></span><b class="sm"><?= money($p['price']) ?></b></label><?php endforeach; if (!$products) echo '<p class="muted sm">Collect products first.</p>'; ?></div></div>
      <div><div class="muted sm" style="font-weight:600;margin-bottom:6px">Sets</div><div class="pick-box"><?php foreach ($sets as $s): ?><label><input type="checkbox" name="sets[]" value="<?= $s['id'] ?>" <?= in_array((int)$s['id'], $sel['set'], true) ? 'checked' : '' ?>><span><?= e($s['title']) ?></span><b class="sm"><?= money($s['sum']) ?></b></label><?php endforeach; if (!$sets) echo '<p class="muted sm">No sets yet.</p>'; ?></div></div>
      <div><div class="muted sm" style="font-weight:600;margin-bottom:6px">Groups</div><div class="pick-box"><?php foreach ($groups as $g): ?><label><input type="checkbox" name="groups[]" value="<?= e($g['grp']) ?>" <?= in_array($g['grp'], $sel['group'], true) ? 'checked' : '' ?>><span><?= e($g['grp']) ?></span><b class="sm"><?= $g['n'] ?> items</b></label><?php endforeach; if (!$groups) echo '<p class="muted sm">No groups yet.</p>'; ?></div></div>
    </div>
  </section>
</form>
<?php }, ['cabinet' => true]);
