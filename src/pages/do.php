<?php
// POST-only actions: /do/<action>
if (!is_post()) redirect('/');
csrf_check();
$act = $seg[1] ?? '';
$u = me();
if (!$u) { flash('Please sign in first.', 'warn'); $_SESSION['next'] = parse_url($_SERVER['HTTP_REFERER'] ?? '/', PHP_URL_PATH) ?: '/'; redirect('/login'); }
if ($u['sub'] && (in_array($act, ['wish', 'cart-add', 'set-add', 'cart-set', 'cart-del', 'review'], true) || ($act === 'collect' && !has_perm($u, 'products')))) deny();
$id = (int)($_POST['id'] ?? 0);

function add_to_cart(int $uid, array $p, int $shop, string $variant, int $qty, ?float $override = null): void {
    $variants = json_decode($p['variants'] ?? '[]', true) ?: [];
    $stock = (int)$p['stock'];
    if ($variants) {
        $found = false;
        foreach ($variants as $v) if ($v['name'] === $variant) { $stock = (int)$v['stock']; $found = true; }
        if (!$found) { $variant = (string)($variants[0]['name'] ?? ''); $stock = (int)($variants[0]['stock'] ?? 0); }
    } else $variant = '';
    $have = (int)val('SELECT qty FROM cart_items WHERE user_id=? AND product_id=? AND variant=? AND shop_id=?', [$uid, $p['id'], $variant, $shop]);
    $qty = max(1, min($qty + $have, $stock));
    if ($stock < 1) { flash('“' . $p['title'] . '” is out of stock.', 'warn'); return; }
    q('INSERT INTO cart_items (user_id, product_id, shop_id, variant, qty, price_override) VALUES (?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE qty = VALUES(qty), price_override = VALUES(price_override)', [$uid, $p['id'], $shop, $variant, $qty, $override]);
}

switch ($act) {
    case 'wish':
        if (val('SELECT 1 FROM wishlist WHERE user_id=? AND product_id=?', [$u['id'], $id])) { q('DELETE FROM wishlist WHERE user_id=? AND product_id=?', [$u['id'], $id]); flash('Removed from Watch later.'); }
        else { q('INSERT IGNORE INTO wishlist (user_id, product_id) VALUES (?,?)', [$u['id'], $id]); flash('Saved to Watch later.'); }
        break;

    case 'collect':
        if (!can_resell($u)) { flash('Only reseller accounts can collect products.', 'warn'); break; }
        $p = row('SELECT * FROM products WHERE id = ? AND status = "active"', [$id]);
        if (!$p) break;
        if ((int)$p['vendor_id'] === (int)$u['vid']) { flash('This is your own product.', 'warn'); break; }
        if (val('SELECT 1 FROM collection_items WHERE vendor_id=? AND product_id=?', [$u['vid'], $id])) { q('DELETE FROM collection_items WHERE vendor_id=? AND product_id=?', [$u['vid'], $id]); flash('Removed from your collection.'); }
        else { q('INSERT IGNORE INTO collection_items (vendor_id, product_id) VALUES (?,?)', [$u['vid'], $id]); flash('Added to your collection — find it under Product collection.'); }
        break;

    case 'cart-add':
        $p = row('SELECT * FROM products WHERE id = ? AND status = "active"', [(int)($_POST['product_id'] ?? 0)]);
        if (!$p) break;
        $shop = (int)($_POST['shop'] ?? 0);
        if ($shop && (int)$shop === (int)$p['vendor_id']) $shop = 0;
        if ($shop && !val('SELECT 1 FROM vendors WHERE id = ?', [$shop])) $shop = 0;
        add_to_cart((int)$u['id'], $p, $shop, (string)($_POST['variant'] ?? ''), max(1, (int)($_POST['qty'] ?? 1)));
        flash('Added to cart.');
        redirect('/cart');

    case 'set-add':
        $set = row('SELECT * FROM product_sets WHERE id = ?', [$id]);
        if (!$set) break;
        $items = rows('SELECT p.*, si.qty AS set_qty FROM set_items si JOIN products p ON p.id = si.product_id WHERE si.set_id = ? AND p.status = "active"', [$id]);
        if (!$items) break;
        foreach ($items as $p) add_to_cart((int)$u['id'], $p, (int)$set['vendor_id'] === (int)$p['vendor_id'] ? 0 : (int)$set['vendor_id'], '', (int)$p['set_qty']);
        flash('Set added to cart.');
        redirect('/cart');

    case 'cart-set':
        $qty = (int)($_POST['qty'] ?? 1);
        if ($qty < 1) q('DELETE FROM cart_items WHERE id = ? AND user_id = ?', [$id, $u['id']]);
        else q('UPDATE cart_items SET qty = ? WHERE id = ? AND user_id = ?', [min($qty, 999), $id, $u['id']]);
        redirect('/cart');

    case 'cart-del':
        q('DELETE FROM cart_items WHERE id = ? AND user_id = ?', [$id, $u['id']]);
        redirect('/cart');

    case 'review':
        $pid = (int)($_POST['product_id'] ?? 0);
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        if (val('SELECT 1 FROM products WHERE id = ?', [$pid])) {
            q('INSERT INTO reviews (product_id, user_id, rating, body) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), body = VALUES(body)', [$pid, $u['id'], $rating, mb_substr(trim((string)($_POST['body'] ?? '')), 0, 1000)]);
            flash('Thanks for your review!');
        }
        redirect('/product/' . $pid);
}
back('/');
