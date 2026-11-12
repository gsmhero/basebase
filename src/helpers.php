<?php
declare(strict_types=1);

/* ---------- basics ---------- */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }
function back(string $fallback = '/'): never { redirect($_SERVER['HTTP_REFERER'] ?? $fallback); }
function money($v): string {
    $v = (float)$v;
    return number_format($v, fmod($v, 1.0) ? 2 : 0, ',', "\u{00A0}") . "\u{00A0}₽";
}
function num($v): string { return number_format((float)$v, 0, ',', "\u{00A0}"); }
function slugify(string $s): string {
    $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ы'=>'y','э'=>'e','ю'=>'yu','я'=>'ya','ъ'=>'','ь'=>''];
    $s = strtr(mb_strtolower($s), $map);
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return $s ?: 'shop';
}
function ago(string $ts): string {
    $d = time() - strtotime($ts);
    if ($d < 60) return 'just now';
    if ($d < 3600) return intdiv($d, 60) . ' min ago';
    if ($d < 86400) return intdiv($d, 3600) . ' h ago';
    return date('d.m.Y', strtotime($ts));
}

/* ---------- db shortcuts ---------- */
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { return q($sql, $p)->fetch() ?: null; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function in_clause(array $ids): string { return implode(',', array_fill(0, count($ids), '?')); }

/* ---------- csrf / flash ---------- */
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
function csrf_field(): string { return '<input type="hidden" name="_t" value="' . csrf() . '">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['_t'] ?? ''))) { http_response_code(419); exit('Session expired. Go back and reload the page.'); }
}
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'][] = [$type, $msg]; }
function flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

/* ---------- auth ---------- */
function me(bool $reload = false): ?array {
    static $u = false;
    if ($u === false || $reload) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $sql = 'SELECT u.*, v.id AS vid, v.slug, v.shop_name, v.avatar, v.commission_pct FROM users u LEFT JOIN vendors v ON v.user_id = u.id WHERE u.id = ?';
            $u = row($sql, [$_SESSION['uid']]);
            if ($u && $u['owner_id']) {
                // staff account: acts as its owner's shop, limited to the granted sections
                $o = row($sql, [$u['owner_id']]);
                if (!$o || $o['role'] === 'customer' || $o['owner_id']) $u = null;
                else {
                    $o['sub'] = true; $o['sub_id'] = (int)$u['id'];
                    $o['name'] = $u['name'] ?: 'Staff';
                    $o['perms'] = array_values(array_filter(explode(',', (string)$u['perms'])));
                    $o['role'] = $o['role'] === 'admin' ? 'supplier' : $o['role'];
                    $u = $o;
                }
            } elseif ($u) { $u['sub'] = false; $u['sub_id'] = null; $u['perms'] = ['products', 'orders', 'messages', 'blog']; }
            if (!$u) unset($_SESSION['uid']);
        }
    }
    return $u;
}
const PERMS = ['products' => 'Products (add, edit, hide from stock)', 'orders' => 'Orders', 'messages' => 'Messaging', 'blog' => 'Blog posting'];
function has_perm(?array $u, string $p): bool { return $u && in_array($p, $u['perms'], true); }
function deny(): never {
    http_response_code(403);
    layout('No access', fn() => print('<section class="wrap empty"><h2>No access</h2><p>Your account does not include this section.</p><a class="btn dark" href="/cabinet">Back to cabinet</a></section>'));
    exit;
}
function require_perm(array $u, string $p): array { if (!has_perm($u, $p)) deny(); return $u; }
function owner_only(array $u): array { if ($u['sub']) deny(); return $u; }
function role_label(string $r): string { return ['customer' => 'Customer', 'vendor' => 'Reseller', 'supplier' => 'Supplier', 'admin' => 'Administrator', 'agent' => 'Agent'][$r] ?? $r; }
function require_login(): array {
    $u = me();
    if (!$u) { flash('Please sign in first.', 'warn'); $_SESSION['next'] = $_SERVER['REQUEST_URI'] ?? '/'; redirect('/login'); }
    return $u;
}
function can_supply(?array $u): bool { return $u && in_array($u['role'], ['supplier', 'admin', 'agent'], true); }
function can_resell(?array $u): bool { return $u && in_array($u['role'], ['vendor', 'admin', 'agent'], true); }
function require_role(string ...$roles): array {
    $u = require_login();
    $have = $u['role'] === 'agent' ? ['agent', 'vendor', 'supplier'] : [$u['role']];   // an Agent can do everything a Reseller and a Supplier can
    if (!array_intersect($have, $roles)) { http_response_code(403); layout('Forbidden', fn() => print('<div class="empty"><h2>No access</h2><p>Your account type cannot open this section.</p></div>')); exit; }
    return $u;
}
function make_vendor(int $uid, string $name): int {
    $slug = slugify($name); $base = $slug; $i = 1;
    while (val('SELECT 1 FROM vendors WHERE slug = ?', [$slug])) $slug = $base . '-' . (++$i);
    q('INSERT INTO vendors (user_id, shop_name, slug, status) VALUES (?,?,?,?)', [$uid, $name, $slug, 'active']);
    return (int)db()->lastInsertId();
}
function notify(int $uid, string $body, string $url = ''): void {
    q('INSERT INTO notifications (user_id, body, url) VALUES (?,?,?)', [$uid, $body, $url]);
}
function wallet_balance(int $uid): float {
    return (float)val("SELECT COALESCE(SUM(amount),0) FROM wallet_tx WHERE user_id = ? AND status <> 'rejected'", [$uid]);
}

