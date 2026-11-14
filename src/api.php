<?php
declare(strict_types=1);
/*
 * Partner API v1 — see docs/BASEBASE-SYSFERA-API-v1.md
 * Every request is signed: X-BB-Agent, X-BB-Timestamp, X-BB-Signature = HMAC-SHA256(secret, ts "\n" METHOD "\n" URI "\n" body)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header_remove('X-Powered-By');

function api_out($data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}
function api_err(string $code, string $msg, int $http = 400): never { api_out(['error' => $code, 'message' => $msg], $http); }

set_exception_handler(function (Throwable $e) {
    @file_put_contents(ROOT . '/storage/error.log', date('c') . ' API ' . $e . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => APP_DEBUG ? $e->getMessage() : 'Internal error.']);
});

/* ---------- authentication ---------- */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 2 * 1024 * 1024) api_err('too_large', 'Request body is limited to 2 MB.', 413);
$aid = (int)($_SERVER['HTTP_X_BB_AGENT'] ?? 0);
$ts = (int)($_SERVER['HTTP_X_BB_TIMESTAMP'] ?? 0);
$sig = (string)($_SERVER['HTTP_X_BB_SIGNATURE'] ?? '');
$ag = $aid ? row("SELECT * FROM agents WHERE id = ? AND status = 'active' AND api_secret IS NOT NULL", [$aid]) : null;
if (!$ag || abs(time() - $ts) > 300 || !hash_equals(bb_sign($ag['api_secret'], $ts, $method, (string)($_SERVER['REQUEST_URI'] ?? ''), $raw), $sig)) {
    api_err('unauthorized', 'Invalid agent, expired timestamp or wrong signature.', 401);
}
$in = [];
if ($raw !== '') { $in = json_decode($raw, true); if (!is_array($in)) api_err('bad_json', 'The body must be a JSON object.'); }

if (function_exists('fastcgi_finish_request')) register_shutdown_function(function () { @fastcgi_finish_request(); webhooks_flush(5); });

/* ---------- helpers ---------- */
function api_seller(array $ag, $id): array {
    $s = row('SELECT u.id, u.email, u.name, u.phone, v.id AS vid, v.shop_name, v.slug FROM users u JOIN vendors v ON v.user_id = u.id WHERE u.id = ? AND u.agent_id = ?', [(int)$id, $ag['id']]);
    if (!$s) api_err('not_found', 'Seller not found for this agent.', 404);
    return $s;
}
function api_str($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }

/** Downloads a remote image (https only, no private addresses, ≤5 MB) into /uploads. */
function api_fetch_image(string $url): ?string {
    $bytes = null;
    for ($hop = 0; $hop < 4 && $bytes === null; $hop++) {   // follow up to 3 redirects, checking every host
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return null;
        $ip = gethostbyname($p['host']);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
        $ua = 'Mozilla/5.0 (compatible; BasebaseImporter/1.0; +https://basebase.ru)';   // many CDNs (Ozon, WB…) refuse an unknown or empty agent
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => $ua,
                CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/*,*/*;q=0.8'], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 5 * 1024 * 1024 + 1, CURLOPT_HEADER => false,
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc) { if (stripos($h, 'location:') === 0) $loc = trim(substr($h, 9)); return strlen($h); }]);
            $loc = null; $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
            if ($code >= 300 && $code < 400 && $loc) { $url = preg_match('~^https?://~i', $loc) ? $loc : ($p['scheme'] . '://' . $p['host'] . '/' . ltrim($loc, '/')); continue; }
            if ($code !== 200 || !is_string($body)) return null;
            $bytes = $body;
        } else {
            $fp = @fopen($url, 'rb', false, stream_context_create(['http' => ['timeout' => 12, 'follow_location' => 1, 'max_redirects' => 3, 'user_agent' => $ua, 'header' => "Accept: image/*,*/*;q=0.8\r\n"]]));
            if (!$fp) return null;
            $bytes = stream_get_contents($fp, 5 * 1024 * 1024 + 1); fclose($fp);
            if ($bytes === false) return null;
        }
    }
    if ($bytes === null || $bytes === '' || strlen($bytes) > 5 * 1024 * 1024) return null;
    $info = @getimagesizefromstring($bytes);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime'] ?? ''] ?? null;
    if (!$ext) return null;
    $dir = ROOT . '/public/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    return file_put_contents("$dir/$name", $bytes) !== false ? "/uploads/$name" : null;
}

