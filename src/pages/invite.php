<?php
$token = (string)($seg[1] ?? '');
$inv = row('SELECT i.*, v.shop_name, o.role AS owner_role FROM invites i JOIN users o ON o.id = i.owner_id LEFT JOIN vendors v ON v.user_id = o.id WHERE i.token = ? AND i.used_at IS NULL', [$token]);
if (!$inv || $inv['owner_role'] === 'customer') {
    http_response_code(404);
    layout('Invite', fn() => print('<section class="wrap empty"><h2>This invite is no longer valid</h2><p>Ask the shop owner to send you a new link.</p><a class="btn dark" href="/">Back to home</a></section>'));
    exit;
}
$err = '';
if (me()) $err = 'You are signed in. Log out first, then open the invite link again.';
elseif (is_post()) {
    csrf_check();
    $name = trim((string)($_POST['name'] ?? '')); $pass = (string)($_POST['password'] ?? '');
    if ($name === '') $err = 'Enter your name.';
    elseif (mb_strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
    elseif (val('SELECT 1 FROM users WHERE email = ?', [$inv['email']])) $err = 'This email already has an account.';
    else {
        q("INSERT INTO users (email, name, password_hash, role, owner_id, perms, last_seen) VALUES (?,?,?,'customer',?,?,NOW())", [$inv['email'], $name, password_hash($pass, PASSWORD_DEFAULT), $inv['owner_id'], $inv['perms']]);
        $uid = (int)db()->lastInsertId();
        q('UPDATE invites SET used_at = NOW() WHERE id = ?', [$inv['id']]);
        notify((int)$inv['owner_id'], $name . ' joined your team.', '/cabinet/team');
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        flash('Welcome to the team of ' . $inv['shop_name'] . '!');
        redirect('/cabinet');
    }
}
layout('Join team', function () use ($inv, $err) { ?>
<div class="wrap"><div class="auth-card"><div class="card">
  <span class="eyebrow">Team invite</span><h2>Join <?= e($inv['shop_name']) ?></h2>
  <p class="muted">You'll manage this shop as staff: <?= e(implode(', ', array_map(fn($p) => strtolower(explode(' ', PERMS[$p] ?? $p)[0]), explode(',', $inv['perms'])))) ?>.</p>
  <?php if ($err): ?><div class="flash bad" style="margin-bottom:14px"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <label class="fld"><span>Email</span><input value="<?= e($inv['email']) ?>" disabled></label>
    <?= field('Your name', 'name', $_POST['name'] ?? $inv['name'], 'text', 'required') ?>
    <?= field('Choose a password (min 8 characters)', 'password', '', 'password', 'required minlength="8" autocomplete="new-password"') ?>
    <button class="btn gold block lg">Accept invite</button>
  </form>
</div></div></div>
<?php });