/* ---------- catalog helpers ---------- */
function eff_price(array $p, string $variant = ''): float {
    if ($variant !== '' && !empty($p['variants'])) {
        foreach (json_decode($p['variants'], true) ?: [] as $v) {
            if (($v['name'] ?? '') === $variant && (float)($v['price'] ?? 0) > 0) return (float)$v['price'];
        }
    }
    return (float)$p['price'];   // customers always see and pay the public price
}
/** What the supplier receives for one unit sold at $unit (the "promo" price, scaled for variants). */
function base_price(array $p, float $unit): float {
    $pr = (float)($p['promo_price'] ?? 0); $pp = (float)$p['price'];
    return ($pr > 0 && $pp > 0 && $pr < $pp) ? round($unit * $pr / $pp, 2) : $unit;
}
function setting(string $k, string $d = ''): string {
    static $c;
    $c ??= array_column(rows('SELECT k, v FROM settings'), 'v', 'k');
    return $c[$k] ?? $d;
}
function platform_pct(): float { return max(0.0, min(100.0, (float)setting('platform_commission', '10'))); }
/** Split of the margin (price − supplier price): [admin fee, reseller share]. No reseller → admin keeps all of it. */
function item_split(array $p, float $unit, int $qty, bool $hasReseller): array {
    $margin = max(0.0, ($unit - base_price($p, $unit)) * $qty);
    $fee = round($margin * platform_pct() / 100, 2);                  // total platform commission (Basebase + agent)
    return [$fee, $hasReseller ? round($margin - $fee, 2) : 0.0];     // no blogger: the seller keeps the rest of the margin
}
/** The active Agent a seller (vendor id) belongs to, if any. */
function agent_for_vendor(int $vendorId): ?array {
    return row("SELECT a.id, a.user_id, a.agency_fee FROM vendors v JOIN users u ON u.id = v.user_id JOIN agents a ON a.id = u.agent_id WHERE v.id = ? AND a.status = 'active'", [$vendorId]) ?: null;
}
/** Agent's part of the platform commission: Agency fee % of the margin, never more than the total commission. */
function agent_cut(array $p, float $unit, int $qty, float $totalFee, ?array $agent): float {
    if (!$agent) return 0.0;
    $margin = max(0.0, ($unit - base_price($p, $unit)) * $qty);
    return max(0.0, min($totalFee, round($margin * (float)$agent['agency_fee'] / 100, 2)));
}
function reseller_earn(array $p, ?float $unit = null): float {
    $unit ??= (float)$p['price'];
    return item_split($p, $unit, 1, true)[1];
}
function admin_user_id(): int {
    return (int)(val('SELECT id FROM users WHERE email = ?', [mb_strtolower(env('ADMIN_EMAIL', 'admin@basebase.ru'))]) ?: val("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1"));
}
const SET_SUM_SQL = '(SELECT COALESCE(SUM(p.price * si.qty),0) FROM set_items si JOIN products p ON p.id = si.product_id WHERE si.set_id = s.id)';
function categories_tree(): array {
    $all = rows('SELECT * FROM categories ORDER BY id');
    $tree = [];
    foreach ($all as $c) if (!$c['parent_id']) $tree[$c['id']] = $c + ['children' => []];
    foreach ($all as $c) if ($c['parent_id'] && isset($tree[$c['parent_id']])) $tree[$c['parent_id']]['children'][] = $c;
    return $tree;
}
function category_path(?int $id): array {
    if (!$id) return [];
    $c = row('SELECT * FROM categories WHERE id = ?', [$id]);
    if (!$c) return [];
    return $c['parent_id'] ? [...category_path((int)$c['parent_id']), $c] : [$c];
}
function rating_of(int $pid): array {
    $r = row('SELECT COUNT(*) n, COALESCE(AVG(rating),0) avg FROM reviews WHERE product_id = ?', [$pid]);
    return [(int)$r['n'], (float)$r['avg']];
}