function api_variants($v): array {
    $out = [];
    foreach ((array)$v as $x) {
        if (!is_array($x)) continue;
        $n = api_str($x['name'] ?? '', 120);
        if ($n !== '') $out[] = ['name' => $n, 'price' => max(0.0, (float)($x['price'] ?? 0)), 'stock' => max(0, (int)($x['stock'] ?? 0))];
    }
    return $out;
}
function api_product_json(array $p): array {
    return ['product_id' => (int)$p['id'], 'external_id' => $p['external_id'], 'status' => $p['status'], 'url' => site_url() . '/product/' . $p['id']];
}

/* ---------- routing ---------- */
$res = $seg[2] ?? '';
$sid = $seg[3] ?? null; $sub = $seg[4] ?? ''; $sub2 = $seg[5] ?? '';
if (($seg[1] ?? '') !== 'v1') api_err('not_found', 'Unknown API version. Use /api/v1.', 404);

if ($res === 'ping' && $method === 'GET') api_out(['ok' => true, 'agent_id' => (int)$ag['id'], 'agency_fee' => (float)$ag['agency_fee'], 'platform_commission' => platform_pct(), 'time' => time()]);

if ($res === 'agent' && $sid === 'wallet' && $method === 'GET') {   // the Agent's own balance (Agency fee credited by sales of its sellers), read-only
    $uid = (int)$ag['user_id'];
    $tx = rows('SELECT id, amount, type, status, note, created_at FROM wallet_tx WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$uid]);
    api_out(['balance' => wallet_balance($uid), 'transactions' => array_map(fn($t) => ['id' => (int)$t['id'], 'amount' => (float)$t['amount'], 'type' => $t['type'], 'status' => $t['status'], 'note' => $t['note'], 'created_at' => date('c', strtotime($t['created_at']))], $tx)]);
}
if ($res === 'categories' && $method === 'GET') {
    api_out(['categories' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'children' => array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name']], $c['children'])], categories_tree())]);
}

if ($res !== 'sellers') api_err('not_found', 'Unknown endpoint.', 404);

/* POST /sellers — create a managed seller account */
if ($sid === null) {
    if ($method !== 'POST') api_err('method_not_allowed', 'Use POST.', 405);
    $ext = api_str($in['external_id'] ?? '', 80); $email = mb_strtolower(api_str($in['email'] ?? '', 190)); $name = api_str($in['name'] ?? '', 120);
    if ($ext === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) api_err('validation', 'external_id, name and a valid email are required.', 422);
    $ex = row('SELECT id FROM users WHERE agent_id = ? AND ext_id = ?', [$ag['id'], $ext]);
    if ($ex) { $s = api_seller($ag, $ex['id']); api_out(['seller_id' => (int)$s['id'], 'shop_slug' => $s['slug'], 'created' => false]); }
    if (val('SELECT 1 FROM users WHERE email = ?', [$email])) api_err('email_taken', 'A Basebase account with this email already exists.', 409);
    if ($email === mb_strtolower(env('ADMIN_EMAIL', 'admin@basebase.ru'))) api_err('email_taken', 'This email cannot be used.', 409);
    q("INSERT INTO users (email, name, phone, password_hash, role, is_guest, is_managed, agent_id, ext_id) VALUES (?,?,?,?, 'supplier', 1, 1, ?, ?)",
      [$email, $name, api_str($in['phone'] ?? '', 40) ?: null, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $ag['id'], $ext]);
    $uid = (int)db()->lastInsertId();
    make_vendor($uid, api_str($in['shop_name'] ?? '', 190) ?: $name);
    mail_managed_seller($uid, (string)$ag['domain']);
    $s = api_seller($ag, $uid);
    api_out(['seller_id' => $uid, 'shop_slug' => $s['slug'], 'created' => true], 201);
}

$s = api_seller($ag, $sid);
$vid = (int)$s['vid'];

/* GET /sellers/{id} */
if ($sub === '' && $method === 'GET') api_out(['seller_id' => (int)$s['id'], 'email' => $s['email'], 'name' => $s['name'], 'shop_name' => $s['shop_name'], 'shop_url' => site_url() . '/shop/' . $s['slug']]);

/* ----- products ----- */
if ($sub === 'products' && $sub2 !== '') {
    $ext = (string)$sub2;
    if (!preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $ext)) api_err('validation', 'external_id may contain letters, digits and . _ : - (max 80).', 422);
    $p = row('SELECT * FROM products WHERE vendor_id = ? AND external_id = ?', [$vid, $ext]);
    $act = $seg[6] ?? '';

    if ($method === 'PUT' && $act === '') {
        $title = api_str($in['title'] ?? '', 255); $price = (float)($in['price'] ?? 0);
        if ($title === '' || $price <= 0) api_err('validation', 'title and a price greater than zero are required.', 422);
        $promo = (float)($in['promo_price'] ?? 0); $promo = ($promo > 0 && $promo < $price) ? $promo : null;
        $variants = api_variants($in['variants'] ?? []);
        $stock = $variants ? array_sum(array_column($variants, 'stock')) : max(0, (int)($in['stock'] ?? 0));
        $cat = null;
        if (!empty($in['category_id']) && val('SELECT 1 FROM categories WHERE id = ?', [(int)$in['category_id']])) $cat = (int)$in['category_id'];
        elseif (!empty($in['category'])) $cat = (int)val('SELECT id FROM categories WHERE LOWER(name) = LOWER(?) ORDER BY parent_id DESC LIMIT 1', [api_str($in['category'], 120)]) ?: null;
        $attrs = [];
        $fields = $in['fields'] ?? [];
        if (is_array($fields)) foreach ($fields as $k => $f) {
            if (is_array($f)) { $n = api_str($f['name'] ?? '', 120); $val = api_str($f['value'] ?? '', 500); $sec = api_str($f['section'] ?? '', 120); }
            else { $n = api_str($k, 120); $val = api_str($f, 500); $sec = ''; }
            if ($n !== '' && $val !== '') $attrs[] = ['section' => $sec, 'name' => $n, 'value' => $val];
        }
        $status = ($in['status'] ?? 'active') === 'draft' ? 'draft' : 'active';
        $desc = trim((string)($in['description'] ?? '')); $desc = mb_substr($desc, 0, 20000);
        $short = api_str($in['short_description'] ?? '', 500) ?: mb_substr(trim(preg_replace('/\s+/', ' ', $desc)), 0, 200);
        $image = $p['image'] ?? null; $gallery = json_decode($p['gallery'] ?? '[]', true) ?: []; $hash = $p['ext_img_hash'] ?? null;
        $urls = array_slice(array_values(array_filter(array_map('strval', (array)($in['images'] ?? [])), fn($u) => $u !== '')), 0, 8);
        if ($urls) { @ignore_user_abort(true); @set_time_limit(120); }   // downloading photos can take longer than the caller waits
        if ($urls && md5(implode('|', $urls)) !== $hash) {
            $saved = array_values(array_filter(array_map('api_fetch_image', $urls)));
            if ($saved) { $image = $saved[0]; $gallery = array_slice($saved, 1); $hash = md5(implode('|', $urls)); }
        }
        $data = [$title, $short, $desc, $price, $promo, $stock, $cat, api_str($in['sku'] ?? '', 80) ?: null, $status, $image, json_encode($gallery), json_encode($attrs, JSON_UNESCAPED_UNICODE), $variants ? json_encode($variants, JSON_UNESCAPED_UNICODE) : null, $hash];
        if ($p) { q('UPDATE products SET title=?, short_desc=?, description=?, price=?, promo_price=?, stock=?, category_id=?, sku=?, status=?, image=?, gallery=?, attrs=?, variants=?, ext_img_hash=? WHERE id=?', [...$data, $p['id']]); $pid = (int)$p['id']; $created = false; }
        else { q('INSERT INTO products (title, short_desc, description, price, promo_price, stock, category_id, sku, status, image, gallery, attrs, variants, ext_img_hash, vendor_id, external_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$data, $vid, $ext]); $pid = (int)db()->lastInsertId(); $created = true; }
        api_out(api_product_json(row('SELECT * FROM products WHERE id = ?', [$pid])) + ['created' => $created, 'images_requested' => count($urls), 'images_saved' => $image ? 1 + count($gallery) : 0], $created ? 201 : 200);
    }
    if (!$p) api_err('not_found', 'Product not found.', 404);

    if ($method === 'DELETE' && $act === '') { q("UPDATE products SET status = 'draft' WHERE id = ?", [$p['id']]); api_out(api_product_json(row('SELECT * FROM products WHERE id = ?', [$p['id']]))); }

    if ($method === 'PATCH' && $act === 'stock') {
        if (isset($in['variants'])) {
            $cur = json_decode($p['variants'] ?? '[]', true) ?: []; $by = array_column($in['variants'], null, 'name');
            foreach ($cur as &$v) if (isset($by[$v['name']])) { $v['stock'] = max(0, (int)($by[$v['name']]['stock'] ?? $v['stock'])); if (isset($by[$v['name']]['price']) && (float)$by[$v['name']]['price'] > 0) $v['price'] = (float)$by[$v['name']]['price']; }
            unset($v);
            q('UPDATE products SET variants = ?, stock = ? WHERE id = ?', [json_encode($cur, JSON_UNESCAPED_UNICODE), array_sum(array_column($cur, 'stock')), $p['id']]);
        } elseif (isset($in['stock'])) q('UPDATE products SET stock = ? WHERE id = ?', [max(0, (int)$in['stock']), $p['id']]);
        else api_err('validation', 'Send stock or variants.', 422);
        $p = row('SELECT * FROM products WHERE id = ?', [$p['id']]);
        api_out(['product_id' => (int)$p['id'], 'stock' => (int)$p['stock'], 'variants' => json_decode($p['variants'] ?? '[]', true) ?: []]);
    }
    api_err('method_not_allowed', 'Unsupported method for this endpoint.', 405);
}

/* ----- orders ----- */
if ($sub === 'orders') {
    if ($method === 'GET' && $sub2 === '') {
        $w = ['oi.vendor_id = ?']; $a = [$vid];
        if (isset($_GET['since_id'])) { $w[] = 'oi.id > ?'; $a[] = (int)$_GET['since_id']; }
        if (!empty($_GET['status']) && isset(ITEM_STATUSES[$_GET['status']])) { $w[] = 'oi.item_status = ?'; $a[] = $_GET['status']; }
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
        $items = rows('SELECT oi.*, o.created_at, o.name cname, o.phone, o.address, o.delivery_method, o.notes, o.payment_status, p.external_id FROM order_items oi JOIN orders o ON o.id = oi.order_id LEFT JOIN products p ON p.id = oi.product_id WHERE ' . implode(' AND ', $w) . " ORDER BY oi.id ASC LIMIT $limit", $a);
        api_out(['items' => array_map(fn($i) => [
            'order_id' => (int)$i['order_id'], 'item_id' => (int)$i['id'], 'status' => $i['item_status'], 'payment_status' => $i['payment_status'], 'created_at' => date('c', strtotime($i['created_at'])),
            'customer' => ['name' => $i['cname'], 'phone' => $i['phone'], 'address' => $i['address'], 'delivery' => $i['delivery_method'], 'notes' => $i['notes']],
            'product' => ['external_id' => $i['external_id'], 'title' => $i['title'], 'variant' => $i['variant']],
            'qty' => (int)$i['qty'], 'price' => (float)$i['price'], 'seller_receives' => round($i['price'] * $i['qty'] - $i['commission'] - $i['reseller_fee'], 2),
        ], $items)]);
    }
    if ($method === 'PATCH' && ctype_digit((string)$sub2)) {
        $it = row('SELECT * FROM order_items WHERE id = ? AND vendor_id = ?', [(int)$sub2, $vid]);
        if (!$it) api_err('not_found', 'Order item not found.', 404);
        $new = (string)($in['status'] ?? '');
        if (!isset(ITEM_STATUSES[$new])) api_err('validation', 'status must be one of: ' . implode(', ', array_keys(ITEM_STATUSES)), 422);
        apply_item_status($it, $new);
        api_out(['item_id' => (int)$it['id'], 'status' => $new]);
    }
    api_err('method_not_allowed', 'Unsupported method for this endpoint.', 405);
}

/* ----- wallet ----- */
if ($sub === 'wallet') {
    if ($method === 'GET' && $sub2 === '') {
        $tx = rows('SELECT id, amount, type, status, note, created_at FROM wallet_tx WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$s['id']]);
        api_out(['balance' => wallet_balance((int)$s['id']), 'transactions' => array_map(fn($t) => ['id' => (int)$t['id'], 'amount' => (float)$t['amount'], 'type' => $t['type'], 'status' => $t['status'], 'note' => $t['note'], 'created_at' => date('c', strtotime($t['created_at']))], $tx)]);
    }
    if ($method === 'POST' && $sub2 === 'withdraw') {
        $amt = round((float)($in['amount'] ?? 0), 2);
        [$ft, $msg, $code] = withdraw_money((int)$s['id'], row('SELECT * FROM vendors WHERE id = ?', [$vid]), $amt);
        $http = $code === 'sent' ? 200 : ($code === 'review' ? 202 : 422);
        api_out(['status' => $code, 'message' => $msg, 'balance' => wallet_balance((int)$s['id'])], $http);
    }
    api_err('method_not_allowed', 'Unsupported method for this endpoint.', 405);
}

/* ----- payout profile ----- */
if ($sub === 'profile' && $method === 'GET') {   // field definitions + the values saved so far, so an agent site can show and edit the same form
    $types = require ROOT . '/src/profile_fields.php';
    $vrow = row('SELECT * FROM vendors WHERE id = ?', [$vid]);
    $personal = json_decode($vrow['personal'] ?? '{}', true) ?: [];
    $t = isset($types[$vrow['payout_type'] ?? '']) ? (string)$vrow['payout_type'] : '';
    $defs = []; $vals = [];
    foreach ($types as $k => $ty) {
        $defs[$k] = ['label' => $ty['label'], 'section' => $ty['section'], 'fields' => array_map(fn($f) => ['id' => (int)$f['id'], 'label' => trim((string)$f['label']), 'type' => $f['type'], 'required' => (bool)$f['required'], 'options' => $f['options'] ?? null], $ty['fields'])];
        foreach ($ty['fields'] as $f) $vals[$k][(string)$f['id']] = (string)(($personal[$k] ?? [])['f' . $f['id']] ?? '');
    }
    api_out(['payout_type' => $t, 'types' => $defs, 'values' => $vals, 'missing_required' => $t ? missing_required($types[$t], $personal[$t] ?? []) : [], 'shop_name' => $s['shop_name']]);
}
if ($sub === 'profile' && $method === 'PUT') {
    $types = require ROOT . '/src/profile_fields.php';
    $t = (string)($in['payout_type'] ?? '');
    if (!isset($types[$t])) api_err('validation', 'payout_type must be one of: ' . implode(', ', array_keys($types)), 422);
    $vrow = row('SELECT * FROM vendors WHERE id = ?', [$vid]);
    $personal = json_decode($vrow['personal'] ?? '{}', true) ?: [];
    $data = $personal[$t] ?? [];
    foreach ($types[$t]['fields'] as $f) {
        $raw = $in['fields']['f' . $f['id']] ?? $in['fields'][(string)$f['id']] ?? null;
        if ($raw === null) continue;
        $val = api_str($raw, 190);
        if ($f['type'] === 'date') { $d = DateTime::createFromFormat('Y-m-d', $val); $val = ($d && $d->format('Y-m-d') === $val) ? $val : ''; }
        elseif ($f['type'] === 'number') $val = preg_replace('/\D+/', '', $val);
        $data['f' . $f['id']] = $val;
    }
    $personal[$t] = $data;
    q('UPDATE vendors SET payout_type = ?, personal = ? WHERE id = ?', [$t, json_encode($personal, JSON_UNESCAPED_UNICODE), $vid]);
    api_out(['payout_type' => $t, 'missing_required' => missing_required($types[$t], $data)]);
}

/* ----- messages ----- */
if ($sub === 'messages') {
    if ($method === 'GET') {
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
        $msgs = rows("SELECT m.id, m.from_id, m.to_id, m.product_id, m.body, m.created_at, m.is_read, uf.name fname, ut.name tname FROM messages m JOIN users uf ON uf.id = m.from_id JOIN users ut ON ut.id = m.to_id WHERE (m.from_id = ? OR m.to_id = ?) AND m.id > ? ORDER BY m.id ASC LIMIT $limit", [$s['id'], $s['id'], (int)($_GET['since_id'] ?? 0)]);
        api_out(['messages' => array_map(fn($m) => ['id' => (int)$m['id'], 'direction' => (int)$m['to_id'] === (int)$s['id'] ? 'in' : 'out', 'peer_user_id' => (int)((int)$m['to_id'] === (int)$s['id'] ? $m['from_id'] : $m['to_id']), 'peer_name' => (int)$m['to_id'] === (int)$s['id'] ? $m['fname'] : $m['tname'], 'product_id' => $m['product_id'] ? (int)$m['product_id'] : null, 'body' => $m['body'], 'created_at' => date('c', strtotime($m['created_at']))], $msgs)]);
    }
    if ($method === 'POST') {
        $to = (int)($in['to_user_id'] ?? 0); $body = api_str($in['body'] ?? '', 4000);
        if ($body === '' || !$to || $to === (int)$s['id'] || !val('SELECT 1 FROM users WHERE id = ?', [$to])) api_err('validation', 'to_user_id (another user) and body are required.', 422);
        q('INSERT INTO messages (from_id, to_id, body) VALUES (?,?,?)', [$s['id'], $to, $body]);
        notify($to, 'New message from ' . ($s['shop_name'] ?: $s['name']), '/cabinet/messenger/' . $s['id']);
        api_out(['id' => (int)db()->lastInsertId()], 201);
    }
}

api_err('not_found', 'Unknown endpoint.', 404);
