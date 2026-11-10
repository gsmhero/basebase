<?php
$u = owner_only(require_login());
if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'account') {
        $name = trim((string)($_POST['name'] ?? '')); $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Enter a name and a valid email.', 'bad');
        elseif (val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) flash('That email is already used.', 'bad');
        else { q('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?', [$name, $email, trim((string)($_POST['phone'] ?? '')), $u['id']]); flash('Account saved.'); }
    } elseif ($do === 'role' && $u['role'] !== 'admin' && in_array($_POST['role'] ?? '', ['customer', 'vendor', 'supplier'], true)) {
        q('UPDATE users SET role = ? WHERE id = ?', [$_POST['role'], $u['id']]);
        if ($_POST['role'] !== 'customer' && !val('SELECT 1 FROM vendors WHERE user_id = ?', [$u['id']])) make_vendor((int)$u['id'], $u['name'] ?: $u['email']);
        flash('Account type changed to ' . role_label($_POST['role']) . '.');
    } elseif ($do === 'password') {
        if (!password_verify((string)($_POST['old'] ?? ''), $u['password_hash'])) flash('Current password is wrong.', 'bad');
        elseif (mb_strlen((string)($_POST['new'] ?? '')) < 8) flash('New password must be at least 8 characters.', 'bad');
        else { q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash((string)$_POST['new'], PASSWORD_DEFAULT), $u['id']]); flash('Password changed.'); }
    }
    redirect('/cabinet/settings');
}
layout('Settings', function () use ($u) { ?>
<div class="card-h"><h2 style="margin:0">Settings</h2></div>
<div class="dash-grid">
  <form class="card span-6" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="account"><h3>Account</h3>
    <?= field('Name', 'name', $u['name'], 'text', 'required') ?><?= field('Email', 'email', $u['email'], 'email', 'required') ?><?= field('Phone', 'phone', $u['phone'] ?? '', 'tel') ?>
    <p class="muted sm">Account type: <b><?= e(ucfirst($u['role'])) ?></b></p><button class="btn dark">Save</button></form>
  <form class="card span-6" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="password"><h3>Password</h3>
    <?= field('Current password', 'old', '', 'password', 'required autocomplete="current-password"') ?><?= field('New password', 'new', '', 'password', 'required minlength="8" autocomplete="new-password"') ?><button class="btn dark">Change password</button></form>
  <form class="card span-12" method="post" data-confirm="Switch account type? Your products, orders and team stay, but sections change."><?= csrf_field() ?><input type="hidden" name="do" value="role"><h3>Account type</h3>
    <?php if ($u['role'] === 'admin'): ?><p class="muted">You are the site administrator. Administrator accounts cannot be switched.</p>
    <?php else: ?><p class="muted">Switch any time — your data is kept. Resellers collect products and write blogs; suppliers list and ship products; customers just buy.</p>
    <div class="seg"><?php foreach (['vendor' => ['Reseller', 'collect &amp; earn'], 'supplier' => ['Supplier', 'list products'], 'customer' => ['Customer', 'just buy']] as $k => [$l, $d]): ?><label><input type="radio" name="role" value="<?= $k ?>" <?= $u['role'] === $k ? 'checked' : '' ?>><span><?= $l ?><small><?= $d ?></small></span></label><?php endforeach; ?></div>
    <button class="btn dark">Switch account type</button><?php endif; ?></form>
</div>
<?php }, ['cabinet' => true]);
