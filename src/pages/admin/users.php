<?php
$u = require_role('admin');
if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($do === 'role' && $id !== (int)$u['id'] && in_array($_POST['role'] ?? '', ['customer', 'vendor', 'supplier', 'admin'], true)) {
        q('UPDATE users SET role = ? WHERE id = ?', [$_POST['role'], $id]);
        if ($_POST['role'] !== 'customer' && !val('SELECT 1 FROM vendors WHERE user_id = ?', [$id])) make_vendor($id, (string)val('SELECT COALESCE(NULLIF(name,\'\'), email) FROM users WHERE id = ?', [$id]));
        flash('Role updated.');
    } elseif ($do === 'commission') {
        $c = max(0, min(100, round((float)str_replace(',', '.', (string)($_POST['pct'] ?? 10)), 2)));
        q('INSERT INTO settings (k, v) VALUES ("platform_commission", ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [(string)$c]);
        flash('Platform commission set to ' . $c . '%.');
    } elseif ($do === 'vendor-status' && in_array($_POST['status'] ?? '', ['active', 'suspended'], true)) {
        q('UPDATE vendors SET status = ? WHERE user_id = ?', [$_POST['status'], $id]); flash('Shop status updated.');
    } elseif ($do === 'payout' && in_array($_POST['res'] ?? '', ['done', 'rejected'], true)) {
        q("UPDATE wallet_tx SET status = ? WHERE id = ? AND type = 'withdraw' AND status = 'pending'", [$_POST['res'], $id]); flash('Withdrawal ' . $_POST['res'] . '.');
    }
    back('/admin/users');
}
$roles = ['customer', 'vendor', 'supplier', 'admin'];
$f = $_GET['role'] ?? 'all';
if (!in_array($f, array_merge($roles, ['staff', 'all']), true)) $f = 'all';
$srch = trim((string)($_GET['q'] ?? ''));
$sortMap = ['id' => 'u.id', 'name' => 'u.name', 'role' => "FIELD(u.role, 'admin', 'supplier', 'vendor', 'customer')", 'shop' => 'v.shop_name', 'products' => 'pc', 'seen' => 'u.last_seen'];
$sort = isset($sortMap[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'id';
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$w = []; $a = [];
if ($f === 'staff') $w[] = 'u.owner_id IS NOT NULL';
elseif ($f !== 'all') { $w[] = 'u.role = ? AND u.owner_id IS NULL'; $a[] = $f; }
if ($srch !== '') { $w[] = '(u.name LIKE ? OR u.email LIKE ? OR v.shop_name LIKE ?)'; array_push($a, "%$srch%", "%$srch%", "%$srch%"); }
$users = rows('SELECT u.*, v.status vstatus, v.shop_name, (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id) pc FROM users u LEFT JOIN vendors v ON v.user_id = u.id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY {$sortMap[$sort]} $dir, u.id DESC LIMIT 200", $a);
$counts = array_column(rows('SELECT IF(owner_id IS NOT NULL, "staff", role) r, COUNT(*) n FROM users GROUP BY r'), 'n', 'r');
$totalUsers = array_sum($counts);
$pay = rows("SELECT w.*, u.email, u.name FROM wallet_tx w JOIN users u ON u.id = w.user_id WHERE w.type = 'withdraw' AND w.status = 'pending' ORDER BY w.id");
$demo = (int)val("SELECT COUNT(*) FROM users WHERE email LIKE '%@demo.basebase'");
layout('Users manager', function () use ($users, $pay, $u, $demo, $roles, $f, $srch, $sort, $dir, $counts, $totalUsers) {
    $link = function (array $o) use ($f, $srch, $sort, $dir) { $q = array_merge(['role' => $f, 'q' => $srch, 'sort' => $sort, 'dir' => strtolower($dir)], $o); $q = array_filter($q, fn($v) => $v !== '' && $v !== 'all'); return '/admin/users' . ($q ? '?' . http_build_query($q) : ''); };
    $th = function (string $label, string $key, string $cls = '') use ($sort, $dir, $link) { $on = $sort === $key; $nd = $on && $dir === 'ASC' ? 'desc' : 'asc'; return '<th class="' . $cls . '"><a href="' . e($link(['sort' => $key, 'dir' => $nd])) . '" style="white-space:nowrap">' . $label . ($on ? ($dir === 'ASC' ? ' ↑' : ' ↓') : '') . '</a></th>'; };
?>
<div class="card-h"><div><h2 style="margin:0">Users manager</h2><span class="muted"><?= count($users) ?> of <?= $totalUsers ?> users</span></div>
  <form method="post" action="/admin/seed" data-confirm="<?= $demo ? 'Remove all demo data?' : 'Create demo suppliers, bloggers and products?' ?>"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $demo ? 'clear' : 'load' ?>"><button class="btn <?= $demo ? 'danger' : 'gold' ?>"><?= $demo ? 'Remove demo data' : 'Load demo data' ?></button></form></div>
<section class="card"><div class="card-h"><div><h3>Platform commission</h3><span class="muted sm">Share of the margin (Price − Promo price) kept by the platform when a blogger sells a product. Admin wallet: <b><?= money(wallet_balance(admin_user_id())) ?></b></span></div>
  <form method="post" class="share-box" style="flex:none"><?= csrf_field() ?><input type="hidden" name="do" value="commission"><input name="pct" type="number" min="0" max="100" step="0.5" value="<?= e(platform_pct()) ?>" style="width:90px"><span>%</span><button class="btn dark sm">Save</button></form></div></section>
<?php if ($pay): ?><section class="card"><h3>Pending withdrawals</h3><table class="tbl"><tbody><?php foreach ($pay as $w): ?><tr><td><b><?= e($w['name'] ?: $w['email']) ?></b><div class="muted sm"><?= e($w['note']) ?> · <?= ago($w['created_at']) ?></div></td><td class="num"><b><?= money(-$w['amount']) ?></b></td><td class="right">
  <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="payout"><input type="hidden" name="id" value="<?= $w['id'] ?>"><button name="res" value="done" class="btn sm dark">Mark paid</button> <button name="res" value="rejected" class="btn sm danger">Reject</button></form></td></tr><?php endforeach; ?></tbody></table></section><?php endif; ?>
<div class="tabs"><?php foreach (array_merge(['all' => 'All'], array_combine($roles, array_map('role_label', $roles)), ['staff' => 'Staff']) as $k => $l): ?><a class="<?= $f === $k ? 'on' : '' ?>" href="<?= e($link(['role' => $k])) ?>"><?= e($l) ?> <span class="muted sm"><?= $k === 'all' ? $totalUsers : (int)($counts[$k] ?? 0) ?></span></a><?php endforeach; ?></div>
<form method="get" class="share-box" style="margin:14px 0"><?php if ($f !== 'all'): ?><input type="hidden" name="role" value="<?= e($f) ?>"><?php endif; ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e(strtolower($dir)) ?>"><input name="q" value="<?= e($srch) ?>" placeholder="Search name, email or shop"><button class="btn dark" style="flex:none">Search</button></form>
<section class="card scroll"><table class="tbl"><thead><tr><?= $th('User', 'name') ?><?= $th('Role', 'role') ?><?= $th('Shop', 'shop') ?><?= $th('Products', 'products', 'num') ?><?= $th('Last seen', 'seen') ?><th></th></tr></thead><tbody>
<?php foreach ($users as $x): ?><tr><td><b><?= e($x['name'] ?: '—') ?></b><?= $x['owner_id'] ? ' <span class="badge info">Staff</span>' : '' ?><div class="muted sm"><?= e($x['email']) ?></div></td>
  <td><form method="post" class="share-box"><?= csrf_field() ?><input type="hidden" name="do" value="role"><input type="hidden" name="id" value="<?= $x['id'] ?>"><select name="role" onchange="this.form.submit()" <?= (int)$x['id'] === (int)$u['id'] || $x['owner_id'] ? 'disabled' : '' ?> style="padding:6px 8px;font-size:13px"><?php foreach (['customer', 'vendor', 'supplier', 'admin'] as $r): ?><option value="<?= $r ?>" <?= $x['role'] === $r ? 'selected' : '' ?>><?= role_label($r) ?></option><?php endforeach; ?></select></form></td>
  <td><?= $x['shop_name'] ? e($x['shop_name']) . ' ' . badge($x['vstatus']) : '—' ?></td><td class="num"><?= $x['pc'] ?></td><td class="muted"><?= $x['last_seen'] ? ago($x['last_seen']) : '—' ?></td>
  <td class="right"><?php if ($x['shop_name'] && (int)$x['id'] !== (int)$u['id']): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="do" value="vendor-status"><input type="hidden" name="id" value="<?= $x['id'] ?>"><button name="status" value="<?= $x['vstatus'] === 'active' ? 'suspended' : 'active' ?>" class="btn sm"><?= $x['vstatus'] === 'active' ? 'Suspend' : 'Activate' ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$users): ?><tr><td colspan="6" class="center muted" style="padding:30px">No users match.</td></tr><?php endif; ?>
</tbody></table></section>
<?php }, ['cabinet' => true]);
