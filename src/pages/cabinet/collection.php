<?php
$u = require_role('vendor', 'admin');
$vid = (int)$u['vid'];
$tab = in_array($_GET['tab'] ?? '', ['sets', 'groups'], true) ? $_GET['tab'] : 'products';

if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'remove') q('DELETE FROM collection_items WHERE vendor_id = ? AND product_id = ?', [$vid, (int)$_POST['id']]);
    elseif ($do === 'group') {
        $g = mb_substr(trim((string)($_POST['grp'] ?? '')), 0, 80);
        q('UPDATE collection_items SET grp = ? WHERE vendor_id = ? AND product_id = ?', [$g, $vid, (int)$_POST['id']]);
        flash('Group updated.');
    } elseif ($do === 'set-create') {
        $ids = array_map('intval', (array)($_POST['items'] ?? []));
        $title = trim((string)($_POST['title'] ?? ''));
        $ids = $ids ? array_column(rows('SELECT product_id FROM collection_items WHERE vendor_id = ? AND product_id IN (' . in_clause($ids) . ')', [$vid, ...$ids]), 'product_id') : [];
        $price = $ids ? (float)val('SELECT SUM(price) FROM products WHERE id IN (' . in_clause($ids) . ')', $ids) : 0;
        if ($title === '' || count($ids) < 2) flash('A set needs a title and at least 2 products from your collection.', 'bad');
        else {
            q('INSERT INTO product_sets (vendor_id, title, price) VALUES (?,?,?)', [$vid, $title, $price]);
            $sid = (int)db()->lastInsertId();
            foreach ($ids as $pid) q('INSERT INTO set_items (set_id, product_id, qty) VALUES (?,?,1)', [$sid, $pid]);
            flash('Set created.'); redirect('/cabinet/collection?tab=sets');
        }
    } elseif ($do === 'set-delete') { q('DELETE FROM product_sets WHERE id = ? AND vendor_id = ?', [(int)$_POST['id'], $vid]); flash('Set deleted.'); redirect('/cabinet/collection?tab=sets'); }
    back('/cabinet/collection');
}

$host = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'www.basebase.ru');
$items = rows('SELECT p.*, ci.grp, c.name cat_name FROM collection_items ci JOIN products p ON p.id = ci.product_id LEFT JOIN categories c ON c.id = p.category_id WHERE ci.vendor_id = ? ORDER BY ci.created_at DESC', [$vid]);
$sets = rows('SELECT s.*, (SELECT COUNT(*) FROM set_items WHERE set_id = s.id) n, ' . SET_SUM_SQL . ' AS sum FROM product_sets s WHERE s.vendor_id = ? ORDER BY s.id DESC', [$vid]);
$groups = []; foreach ($items as $i) if ($i['grp'] !== '') $groups[$i['grp']][] = $i;

function share_box(string $url, string $label): string {
    $code = '<a href="' . $url . '">' . $label . '</a>';
    return '<div class="share-grid"><button type="button" class="btn sm" data-copy="' . e($url) . '">' . ico('link', 14) . ' Link URL</button><button type="button" class="btn sm" data-copy="' . e($code) . '">' . ico('code', 14) . ' Code</button></div>';
}