/* ---------- uploads ---------- */
function save_upload(array $f): ?string {
    if (($f['error'] ?? 4) !== UPLOAD_ERR_OK || $f['size'] > 5 * 1024 * 1024) return null;
    $info = @getimagesize($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime'] ?? ''] ?? null;
    if (!$ext) return null;
    $dir = ROOT . '/public/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "<FilesMatch \"\\.(php|phtml|php\\d|phar|pl|cgi|sh)\$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes -ExecCGI\n");
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    return move_uploaded_file($f['tmp_name'], "$dir/$name") ? "/uploads/$name" : null;
}
function save_uploads(string $field): array {
    $out = [];
    if (empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) return $out;
    foreach ($_FILES[$field]['name'] as $i => $_) {
        $p = save_upload(['error' => $_FILES[$field]['error'][$i], 'size' => $_FILES[$field]['size'][$i], 'tmp_name' => $_FILES[$field]['tmp_name'][$i]]);
        if ($p) $out[] = $p;
    }
    return $out;
}

/* ---------- view helpers ---------- */
function ico(string $n, int $s = 20): string {
    static $i = [
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'cart' => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2 2h2l2.7 12.4a2 2 0 0 0 2 1.6h9.8a2 2 0 0 0 2-1.6L22 7H5.1"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users' => '<circle cx="9" cy="7" r="4"/><path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2M17 3.1a4 4 0 0 1 0 7.8M22 21v-2a4 4 0 0 0-3-3.9"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'trash' => '<path d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14"/>',
        'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
        'star' => '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1Z"/>',
        'layers' => '<path d="m12 2 10 5-10 5L2 7Z"/><path d="m2 12 10 5 10-5M2 17l10 5 10-5"/>',
        'box' => '<path d="M21 8 12 3 3 8v8l9 5 9-5Z"/><path d="m3 8 9 5 9-5M12 13v8"/>',
        'chart' => '<path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 5-6"/>',
        'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h14v4"/><path d="M3 7v11a2 2 0 0 0 2 2h16V9H5a2 2 0 0 1-2-2Z"/><circle cx="17" cy="14.5" r="1"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'shop' => '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11h16V9"/><path d="M9 20v-6h6v6"/>',
        'tag' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8Z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
        'msg' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2Z"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
        'code' => '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
        'check' => '<path d="m4 12 5 5L20 6"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'arrow' => '<path d="M5 12h14M13 5l7 7-7 7"/>',
        'truck' => '<path d="M1 3h14v13H1zM15 8h4l4 4v4h-8"/><circle cx="5.5" cy="18.5" r="2"/><circle cx="18.5" cy="18.5" r="2"/>',
        'shield' => '<path d="M12 2 4 5v6c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5Z"/><path d="m9 12 2 2 4-4"/>',
        'zap' => '<path d="M13 2 3 14h9l-1 8 10-12h-9Z"/>',
        'home' => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    ];
    return '<svg class="ico" width="' . $s . '" height="' . $s . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($i[$n] ?? '') . '</svg>';
}
function logo(string $cls = 'logo'): string {
    static $svg;
    $svg ??= preg_replace('/<svg /', '<svg role="img" aria-label="basebase" ', file_get_contents(ROOT . '/src/logo.svg'), 1);
    return '<span class="' . $cls . '">' . $svg . '</span>';
}
function stars(float $r, int $s = 16): string {
    $o = '<span class="stars" title="' . number_format($r, 1) . '">';
    for ($i = 1; $i <= 5; $i++) $o .= '<span class="' . ($r >= $i - 0.25 ? 'on' : ($r >= $i - 0.75 ? 'half' : '')) . '">' . ico('star', $s) . '</span>';
    return $o . '</span>';
}
function pimg(array $p, string $cls = ''): string {
    if (!empty($p['image'])) return '<img class="' . $cls . '" src="' . e($p['image']) . '" alt="' . e($p['title']) . '" loading="lazy">';
    $h = ($p['id'] * 47) % 360;
    $ini = mb_strtoupper(mb_substr(trim($p['title']), 0, 1));
    return '<div class="ph ' . $cls . '" style="--h:' . $h . '"><span>' . e($ini) . '</span></div>';
}
function badge(string $status): string {
    $m = ['pending' => 'warn', 'prepare' => 'info', 'shipped' => 'info', 'on_delivery' => 'info', 'delivered' => 'ok', 'archived' => 'mute', 'returned' => 'bad',
          'paid' => 'ok', 'unpaid' => 'warn', 'refunded' => 'bad', 'active' => 'ok', 'draft' => 'mute', 'done' => 'ok', 'rejected' => 'bad'];
    return '<span class="badge ' . ($m[$status] ?? 'mute') . '">' . e(ucfirst(str_replace('_', ' ', $status))) . '</span>';
}
function product_card(array $p, int $shop = 0): string {
    $price = eff_price($p);
    $href = '/product/' . $p['id'] . ($shop ? '?shop=' . $shop : '');
    $o = '<article class="pcard"><a class="pcard-img" href="' . $href . '">' . pimg($p);
    $o .= '</a><div class="pcard-body"><a class="pcard-title" href="' . $href . '">' . e($p['title']) . '</a>';
    if (!empty($p['cat_name'])) $o .= '<div class="muted sm">' . e($p['cat_name']) . '</div>';
    $o .= '<div class="pcard-foot"><div class="price">' . money($price) . '</div>';
    $o .= '<form method="post" action="/do/wish">' . csrf_field() . '<input type="hidden" name="id" value="' . $p['id'] . '"><button class="icon-btn' . (wished((int)$p['id']) ? ' on' : '') . '" title="Watch later">' . ico('clock', 18) . '</button></form></div></div></article>';
    return $o;
}
function pager(int $total, int $per, int $page, array $qs = []): string {
    $pages = (int)ceil($total / $per);
    if ($pages < 2) return '';
    $o = '<nav class="pager">';
    for ($i = 1; $i <= $pages; $i++) {
        $o .= '<a class="' . ($i === $page ? 'on' : '') . '" href="?' . e(http_build_query($qs + ['page' => $i])) . '">' . $i . '</a>';
    }
    return $o . '</nav>';
}
function field(string $label, string $name, $value = '', string $type = 'text', string $extra = ''): string {
    return '<label class="fld"><span>' . e($label) . '</span><input type="' . $type . '" name="' . e($name) . '" value="' . e($value) . '" ' . $extra . '></label>';
}

/* ---------- layout ---------- */
function layout(string $title, callable $body, array $opt = []): void {
    $u = me();
    $counts = ['n' => 0, 'm' => 0, 'c' => 0, 'w' => 0];
    if ($u) {
        if (!$u['sub'] || has_perm($u, 'orders') || has_perm($u, 'messages')) $counts['n'] = (int)val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$u['id']]);
        if (has_perm($u, 'messages')) $counts['m'] = (int)val('SELECT COUNT(*) FROM messages WHERE to_id = ? AND is_read = 0', [$u['id']]);
        if (!$u['sub']) {
            $counts['c'] = (int)val('SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE user_id = ?', [$u['id']]);
            $counts['w'] = (int)val('SELECT COUNT(*) FROM wishlist WHERE user_id = ?', [$u['id']]);
        }
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $active = fn(string $p) => ($p === '/' ? $path === '/' : str_starts_with($path, $p)) ? ' class="on"' : '';
    $cab = !empty($opt['cabinet']);
    $desc = $opt['desc'] ?? 'Collect products, share them, earn on every sale. Multivendor marketplace with dropshipping.';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · basebase</title>
<meta name="description" content="<?= e($desc) ?>">
<meta name="theme-color" content="#14161d">
<link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&family=Unbounded:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css?v=<?= @filemtime(ROOT . '/public/assets/app.css') ?>">
</head>
<body class="<?= $cab ? 'is-cab' : '' ?>">
<header class="top">
  <div class="wrap top-in">
    <a class="brand" href="/" aria-label="basebase"><?= logo() ?></a>
    <nav class="nav" id="nav">
      <a href="/"<?= $active('/') ?>>Home</a>
      <a href="/products"<?= $active('/products') ?>>Products</a>
      <a href="/blogs"<?= $active('/blogs') ?>>Blogs</a>
      <a href="/faq"<?= $active('/faq') ?>>FAQ</a>
    </nav>
    <form class="search" action="/products" role="search">
      <?= ico('search', 18) ?><input name="q" placeholder="Search products" value="<?= e($_GET['q'] ?? '') ?>" aria-label="Search">
    </form>
    <div class="tools">
      <a class="tool" href="/cabinet/notifications" title="Notifications"><?= ico('bell') ?><?= $counts['n'] ? '<i>' . $counts['n'] . '</i>' : '' ?></a>
      <a class="tool" href="/cabinet/messenger" title="Messenger"><?= ico('mail') ?><?= $counts['m'] ? '<i>' . $counts['m'] . '</i>' : '' ?></a>
      <a class="tool" href="/cart" title="Cart"><?= ico('cart') ?><?= $counts['c'] ? '<i>' . $counts['c'] . '</i>' : '' ?></a>
      <a class="tool" href="/watch" title="Watch later"><?= ico('clock') ?><?= $counts['w'] ? '<i>' . $counts['w'] . '</i>' : '' ?></a>
    </div>
    <div class="auth">
      <?php if ($u): ?>
        <a class="btn sm dark" href="/cabinet"><?= ico('user', 16) ?> <?= e($u['name'] ?: 'Cabinet') ?></a>
      <?php else: ?>
        <a class="link" href="/login">Login</a><a class="btn sm dark" href="/register">Register</a>
      <?php endif; ?>
    </div>
    <button class="burger" id="burger" aria-label="Menu"><?= ico('menu', 24) ?></button>
  </div>
</header>
<?php if ($f = flashes()): ?><div class="wrap flashes"><?php foreach ($f as [$t, $m]): ?><div class="flash <?= e($t) ?>"><?= e($m) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($cab && $u): cabinet_open($u, $path); else: ?><main><?php endif; ?>
<?php $body(); ?>
<?php if ($cab && $u): ?></div></div></main><?php else: ?></main><?php endif; ?>
<footer class="foot">
  <div class="wrap foot-in">
    <div><?= logo('logo sm') ?><p class="muted">Multivendor marketplace with dropshipping.<br>Collect · Share · Earn.</p></div>
    <div><b>Marketplace</b><a href="/products">Products</a><a href="/blogs">Blogs &amp; shops</a><a href="/faq">FAQ</a></div>
    <div><b>Account</b><a href="/cabinet">Cabinet</a><a href="/cabinet/collection">Collection</a><a href="/cart">Cart</a></div>
  </div>
  <div class="wrap copy">© <?= date('Y') ?> basebase.ru</div>
</footer>
<script src="/assets/app.js?v=<?= @filemtime(ROOT . '/public/assets/app.js') ?>" defer></script>
</body>
</html>
<?php
}

