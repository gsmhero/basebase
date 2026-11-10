<?php
// /reset            – ask for a reset link by email
// /reset/{token}    – choose a password (also used by the guest "set password & track order" link)
$raw = (string)($seg[1] ?? '');
$err = '';

if ($raw === '') {
    $sent = false;
    if (is_post()) {
        csrf_check();
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $_SESSION['reset_n'] = ($_SESSION['reset_n'] ?? 0) + 1;
        if ($_SESSION['reset_n'] > 5) $err = 'Too many requests. Try again later.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email.';
        else {
            $uid = (int)val('SELECT id FROM users WHERE email = ?', [$email]);
            // one link per minute per account; the answer is the same whether or not the account exists
            if ($uid && !val('SELECT 1 FROM login_tokens WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)', [$uid])) mail_reset($uid);
            $sent = true;
        }
    }
    layout('Reset password', function () use ($err, $sent) { ?>
<div class="wrap"><div class="auth-card"><div class="card">
  <h2>Forgot your password?</h2>
  <?php if ($sent): ?><div class="flash ok">If an account with this email exists, we have sent a link to choose a new password. Check your inbox.</div>
  <?php else: ?><p class="muted">Enter your email and we'll send you a link.</p>
  <?php if ($err): ?><div class="flash bad" style="margin-bottom:14px"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?><?= field('Email', 'email', $_POST['email'] ?? '', 'email', 'required autofocus autocomplete="username"') ?><button class="btn dark block lg">Send link</button></form><?php endif; ?>
  <p class="muted center" style="margin:18px 0 0"><a class="link" href="/login" style="color:var(--accent-d)">Back to sign in</a></p>
</div></div></div>
<?php });
    exit;
}

$tk = find_login_token($raw);
if (!$tk) {
    http_response_code(404);
    layout('Link expired', fn() => print('<section class="wrap empty"><h2>This link is no longer valid</h2><p>It may have been used already or has expired.</p><a class="btn dark" href="/reset">Get a new link</a></section>'));
    exit;
}
$usr = row('SELECT id, email, name FROM users WHERE id = ?', [$tk['user_id']]);
if (is_post()) {
    csrf_check();
    $pass = (string)($_POST['password'] ?? '');
    if (mb_strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
    elseif ($pass !== (string)($_POST['password2'] ?? '')) $err = 'Passwords do not match.';
    else {
        q('UPDATE users SET password_hash = ?, is_guest = 0, last_seen = NOW() WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $usr['id']]);
        q('UPDATE login_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [time(), $usr['id']]);
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$usr['id'];
        flash($tk['purpose'] === 'access' ? 'Password set. Your orders are below.' : 'Password changed. You are signed in.');
        redirect('/cabinet/orders');
    }
}
layout('Choose a password', function () use ($err, $usr, $tk) { ?>
<div class="wrap"><div class="auth-card"><div class="card">
  <h2><?= $tk['purpose'] === 'access' ? 'Set your password' : 'Choose a new password' ?></h2><p class="muted"><?= e($usr['email']) ?></p>
  <?php if ($err): ?><div class="flash bad" style="margin-bottom:14px"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <?= field('Password (min 8 characters)', 'password', '', 'password', 'required minlength="8" autofocus autocomplete="new-password"') ?>
    <?= field('Repeat password', 'password2', '', 'password', 'required minlength="8" autocomplete="new-password"') ?>
    <button class="btn gold block lg">Save and sign in</button>
  </form>
</div></div></div>
<?php });