layout('Product collection', function () use ($items, $sets, $groups, $tab, $host, $u, $vid) { ?>
<div class="card-h"><div><h2 style="margin:0">My product collection</h2><span class="muted"><?= count($items) ?> collected · <?= count($sets) ?> sets · <?= count($groups) ?> groups</span></div><a class="btn gold" href="/products"><?= ico('plus', 16) ?> Collect more</a></div>
<div class="tabs"><a class="<?= $tab === 'products' ? 'on' : '' ?>" href="?tab=products">Products</a><a class="<?= $tab === 'sets' ? 'on' : '' ?>" href="?tab=sets">Sets</a><a class="<?= $tab === 'groups' ? 'on' : '' ?>" href="?tab=groups">Groups</a></div>

<?php if ($tab === 'products'): ?>
  <?php if ($items): ?><div class="mini-grid"><?php foreach ($items as $p): $url = $host . '/product/' . $p['id'] . '?shop=' . $vid; ?>
    <article class="pcard"><a class="pcard-img" href="/product/<?= $p['id'] ?>"><?= pimg($p) ?></a><div class="pcard-body">
      <a class="pcard-title" href="/product/<?= $p['id'] ?>"><?= e($p['title']) ?></a>
      <div class="row" style="flex:none;justify-content:space-between;gap:6px"><div class="price"><?= money(eff_price($p)) ?></div><span class="badge ok" style="flex:none">+<?= money(reseller_earn($p)) ?></span></div>
      <form method="post" class="share-box" style="margin-top:6px"><?= csrf_field() ?><input type="hidden" name="do" value="group"><input type="hidden" name="id" value="<?= $p['id'] ?>"><input name="grp" value="<?= e($p['grp']) ?>" placeholder="Group" list="grp-list" maxlength="80"><button class="btn sm">Set</button></form>
      <div style="margin-top:8px"><?= share_box($url, $p['title']) ?></div>
      <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="remove"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn sm danger block"><?= ico('trash', 14) ?> Remove</button></form>
    </div></article><?php endforeach; ?></div>
    <datalist id="grp-list"><?php foreach (array_keys($groups) as $g): ?><option value="<?= e($g) ?>"><?php endforeach; ?></datalist>
  <?php else: ?><div class="card empty"><h2>Your collection is empty</h2><p>Press “Collect” on products in the catalog to build your showcase.</p><a class="btn gold" href="/products">Browse catalog</a></div><?php endif; ?>

<?php elseif ($tab === 'sets'): ?>
  <div class="dash-grid">
    <section class="card span-7"><div class="card-h"><h3>My sets</h3></div>
      <?php foreach ($sets as $s): $url = $host . '/set/' . $s['id']; ?><div class="row" style="flex-wrap:nowrap;align-items:center;padding:14px 0;border-bottom:1px solid var(--line)"><div style="min-width:0"><a class="link" href="/set/<?= $s['id'] ?>"><b><?= e($s['title']) ?></b></a><div class="muted sm"><?= $s['n'] ?> items · <?= money($s['sum']) ?></div></div>
        <div style="flex:none"><?= share_box($url, $s['title']) ?></div>
        <form method="post" data-confirm="Delete this set?" style="flex:none"><?= csrf_field() ?><input type="hidden" name="do" value="set-delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button class="icon-btn"><?= ico('trash', 18) ?></button></form></div><?php endforeach; ?>
      <?php if (!$sets): ?><p class="muted">No sets yet. Create the first one →</p><?php endif; ?></section>
    <form method="post" class="card span-5"><?= csrf_field() ?><input type="hidden" name="do" value="set-create"><h3>New set</h3>
      <?= field('Set title', 'title', '', 'text', 'required') ?>      <div class="fld"><span>Pick products (min. 2)</span><div style="max-height:260px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:6px 10px"><?php foreach ($items as $p): ?><label style="display:flex;gap:10px;align-items:center;padding:6px 0"><input type="checkbox" name="items[]" value="<?= $p['id'] ?>" data-price="<?= eff_price($p) ?>"><span style="flex:1"><?= e(mb_strimwidth($p['title'], 0, 38, '…')) ?></span><b class="sm"><?= money(eff_price($p)) ?></b></label><?php endforeach; if (!$items) echo '<p class="muted">Collect products first.</p>'; ?></div></div>
      <div class="row" style="justify-content:space-between;flex-wrap:nowrap;padding:4px 0 14px"><span class="muted">Set price (sum of products)</span><b id="set-total" style="flex:none">0 ₽</b></div>
      <button class="btn dark block" <?= count($items) < 2 ? 'disabled' : '' ?>>Create set</button></form>
  </div>

<?php else: ?>
  <?php if ($groups): foreach ($groups as $g => $list): $url = $host . '/shop/' . $u['slug'] . '?group=' . urlencode($g); ?>
    <section class="card"><div class="card-h"><h3><?= e($g) ?> <span class="muted sm">· <?= count($list) ?></span></h3><?= share_box($url, $g) ?></div>
      <div class="row" style="gap:10px"><?php foreach ($list as $p): ?><a href="/product/<?= $p['id'] ?>" class="owner" style="flex:none;min-width:0;max-width:260px"><span style="width:44px;height:44px;border-radius:8px;overflow:hidden;flex:none"><?= pimg($p) ?></span><span style="min-width:0"><b class="sm" style="display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($p['title']) ?></b><span class="muted sm"><?= money(eff_price($p)) ?></span></span></a><?php endforeach; ?></div></section>
  <?php endforeach; else: ?><div class="card empty"><h2>No groups yet</h2><p>On the Products tab, type a group name under any product to start grouping.</p></div><?php endif; ?>
<?php endif; ?>
<?php }, ['cabinet' => true]);
