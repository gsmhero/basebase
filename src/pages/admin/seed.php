<?php
// Demo data loader / remover (admin only, POST).
$u = require_role('admin');
if (!is_post()) redirect('/admin/users');
csrf_check();
set_time_limit(60);

$demoUsers = array_column(rows("SELECT id FROM users WHERE email LIKE '%@demo.basebase'"), 'id');

if (($_POST['do'] ?? '') === 'clear') {
    if ($demoUsers) {
        $in = in_clause($demoUsers);
        $vids = array_column(rows("SELECT id FROM vendors WHERE user_id IN ($in)", $demoUsers), 'id');
        $oids = array_column(rows("SELECT id FROM orders WHERE customer_id IN ($in)", $demoUsers), 'id');
        if ($vids) {
            $vin = in_clause($vids);
            q("DELETE FROM order_items WHERE vendor_id IN ($vin) OR reseller_id IN ($vin)", [...$vids, ...$vids]);
            q("DELETE FROM products WHERE vendor_id IN ($vin)", $vids);
        }
        if ($oids) q('DELETE FROM order_items WHERE order_id IN (' . in_clause($oids) . ')', $oids);
        if ($oids) q('DELETE FROM orders WHERE id IN (' . in_clause($oids) . ')', $oids);
        if ($vids) q("DELETE FROM vendors WHERE id IN ($vin)", $vids);
        q("DELETE FROM users WHERE id IN ($in)", $demoUsers);
    }
    flash('Demo data removed.');
    redirect('/admin/users');
}
if ($demoUsers) { flash('Demo data is already loaded.', 'warn'); redirect('/admin/users'); }

$pass = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);
function mkuser(string $email, string $name, string $role, string $shop, string $pass, array $extra = []): int {
    q('INSERT INTO users (email, name, password_hash, role, last_seen) VALUES (?,?,?,?,NOW())', [$email, $name, $pass, $role]);
    $uid = (int)db()->lastInsertId();
    $vid = make_vendor($uid, $shop);
    if ($extra) q('UPDATE vendors SET bio = ?, blogging_type = ?, socials = ? WHERE id = ?', [$extra[0], $extra[1], json_encode($extra[2], JSON_UNESCAPED_UNICODE), $vid]);
    return $vid;
}
$sup = [
    mkuser('paris@demo.basebase', 'Paris Trade', 'supplier', 'Paris Trade', $pass, ['Official importer of French cosmetics and skin care.', 'Supplier', []]),
    mkuser('sellmotor@demo.basebase', 'SellMotor', 'supplier', 'SellMotor', $pass, ['Consumer electronics and smart gadgets, shipped within 24 hours.', 'Supplier', []]),
    mkuser('casa@demo.basebase', 'Casa Nova', 'supplier', 'Casa Nova', $pass, ['Home textile, kitchen and decor from small European studios.', 'Supplier', []]),
];
$bl = [
    mkuser('anna@demo.basebase', 'Anna Volkova', 'vendor', 'Anna Beauty', $pass, ['Daily skin care routines and honest reviews.', 'Beauty blogger', ['VK' => ['url' => 'https://vk.com/', 'followers' => 48200], 'YouTube' => ['url' => 'https://youtube.com/', 'followers' => 15300], 'Dzen' => ['url' => '', 'followers' => 9100]]]),
    mkuser('max@demo.basebase', 'Max Orlov', 'vendor', 'Tech with Max', $pass, ['Gadgets I actually use. Tested, compared, recommended.', 'Tech reviewer', ['YouTube' => ['url' => 'https://youtube.com/', 'followers' => 92000], 'VK Video' => ['url' => '', 'followers' => 12500]]]),
    mkuser('lena@demo.basebase', 'Elena Smirnova', 'vendor', 'Cozy Home by Lena', $pass, ['Making small flats feel like home.', 'Lifestyle & interior', ['VK' => ['url' => 'https://vk.com/', 'followers' => 21400], 'OK' => ['url' => '', 'followers' => 6800]]]),
];
q("INSERT INTO users (email, name, password_hash, role) VALUES ('buyer@demo.basebase','Demo Customer',?, 'customer')", [$pass]);
$buyer = (int)db()->lastInsertId();

