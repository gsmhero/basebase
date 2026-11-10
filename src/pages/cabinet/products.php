<?php
$u = require_role('supplier', 'admin');
$vid = (int)$u['vid'];
$sub = $seg[2] ?? '';
$tree = categories_tree();

/* ---------- list ---------- */
if ($sub === '') {
    if (is_post() && ($_POST['do'] ?? '') === 'delete') {
        csrf_check();
        $pid = (int)$_POST['id'];
        $own = row('SELECT * FROM products WHERE id = ? AND (vendor_id = ? OR ?)', [$pid, $vid, $u['role'] === 'admin' ? 1 : 0]);
        if ($own) {
            if (val('SELECT 1 FROM order_items WHERE product_id = ? LIMIT 1', [$pid])) { q("UPDATE products SET status='archived' WHERE id = ?", [$pid]); flash('Product has orders, so it was archived instead of deleted.', 'warn'); }
            else { q('DELETE FROM products WHERE id = ?', [$pid]); flash('Product deleted.'); }
        }
        redirect('/cabinet/products');
    }
    $items = rows("SELECT p.*, c.name cat_name, (SELECT COUNT(*) FROM collection_items ci WHERE ci.product_id = p.id) collectors FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.status <> 'archived' AND (p.vendor_id = ? OR ?) ORDER BY p.id DESC", [$vid, $u['role'] === 'admin' ? 1 : 0]);
    layout('My products', function () use ($items) { ?>
    <div class="card-h"><div><h2 style="margin:0">My products</h2><span class="muted"><?= count($items) ?> listed</span></div><a class="btn gold" href="/cabinet/products/new"><?= ico('plus', 16) ?> Add product</a></div>
    <?php if ($items): ?><div class="mini-grid">
      <?php foreach ($items as $p): ?><article class="pcard"><a class="pcard-img" href="/product/<?= $p['id'] ?>"><?= pimg($p) ?></a>
        <div class="pcard-body"><a class="pcard-title" href="/product/<?= $p['id'] ?>"><?= e($p['title']) ?></a>
          <div class="row" style="gap:6px;flex:none;flex-wrap:nowrap"><?= badge($p['status']) ?><span class="muted sm"><?= $p['collectors'] ?> collectors</span></div>
          <div class="pcard-foot"><div><div class="price"><?= money(eff_price($p)) ?></div><div class="muted sm">Stock <?= num($p['stock']) ?><?= (float)$p['promo_price'] > 0 ? ' · You get ' . money($p['promo_price']) : '' ?></div></div></div>
          <div class="row" style="gap:8px;margin-top:10px;flex-wrap:nowrap"><a class="btn sm block" href="/cabinet/products/<?= $p['id'] ?>"><?= ico('edit', 14) ?> Edit</a>
            <form method="post" data-confirm="Delete this product?" style="flex:none"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn sm danger"><?= ico('trash', 14) ?></button></form></div>
        </div></article><?php endforeach; ?></div>
    <?php else: ?><div class="card empty"><h2>No products yet</h2><p>Add your first product — resellers will start collecting it.</p><a class="btn gold" href="/cabinet/products/new">Add product</a></div><?php endif; ?>
    <?php }, ['cabinet' => true]);
    exit;
}

/* ---------- add / edit ---------- */
$p = $sub === 'new' ? null : row('SELECT * FROM products WHERE id = ?', [(int)$sub]);
if ($sub !== 'new' && (!$p || ((int)$p['vendor_id'] !== $vid && $u['role'] !== 'admin'))) { flash('Product not found.', 'warn'); redirect('/cabinet/products'); }

