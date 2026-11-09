<?php
if (me()) redirect('/cabinet');
$err = '';
if (is_post()) {
    csrf_check();
    $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
    if ($_SESSION['tries'] > 8 && time() - ($_SESSION['try_t'] ?? 0) < 60) { $err = 'Too many attempts. Wait a minute.'; }
    else {
        $_SESSION['try_t'] = time();
        $u = row('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim((string)($_POST['email'] ?? '')))]);
        if ($u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
            if ($u['email'] === mb_strtolower(env('ADMIN_EMAIL', 'admin@basebase.ru')) && $u['role'] !== 'admin') {
                q("UPDATE users SET role = 'admin' WHERE id = ?", [$u['id']]);
                if (!val('SELECT 1 FROM vendors WHERE user_id = ?', [$u['id']])) make_vendor((int)$u['id'], $u['name'] ?: 'Site Admin');
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id']; unset($_SESSION['tries']);
            q('UPDATE users SET last_seen = NOW() WHERE id = ?', [$u['id']]);
            $next = $_SESSION['next'] ?? '/cabinet'; unset($_SESSION['next']);
            redirect(str_starts_with($next, '/') && !str_starts_with($next, '//') ? $next : '/cabinet');
        }
        $err = 'Wrong email or password.';
    }
}
layout('Login', function () use ($err) { ?>
<div class="wrap"><div class="auth-card"><div class="card">
  <h2>Welcome back</h2><p class="muted">Sign in to your cabinet.</p>
  <?php if ($err): ?><div class="flash bad" style="margin-bottom:14px"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <?= field('Email', 'email', $_POST['email'] ?? '', 'email', 'required autofocus autocomplete="username"') ?>
    <?= field('Password', 'password', '', 'password', 'required autocomplete="current-password"') ?>
    <button class="btn dark block lg">Sign in</button>
  </form>
  <p class="muted center" style="margin:18px 0 0">New here? <a class="link" href="/register" style="color:var(--accent-d)">Create an account</a></p>
</div></div></div>
<?php });