// [title, short, category, price, promo, stock, reseller %, supplier idx, sku, options]
$cat = [
 ['Hydrating Face Serum 30 ml', 'Hyaluronic acid and vitamin B5 for all-day moisture.', 11, 3890, 3000, 500, 18, 0, 'PT-SRM-30', null],
 ['Velvet Matte Lipstick', 'Long-wear matte finish, 12 shades.', 12, 1290, null, 340, 20, 0, 'PT-LIP-12', [['Rose', 0, 120], ['Berry', 0, 90], ['Nude', 0, 130]]],
 ['Repair Hair Mask 250 ml', 'Deep repair for dry and coloured hair.', 13, 1650, 1390, 210, 15, 0, 'PT-HRM-250', null],
 ['Night Recovery Cream', 'Rich night cream with peptides and ceramides.', 11, 4270, 3350, 5, 16, 0, 'PT-NCR-50', null],
 ['Vitamin C Brightening Toner', 'Evens tone and adds glow in two weeks.', 11, 1180, null, 280, 18, 0, 'PT-TON-C', null],
 ['Wireless Earbuds Pro', 'ANC, 30 h battery, IPX5 water resistance.', 21, 7990, 6490, 150, 12, 1, 'SM-EAR-PRO', [['Black', 0, 80], ['White', 0, 70]]],
 ['Smart Watch Fit 2', 'AMOLED display, heart rate, 14-day battery.', 22, 9900, 8400, 90, 10, 1, 'SM-WCH-F2', null],
 ['Portable Bluetooth Speaker', 'Waterproof 20 W speaker with 18 h playtime.', 21, 3490, null, 220, 14, 1, 'SM-SPK-20', null],
 ['USB-C Fast Charger 65 W', 'GaN charger for laptop, phone and tablet.', 23, 2190, 1790, 600, 12, 1, 'SM-CHG-65', null],
 ['Smart LED Desk Lamp', 'App-controlled, 5 colour temperatures.', 22, 2890, null, 75, 13, 1, 'SM-LMP-D1', null],
 ['Linen Duvet Cover Set', 'Washed linen, Euro size, 4 pieces.', 33, 6900, 5900, 60, 17, 2, 'CN-LIN-EU', [['Sand', 0, 20], ['Olive', 0, 25], ['Grey', 0, 15]]],
 ['Ceramic Mug Set (4 pcs)', 'Hand-glazed stoneware, dishwasher safe.', 31, 2450, null, 140, 16, 2, 'CN-MUG-4', null],
 ['Scented Soy Candle', 'Cedar & amber, 45 h burning time.', 32, 990, 790, 400, 20, 2, 'CN-CND-CA', null],
 ['Cast Iron Skillet 26 cm', 'Pre-seasoned, works on induction.', 31, 3890, null, 55, 14, 2, 'CN-SKL-26', null],
 ['Woven Storage Basket', 'Seagrass basket with handles, set of 2.', 32, 1890, 1490, 110, 18, 2, 'CN-BSK-2', null],
 ['Wool Throw Blanket', 'Soft merino blend, 130×180 cm.', 33, 4590, null, 45, 15, 2, 'CN-THR-W', null],
 ['Hand Cream Trio', 'Three travel-size creams in a gift box.', 11, 890, null, 450, 22, 0, 'PT-HCR-3', null],
 ['Mini Projector HD', 'Portable 1080p projector with built-in speaker.', 22, 12900, 10900, 30, 9, 1, 'SM-PRJ-HD', null],
];
$pid = [];
$attrsFor = fn($c) => in_array($c, [11, 12, 13], true) ? [['section' => 'Details', 'name' => 'Country', 'value' => 'France'], ['section' => 'Details', 'name' => 'Skin type', 'value' => 'All types'], ['section' => 'Usage', 'name' => 'Application', 'value' => 'Morning and evening']] : [['section' => 'Specifications', 'name' => 'Warranty', 'value' => '12 months'], ['section' => 'Specifications', 'name' => 'Origin', 'value' => 'Europe'], ['section' => 'Delivery', 'name' => 'Dispatch', 'value' => 'Within 1–2 days']];
foreach ($cat as $i => [$t, $short, $c, $price, $promo, $stock, $pct, $si, $sku, $opts]) {
    $variants = $opts ? array_map(fn($o) => ['name' => $o[0], 'price' => $o[1], 'stock' => $o[2]], $opts) : null;
    if ($variants) $stock = array_sum(array_column($variants, 'stock'));
    q("INSERT INTO products (vendor_id, title, short_desc, description, price, promo_price, stock, reseller_pct, category_id, sku, attrs, variants, is_dropship, views) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?)",
      [$sup[$si], $t, $short, $short . "\n\nCarefully selected by our team and shipped directly from the supplier's warehouse. Questions? Use “Ask seller”.", $price, $promo, $stock, $pct, $c, $sku, json_encode($attrsFor($c), JSON_UNESCAPED_UNICODE), $variants ? json_encode($variants, JSON_UNESCAPED_UNICODE) : null, random_int(40, 2600)]);
    $pid[$i] = (int)db()->lastInsertId();
}
// collections: bloggers collect matching items
$coll = [[0, [0, 1, 2, 3, 4, 16]], [1, [5, 6, 7, 8, 9, 17]], [2, [10, 11, 12, 13, 14, 15]]];
foreach ($coll as [$b, $items]) foreach ($items as $k => $ix) q('INSERT IGNORE INTO collection_items (vendor_id, product_id, grp) VALUES (?,?,?)', [$bl[$b], $pid[$ix], $k < 3 ? 'Favourites' : 'Gift ideas']);
q('INSERT INTO product_sets (vendor_id, title, price) VALUES (?,?,?)', [$bl[0], 'Glow routine set', 6990]); $sid = (int)db()->lastInsertId();
foreach ([0, 2, 4] as $ix) q('INSERT INTO set_items (set_id, product_id, qty) VALUES (?,?,1)', [$sid, $pid[$ix]]);
// reviews
$names = ['Olga', 'Dmitry', 'Irina', 'Sergey', 'Maria'];
foreach ($pid as $i => $id) for ($k = 0; $k < 2; $k++) {
    q("INSERT INTO users (email, name, password_hash, role) VALUES (?,?,?, 'customer')", ["r$i$k@demo.basebase", $names[($i + $k) % 5], $pass]);
    q('INSERT INTO reviews (product_id, user_id, rating, body) VALUES (?,?,?,?)', [$id, (int)db()->lastInsertId(), random_int(4, 5) - ($k && $i % 4 === 0 ? 1 : 0), ['Great quality, arrived fast.', 'Exactly as described — recommend!', 'Very happy with this purchase.', 'Good value for the price.'][($i + $k) % 4]]);
}
// orders over the last 60 days
$statuses = ['delivered', 'delivered', 'delivered', 'shipped', 'prepare', 'pending'];
for ($n = 0; $n < 26; $n++) {
    $day = date('Y-m-d H:i:s', strtotime('-' . random_int(0, 58) . ' days -' . random_int(0, 20) . ' hours'));
    $ix = random_int(0, count($cat) - 1); $p = $cat[$ix]; $qty = random_int(1, 3);
    $unit = (float)($p[4] ?: $p[3]); $b = random_int(0, 2); $shopBl = $coll[$b][1];
    $reseller = in_array($ix, $shopBl, true) ? $bl[$b] : null;
    $sum = $unit * $qty; $fee = round($sum * 0.10, 2); $rf = $reseller ? round($sum * $p[6] / 100, 2) : 0;
    $st = $statuses[array_rand($statuses)];
    q("INSERT INTO orders (customer_id, total, status, name, phone, address, delivery_method, payment_status, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)", [$buyer, $sum, $st === 'delivered' ? 'done' : 'shipped', 'Demo Customer', '+7 900 000-00-00', 'Moscow, Demo street 1', ['CDEK', 'OZON Shipping'][$n % 2], $st === 'delivered' ? 'paid' : 'unpaid', $n % 5 ? '' : 'Please add box', $day]);
    $oid = (int)db()->lastInsertId();
    q('INSERT INTO order_items (order_id, product_id, vendor_id, qty, price, commission, fulfillment, item_status, reseller_id, reseller_fee, title) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$oid, $pid[$ix], $sup[$p[7]], $qty, $unit, $fee, 'dropship', $st, $reseller, $rf, $p[0]]);
    $iid = (int)db()->lastInsertId();
    if ($st === 'delivered') {
        $su = (int)val('SELECT user_id FROM vendors WHERE id = ?', [$sup[$p[7]]]);
        q("INSERT IGNORE INTO wallet_tx (user_id, amount, type, ref, note, created_at) VALUES (?,?,?,?,?,?)", [$su, $sum - $fee - $rf, 'sale', "oi$iid:s", 'Sale: ' . $p[0], $day]);
        if ($reseller) q("INSERT IGNORE INTO wallet_tx (user_id, amount, type, ref, note, created_at) VALUES (?,?,?,?,?,?)", [(int)val('SELECT user_id FROM vendors WHERE id = ?', [$reseller]), $rf, 'commission', "oi$iid:r", 'Commission: ' . $p[0], $day]);
    }
}
flash('Demo data loaded: 3 suppliers, 3 bloggers, ' . count($cat) . ' products, orders and reviews.');
redirect('/admin/users');
