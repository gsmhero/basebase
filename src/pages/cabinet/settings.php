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
    } elseif ($do === 'role' && $u['role'] !== 'admin' && in_array($_POST['role'] ?? '', ['customer', 'vendor', 'supplier', 'agent'], true)) {
        q('UPDATE users SET role = ? WHERE id = ?', [$_POST['role'], $u['id']]);
        if ($_POST['role'] !== 'customer' && !val('SELECT 1 FROM vendors WHERE user_id = ?', [$u['id']])) make_vendor((int)$u['id'], $u['name'] ?: $u['email']);
        flash('Account type changed to ' . role_label($_POST['role']) . '.');
    } elseif ($do === 'migrate' && !in_array($u['role'], ['admin', 'agent'], true)) {
        $target = trim((string)($_POST['target'] ?? ''));
        $ag = null;
        if ($target === '' || $target === '-') $ag = false;   // leave: work directly with Basebase
        else {
            if (preg_match('/^[A-Za-z0-9_-]{16,80}$/', $target)) $ag = row("SELECT * FROM agents WHERE agent_key = ? AND status = 'active'", [$target]);
            if (!$ag) { $h = mb_strtolower((string)(parse_url(preg_match('~^https?://~', $target) ? $target : 'https://' . $target, PHP_URL_HOST) ?? '')); if ($h !== '') $ag = row("SELECT * FROM agents WHERE status = 'active' AND LOWER(domain) IN (?, ?)", ['https://' . $h, 'http://' . $h]); }
        }
        $old = $u['agent_id'] ? row('SELECT * FROM agents WHERE id = ?', [$u['agent_id']]) : null;
        if ($ag === null) flash('No connected site found for this address or key. Ask the site administrator for the address of the site.', 'bad');
        elseif ($ag && (int)$ag['user_id'] === (int)$u['id']) flash('This is your own site.', 'bad');
        elseif ($ag && $old && (int)$old['id'] === (int)$ag['id']) flash('You are already on this site.', 'warn');
        else {
            q('UPDATE users SET agent_id = ?, ext_id = NULL WHERE id = ?', [$ag ? $ag['id'] : null, $u['id']]);
            $name = $u['name'] ?: $u['email'];
            if ($old) notify((int)$old['user_id'], 'User left your site: ' . $name, '/cabinet/agent');
            if ($ag) notify((int)$ag['user_id'], 'New user joined your site: ' . $name . ' (user ID ' . (int)$u['id'] . ')', '/cabinet/agent');
            flash($ag ? 'Moved to ' . preg_replace('~^https?://~', '', (string)$ag['domain']) . '. Your products and balance stay with you. On the new site open Basebase → Connection and enter your Seller ID: ' . (int)$u['id'] . '.' : 'You now work directly with Basebase, without a partner site.');
        }
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
  <?php if (!in_array($u['role'], ['admin', 'agent'], true)): $cur = $u['agent_id'] ? row('SELECT domain FROM agents WHERE id = ?', [$u['agent_id']]) : null; ?>
  <form class="card span-12" method="post" data-confirm="Move your account to another site? Your products and balance stay with you."><?= csrf_field() ?><input type="hidden" name="do" value="migrate"><h3>Partner site</h3>
    <p class="muted sm" style="margin-top:0">Your site: <b><?= $cur ? e(preg_replace('~^https?://~', '', (string)$cur['domain'])) : 'none — you work directly with Basebase' ?></b>. You can move to any other site that runs Sysfera and is connected to Basebase. Products, orders and balance stay with your account; your previous site stops seeing you in its users list.</p>
    <?= field('Address or AGENT_KEY of the new site', 'target', '', 'text', 'placeholder="https://shop.example.com"') ?>
    <p class="muted sm">Your Seller ID is <b><?= (int)$u['id'] ?></b>: enter it on the new site in Basebase → Connection. Leave the field empty and press the button to work without a partner site.</p>
    <button class="btn dark">Move my account</button></form>
  <?php endif; ?>
  <form class="card span-12" method="post" data-confirm="Switch account type? Your products, orders and team stay, but sections change."><?= csrf_field() ?><input type="hidden" name="do" value="role"><h3>Account type</h3>
    <?php if ($u['role'] === 'admin'): ?><p class="muted">You are the site administrator. Administrator accounts cannot be switched.</p>
    <?php else: ?><p class="muted">Switch any time — your data is kept. Resellers collect products and write blogs; suppliers list and ship products; customers just buy.</p>
    <div class="seg"><?php foreach (['vendor' => ['Reseller', 'collect &amp; earn'], 'supplier' => ['Supplier', 'list products'], 'agent' => ['Agent', 'partner site'], 'customer' => ['Customer', 'just buy']] as $k => [$l, $d]): ?><label><input type="radio" name="role" value="<?= $k ?>" <?= $u['role'] === $k ? 'checked' : '' ?>><span><?= $l ?><small><?= $d ?></small></span></label><?php endforeach; ?></div>
    <button class="btn dark">Switch account type</button><?php endif; ?></form>
</div>
<?php }, ['cabinet' => true]);