function cabinet_open(array $u, string $path): void {
    $sub = $u['sub'];
    $shop = can_supply($u) || can_resell($u);
    $items = $sub ? [] : [['/cabinet', 'grid', 'Dashboard']];
    if ($shop && !empty($u['slug'])) $items[] = ['/shop/' . $u['slug'], 'shop', 'My shop page'];
    if ($shop && has_perm($u, 'products') && can_supply($u)) $items[] = ['/cabinet/products', 'box', 'My products'];
    if (has_perm($u, 'orders')) $items[] = ['/cabinet/orders', 'truck', $shop ? 'Orders manager' : 'My orders'];
    if ($shop && !$sub) $items[] = ['/cabinet/stats', 'chart', 'Selling stats'];
    if (has_perm($u, 'messages')) $items[] = ['/cabinet/messenger', 'msg', 'Messenger'];
    if ($shop && !$sub) $items[] = ['/cabinet/profile', 'wallet', 'Profile & wallet'];
    if (can_resell($u) && has_perm($u, 'products')) $items[] = ['/cabinet/collection', 'layers', 'Product collection'];
    if ($shop && has_perm($u, 'blog')) $items[] = ['/cabinet/blog', 'edit', 'My blog'];
    if ($shop && !$sub) $items[] = ['/cabinet/team', 'users', 'Team'];
    if (!$sub) $items[] = ['/cabinet/settings', 'settings', 'Settings'];
    if ($u['role'] === 'agent' && !$sub) $items[] = ['/cabinet/agent', 'share', 'Partner site'];
    if ($u['role'] === 'admin') { $items[] = ['/admin/users', 'users', 'Users manager']; $items[] = ['/admin/agents', 'share', 'Agents manager']; }
    ?>
<main class="wrap cab">
  <aside class="side" id="side">
    <div class="side-user"><span class="av"><?= e(mb_strtoupper(mb_substr($u['name'] ?: $u['email'], 0, 1))) ?></span><div><b><?= e($u['name'] ?: 'Member') ?></b><small><?= $sub ? 'Staff · ' . e($u['shop_name']) : e(role_label($u['role'])) ?></small></div></div>
    <?php foreach ($items as [$href, $ic, $label]): $on = $href === '/cabinet' ? $path === '/cabinet' : str_starts_with($path, $href); ?>
      <a href="<?= e($href) ?>" class="<?= $on ? 'on' : '' ?>"><?= ico($ic, 18) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
    <form method="post" action="/logout"><?= csrf_field() ?><button class="side-out"><?= ico('logout', 18) ?><span>Log out</span></button></form>
  </aside>
  <div class="cab-main"><div>
<?php }

