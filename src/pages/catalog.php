<?php
$per = 24;
$pg = max(1, (int)($_GET['page'] ?? 1));
$cat = (int)($_GET['cat'] ?? 0);
$qs = trim((string)($_GET['q'] ?? ''));
$sort = $_GET['sort'] ?? 'new';
$min = $_GET['min'] ?? ''; $max = $_GET['max'] ?? '';

$where = ["p.status='active'"]; $args = [];
if ($cat) { $where[] = '(p.category_id = ? OR p.category_id IN (SELECT id FROM categories WHERE parent_id = ?))'; array_push($args, $cat, $cat); }
if ($qs !== '') { $where[] = '(p.title LIKE ? OR p.short_desc LIKE ? OR p.sku LIKE ?)'; array_push($args, "%$qs%", "%$qs%", "%$qs%"); }
if ($min !== '') { $where[] = 'COALESCE(NULLIF(p.promo_price,0),p.price) >= ?'; $args[] = (float)$min; }
if ($max !== '') { $where[] = 'COALESCE(NULLIF(p.promo_price,0),p.price) <= ?'; $args[] = (float)$max; }
$w = implode(' AND ', $where);
$order = ['new' => 'p.id DESC', 'cheap' => 'COALESCE(NULLIF(p.promo_price,0),p.price) ASC', 'expensive' => 'COALESCE(NULLIF(p.promo_price,0),p.price) DESC', 'popular' => 'p.views DESC'][$sort] ?? 'p.id DESC';

$total = (int)val("SELECT COUNT(*) FROM products p WHERE $w", $args);
$items = rows("SELECT p.*, c.name cat_name FROM products p LEFT JOIN categories c ON c.id=p.category_id WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($pg - 1) * $per), $args);
$tree = categories_tree();
$keep = array_filter(['q' => $qs, 'sort' => $sort, 'min' => $min, 'max' => $max]);

layout('Products', function () use ($items, $total, $tree, $cat, $qs, $sort, $min, $max, $pg, $per, $keep) { ?>
<div class="wrap">
  <div class="page-h"><div class="crumbs"><a href="/">Home</a> / Products</div><h1>Products</h1></div>
  <div class="catalog">
    <aside class="filters">
      <form class="card" method="get">
        <h4>Search</h4><input name="q" value="<?= e($qs) ?>" placeholder="Name or SKU" style="margin-bottom:14px">
        <h4>Price, ₽</h4><div class="row" style="margin-bottom:14px"><input name="min" type="number" min="0" placeholder="from" value="<?= e($min) ?>"><input name="max" type="number" min="0" placeholder="to" value="<?= e($max) ?>"></div>
        <?php if ($cat): ?><input type="hidden" name="cat" value="<?= $cat ?>"><?php endif; ?>
        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <button class="btn dark block">Apply</button>
      </form>
      <div class="card ftree"><h4>Categories</h4>
        <a href="/products" class="<?= !$cat ? 'on' : '' ?>">All</a>
        <?php foreach ($tree as $c): $open = $cat === (int)$c['id'] || in_array($cat, array_column($c['children'], 'id')); ?>
          <a href="/products?cat=<?= $c['id'] ?>" class="<?= $cat === (int)$c['id'] ? 'on' : '' ?>"><?= e($c['name']) ?></a>
          <?php if ($open) foreach ($c['children'] as $s): ?><a class="sub <?= $cat === (int)$s['id'] ? 'on' : '' ?>" href="/products?cat=<?= $s['id'] ?>"><?= e($s['name']) ?></a><?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </aside>
    <section>
      <div class="toolbar">
        <span class="muted"><?= num($total) ?> product<?= $total === 1 ? '' : 's' ?></span>
        <form method="get"><?php foreach (['q' => $qs, 'cat' => $cat ?: '', 'min' => $min, 'max' => $max] as $k => $v) if ($v !== '') echo '<input type="hidden" name="' . $k . '" value="' . e($v) . '">'; ?>
          <select name="sort" onchange="this.form.submit()" aria-label="Sort"><?php foreach (['new' => 'Newest', 'popular' => 'Most viewed', 'cheap' => 'Price: low to high', 'expensive' => 'Price: high to low'] as $k => $l): ?><option value="<?= $k ?>" <?= $sort === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
        </form>
      </div>
      <?php if ($items): ?><div class="pgrid"><?php foreach ($items as $p) echo product_card($p); ?></div>
        <?= pager($total, $per, $pg, $keep + ($cat ? ['cat' => $cat] : [])) ?>
      <?php else: ?><div class="card empty"><h2>Nothing found</h2><p>Try a different category or search term.</p><a class="btn" href="/products">Reset filters</a></div><?php endif; ?>
    </section>
  </div>
</div>
<?php });
