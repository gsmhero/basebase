<?php
if (me()) redirect('/cabinet');
$err = '';
$as = $_POST['role'] ?? ($_GET['as'] ?? 'vendor');
if (!in_array($as, ['vendor', 'supplier', 'customer'], true)) $as = 'vendor';
if (is_post()) {
    csrf_check();
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $name = trim((string)($_POST['name'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email.';
    elseif ($name === '') $err = 'Enter your name.';
    elseif (mb_strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
    elseif (val('SELECT 1 FROM users WHERE email = ?', [$email])) $err = 'This email is already registered.';
    else {
        $role = $email === mb_strtolower(env('ADMIN_EMAIL', 'admin@basebase.ru')) ? 'admin' : $as;
        q('INSERT INTO users (email, name, password_hash, role, last_seen) VALUES (?,?,?,?,NOW())', [$email, $name, password_hash($pass, PASSWORD_DEFAULT), $role]);
        $uid = (int)db()->lastInsertId();
        if ($role !== 'customer') make_vendor($uid, trim((string)($_POST['shop'] ?? '')) ?: $name);
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        flash($role === 'admin' ? 'Welcome, Site Admin! You have full access.' : 'Account created. Welcome to basebase!');
        redirect($role === 'supplier' ? '/cabinet/products/new' : ($role === 'vendor' ? '/products' : '/cabinet'));
    }
}
layout('Register', function () use ($err, $as) { ?>
<div class="wrap"><div class="auth-card"><div class="card">
  <h2>Create account</h2><p class="muted">Choose how you want to use basebase.</p>
  <?php if ($err): ?><div class="flash bad" style="margin-bottom:14px"><?= e($err) ?></div><?php endif; ?>
  <form method="post"><?= csrf_field() ?>
    <div class="seg">
      <label><input type="radio" name="role" value="vendor" <?= $as === 'vendor' ? 'checked' : '' ?>><span>Reseller<small>collect &amp; earn</small></span></label>
      <label><input type="radio" name="role" value="supplier" <?= $as === 'supplier' ? 'checked' : '' ?>><span>Supplier<small>list products</small></span></label>
      <label><input type="radio" name="role" value="customer" <?= $as === 'customer' ? 'checked' : '' ?>><span>Customer<small>just buy</small></span></label>
    </div>
    <?= field('Your name', 'name', $_POST['name'] ?? '', 'text', 'required autocomplete="name"') ?>
    <?= field('Shop / blog name (optional)', 'shop', $_POST['shop'] ?? '') ?>
    <?= field('Email', 'email', $_POST['email'] ?? '', 'email', 'required autocomplete="username"') ?>
    <?= field('Password (min 8 characters)', 'password', '', 'password', 'required minlength="8" autocomplete="new-password"') ?>
    <button class="btn gold block lg">Create account</button>
  </form>
  <p class="muted center" style="margin:18px 0 0">Already registered? <a class="link" href="/login" style="color:var(--accent-d)">Sign in</a></p>
</div></div></div>
<?php });