function wished(int $pid): bool {
    static $s;
    if ($s === null) {
        $u = me();
        $s = ($u && !$u['sub']) ? array_flip(array_column(rows('SELECT product_id FROM wishlist WHERE user_id = ?', [$u['id']]), 'product_id')) : [];
    }
    return isset($s[$pid]);
}

/* ---------- orders ---------- */
/**
 * Creates an order from prepared lines [pid, qty, variant, unit, shop_id, title].
 * Re-checks stock, applies the margin split, notifies sellers. Throws RuntimeException on stock/availability problems.
 */
function place_order(int $customerId, array $lines, array $f, string $pay): int {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $total = 0.0; $ready = [];
        foreach ($lines as $l) {
            $p = row('SELECT * FROM products WHERE id = ? FOR UPDATE', [$l['pid']]);
            if (!$p || $p['status'] !== 'active') throw new RuntimeException('“' . $l['title'] . '” is no longer available.');
            $variants = json_decode($p['variants'] ?? '[]', true) ?: [];
            if ($variants) {
                $ok = false;
                foreach ($variants as &$v) if ($v['name'] === $l['variant']) { if ((int)$v['stock'] < $l['qty']) throw new RuntimeException('Not enough stock for “' . $l['title'] . '”.'); $v['stock'] = (int)$v['stock'] - $l['qty']; $ok = true; }
                unset($v);
                if (!$ok) throw new RuntimeException('Option no longer available for “' . $l['title'] . '”.');
                q('UPDATE products SET variants = ?, stock = ? WHERE id = ?', [json_encode($variants, JSON_UNESCAPED_UNICODE), array_sum(array_column($variants, 'stock')), $p['id']]);
            } else {
                if ((int)$p['stock'] < $l['qty']) throw new RuntimeException('Not enough stock for “' . $l['title'] . '”.');
                q('UPDATE products SET stock = stock - ? WHERE id = ?', [$l['qty'], $p['id']]);
            }
            $total += $l['unit'] * $l['qty']; $ready[] = [$l, $p];
        }
        q('INSERT INTO orders (customer_id, total, status, name, phone, address, delivery_method, payment_method, payment_status, notes) VALUES (?,?,?,?,?,?,?,?,?,?)',
          [$customerId, $total, 'new', $f['name'], $f['phone'], $f['address'], $f['delivery'], $pay, $pay === 'test' ? 'paid' : 'unpaid', $f['notes']]);
        $oid = (int)$pdo->lastInsertId();
        $notify = [];
        foreach ($ready as [$l, $p]) {
            $reseller = (int)$l['shop_id'];
            if ($reseller) {
                $ru = (int)val('SELECT user_id FROM vendors WHERE id = ?', [$reseller]);
                if (!$ru || $ru === $customerId || $reseller === (int)$p['vendor_id']) $reseller = 0;
            }
            [$fee, $rfee] = item_split($p, (float)$l['unit'], (int)$l['qty'], (bool)$reseller);
            $ag = agent_for_vendor((int)$p['vendor_id']);
            $afee = agent_cut($p, (float)$l['unit'], (int)$l['qty'], $fee, $ag);
            q('INSERT INTO order_items (order_id, product_id, vendor_id, qty, price, commission, agent_id, agent_fee, fulfillment, item_status, reseller_id, reseller_fee, title, variant) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
              [$oid, $p['id'], $p['vendor_id'], $l['qty'], $l['unit'], $fee, $ag['id'] ?? null, $afee, $p['is_dropship'] ? 'dropship' : 'vendor', 'pending', $reseller ?: null, $rfee, $p['title'], $l['variant']]);
            $notify[(int)val('SELECT user_id FROM vendors WHERE id = ?', [$p['vendor_id']])][] = $p['title'];
            if ($reseller) $notify[(int)val('SELECT user_id FROM vendors WHERE id = ?', [$reseller])][] = $p['title'];
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    foreach ($notify as $uid => $titles) notify($uid, 'New order BB-' . (1000 + $oid) . ': ' . count($titles) . ' item(s)', '/cabinet/orders/' . $oid);
    return $oid;
}