if (is_post()) {
    csrf_check();
    $title = trim((string)($_POST['title'] ?? ''));
    $price = (float)str_replace(',', '.', (string)($_POST['price'] ?? 0));
    if ($title === '' || $price <= 0) { flash('Title and a price greater than zero are required.', 'bad'); }
    else {
        $promo = (float)str_replace(',', '.', (string)($_POST['promo_price'] ?? 0));
        $promo = ($promo > 0 && $promo < $price) ? $promo : null;
        $attrs = []; foreach ((array)($_POST['a_name'] ?? []) as $i => $n) { $n = trim($n); $vv = trim((string)($_POST['a_value'][$i] ?? '')); if ($n !== '' && $vv !== '') $attrs[] = ['section' => trim((string)($_POST['a_section'][$i] ?? '')), 'name' => $n, 'value' => $vv]; }
        $variants = []; foreach ((array)($_POST['v_name'] ?? []) as $i => $n) { $n = trim($n); if ($n !== '') $variants[] = ['name' => $n, 'price' => (float)str_replace(',', '.', (string)($_POST['v_price'][$i] ?? 0)), 'stock' => max(0, (int)($_POST['v_stock'][$i] ?? 0))]; }
        $stock = $variants ? array_sum(array_column($variants, 'stock')) : max(0, (int)($_POST['stock'] ?? 0));
        $image = $p['image'] ?? null;
        if (!empty($_FILES['image']['name']) && ($up = save_upload($_FILES['image']))) $image = $up;
        $gallery = json_decode($p['gallery'] ?? '[]', true) ?: [];
        foreach ((array)($_POST['remove_g'] ?? []) as $rg) $gallery = array_values(array_diff($gallery, [$rg]));
        $gallery = array_slice([...$gallery, ...save_uploads('gallery')], 0, 8);
        $data = [$title, mb_substr(trim((string)($_POST['short_desc'] ?? '')), 0, 500), trim((string)($_POST['description'] ?? '')), $price, $promo,
            $stock, (float)($p['reseller_pct'] ?? 15), (int)($_POST['category_id'] ?? 0) ?: null, trim((string)($_POST['sku'] ?? '')) ?: null,
            in_array($_POST['status'] ?? '', ['active', 'draft'], true) ? $_POST['status'] : 'active', $image,
            json_encode($gallery), json_encode($attrs, JSON_UNESCAPED_UNICODE), $variants ? json_encode($variants, JSON_UNESCAPED_UNICODE) : null];
        $data[6] = max(0, min(70, $data[6]));
        if ($p) { q('UPDATE products SET title=?, short_desc=?, description=?, price=?, promo_price=?, stock=?, reseller_pct=?, category_id=?, sku=?, status=?, image=?, gallery=?, attrs=?, variants=? WHERE id=?', [...$data, $p['id']]); $pid = (int)$p['id']; }
        else { q('INSERT INTO products (title, short_desc, description, price, promo_price, stock, reseller_pct, category_id, sku, status, image, gallery, attrs, variants, vendor_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$data, $vid]); $pid = (int)db()->lastInsertId(); }
        flash($p ? 'Product saved.' : 'Product added.');
        redirect('/product/' . $pid);
    }
}

$vals = $p ?? ['title' => '', 'short_desc' => '', 'description' => '', 'price' => '', 'promo_price' => '', 'stock' => 0, 'reseller_pct' => 15, 'category_id' => 0, 'sku' => '', 'status' => 'active', 'image' => '', 'gallery' => '[]', 'attrs' => '[]', 'variants' => '[]'];
$vals = array_merge($vals, array_intersect_key($_POST, $vals));
$attrs = json_decode($p['attrs'] ?? '[]', true) ?: []; $variants = json_decode($p['variants'] ?? '[]', true) ?: []; $gallery = json_decode($p['gallery'] ?? '[]', true) ?: [];

layout($p ? 'Edit product' : 'Add product', function () use ($p, $vals, $tree, $attrs, $variants, $gallery) { ?>
<div class="card-h"><div><div class="crumbs" style="margin:0"><a href="/cabinet/products">My products</a> /</div><h2 style="margin:0"><?= $p ? 'Edit product' : 'Add product' ?></h2></div></div>
<form method="post" enctype="multipart/form-data" class="dash-grid"><?= csrf_field() ?>
  <section class="card span-8">
    <?= field('Product name', 'title', $vals['title'], 'text', 'required maxlength="255"') ?>
    <?= field('Short description', 'short_desc', $vals['short_desc'], 'text', 'maxlength="500"') ?>
    <label class="fld"><span>Description</span><textarea name="description" rows="6"><?= e($vals['description']) ?></textarea></label>
    <div class="grid2">
      <label class="fld"><span>Category</span><select name="category_id"><option value="">— choose —</option>
        <?php foreach ($tree as $c): ?><optgroup label="<?= e($c['name']) ?>"><option value="<?= $c['id'] ?>" <?= (int)$vals['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (general)</option><?php foreach ($c['children'] as $s): ?><option value="<?= $s['id'] ?>" <?= (int)$vals['category_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></label>
      <?= field('SKU', 'sku', $vals['sku']) ?>
    </div>

    <h3 style="margin-top:8px">Options (variable product)</h3><p class="muted sm">Leave empty for a simple product. With options, stock is counted per option.</p>
    <div data-repeat><div class="ed-rep"><?php foreach ($variants as $v): ?><div class="rep-row"><input name="v_name[]" value="<?= e($v['name']) ?>" placeholder="Option, e.g. Size M"><input name="v_price[]" type="number" step="0.01" value="<?= e($v['price']) ?>" placeholder="Own price (optional)"><input name="v_stock[]" type="number" value="<?= e($v['stock']) ?>" placeholder="Stock"><button type="button" class="icon-btn" data-del><?= ico('x', 16) ?></button></div><?php endforeach; ?></div>
      <template><div class="rep-row"><input name="v_name[]" placeholder="Option, e.g. Size M"><input name="v_price[]" type="number" step="0.01" placeholder="Own price (optional)"><input name="v_stock[]" type="number" placeholder="Stock"><button type="button" class="icon-btn" data-del><?= ico('x', 16) ?></button></div></template>
      <button type="button" class="btn sm" data-add style="margin-top:8px"><?= ico('plus', 14) ?> Add option</button></div>

    <h3 style="margin-top:26px">Specifications</h3><p class="muted sm">Section, field name and value — shown on the product page.</p>
    <div data-repeat><div class="ed-rep"><?php foreach ($attrs as $a): ?><div class="rep-row"><input name="a_section[]" value="<?= e($a['section']) ?>" placeholder="Section"><input name="a_name[]" value="<?= e($a['name']) ?>" placeholder="Field name"><input name="a_value[]" value="<?= e($a['value']) ?>" placeholder="Value"><button type="button" class="icon-btn" data-del><?= ico('x', 16) ?></button></div><?php endforeach; ?></div>
      <template><div class="rep-row"><input name="a_section[]" placeholder="Section"><input name="a_name[]" placeholder="Field name"><input name="a_value[]" placeholder="Value"><button type="button" class="icon-btn" data-del><?= ico('x', 16) ?></button></div></template>
      <button type="button" class="btn sm" data-add style="margin-top:8px"><?= ico('plus', 14) ?> Add field</button></div>
  </section>

  <section class="span-4" style="display:grid;gap:22px;align-content:start">
    <div class="card"><h3>Pricing &amp; stock</h3>
      <?= field('Price, ₽ (shown to customers)', 'price', $vals['price'], 'number', 'step="0.01" min="0" required id="f-price"') ?>
      <?= field('Promo price, ₽ (you receive)', 'promo_price', $vals['promo_price'], 'number', 'step="0.01" min="0" id="f-promo"') ?>
      <div class="notice sm" id="split" data-pct="<?= e(platform_pct()) ?>" style="margin:-4px 0 14px">Customers only see the Price. The difference between Price and Promo price is the margin: it goes to the blogger who sold the product, minus the platform commission (<?= rtrim(rtrim(number_format(platform_pct(), 2), '0'), '.') ?>%). Without a blogger the margin goes to the platform.<div id="split-out" style="margin-top:6px;font-weight:600"></div></div>
      <?= field('Stock, pc (simple product)', 'stock', $vals['stock'], 'number', 'min="0"') ?>
      <label class="fld"><span>Status</span><select name="status"><option value="active" <?= $vals['status'] === 'active' ? 'selected' : '' ?>>Active — visible in catalog</option><option value="draft" <?= $vals['status'] === 'draft' ? 'selected' : '' ?>>Draft — hidden</option></select></label>
    </div>
    <div class="card"><h3>Images</h3>
      <label class="fld"><span>Main image</span><input type="file" name="image" accept="image/*" data-preview="#pv-main"></label><div class="upl" id="pv-main"><?php if ($vals['image']): ?><img src="<?= e($vals['image']) ?>" alt=""><?php endif; ?></div>
      <label class="fld" style="margin-top:14px"><span>Gallery (up to 8)</span><input type="file" name="gallery[]" accept="image/*" multiple data-preview="#pv-gal"></label><div class="upl" id="pv-gal"></div>
      <?php if ($gallery): ?><div class="upl"><?php foreach ($gallery as $g): ?><label style="text-align:center;font-size:11px"><img src="<?= e($g) ?>" alt=""><input type="checkbox" name="remove_g[]" value="<?= e($g) ?>"> remove</label><?php endforeach; ?></div><?php endif; ?>
      <p class="muted sm">JPG, PNG, WebP or GIF, up to 5 MB each.</p></div>
    <button class="btn gold lg block"><?= $p ? 'Save product' : 'Publish product' ?></button>
  </section>
</form>
<?php }, ['cabinet' => true]);
