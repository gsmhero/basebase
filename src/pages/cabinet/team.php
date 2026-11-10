<?php
$u = owner_only(require_role('vendor', 'supplier', 'admin'));
$oid = (int)$u['id'];
$host = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'www.basebase.ru');
$pickPerms = fn() => implode(',', array_values(array_intersect(array_keys(PERMS), (array)($_POST['perms'] ?? []))));

if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    if ($do === 'invite') {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? ''))); $perms = $pickPerms();
        $count = (int)val('SELECT COUNT(*) FROM users WHERE owner_id = ?', [$oid]) + (int)val('SELECT COUNT(*) FROM invites WHERE owner_id = ? AND used_at IS NULL', [$oid]);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) flash('Enter a valid email.', 'bad');
        elseif ($perms === '') flash('Choose at least one permission.', 'bad');
        elseif ($count >= 10) flash('A shop can have up to 10 team members and pending invites.', 'warn');
        elseif (val('SELECT 1 FROM users WHERE email = ?', [$email])) flash('This email already has an account. Staff need a new email address.', 'bad');
        elseif (val('SELECT 1 FROM invites WHERE email = ? AND used_at IS NULL', [$email])) flash('This email already has a pending invite.', 'warn');
        else {
            q('INSERT INTO invites (owner_id, email, name, perms, token) VALUES (?,?,?,?,?)', [$oid, $email, mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120), $perms, bin2hex(random_bytes(24))]);
            flash('Invite created — copy the link below and send it to ' . $email . '.');
        }
    } elseif ($do === 'revoke') { q('DELETE FROM invites WHERE id = ? AND owner_id = ? AND used_at IS NULL', [$id, $oid]); flash('Invite revoked.'); }
    elseif ($do === 'perms') {
        $perms = $pickPerms();
        if ($perms === '') flash('Choose at least one permission, or remove the member.', 'bad');
        else { q('UPDATE users SET perms = ?, name = ? WHERE id = ? AND owner_id = ?', [$perms, mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120), $id, $oid]); flash('Team member updated.'); }
    } elseif ($do === 'password') {
        if (mb_strlen((string)($_POST['new'] ?? '')) < 8) flash('Password must be at least 8 characters.', 'bad');
        else { q('UPDATE users SET password_hash = ? WHERE id = ? AND owner_id = ?', [password_hash((string)$_POST['new'], PASSWORD_DEFAULT), $id, $oid]); flash('Password changed.'); }
    } elseif ($do === 'remove') { q('DELETE FROM users WHERE id = ? AND owner_id = ?', [$id, $oid]); flash('Team member removed.'); }
    redirect('/cabinet/team');
}

$team = rows('SELECT id, name, email, perms, last_seen FROM users WHERE owner_id = ? ORDER BY id', [$oid]);
$invites = rows('SELECT * FROM invites WHERE owner_id = ? AND used_at IS NULL ORDER BY id DESC', [$oid]);

layout('Team', function () use ($team, $invites, $host, $u) { ?>
<div class="card-h"><div><h2 style="margin:0">Team</h2><span class="muted">Staff manage <b><?= e($u['shop_name']) ?></b> without a wallet of their own.</span></div></div>
<div class="dash-grid">
  <form class="card span-5" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="invite"><h3>Invite a team member</h3>
    <?= field('Name (optional)', 'name', '') ?><?= field('Email', 'email', '', 'email', 'required') ?>
    <div class="fld"><span>Can manage</span><?php foreach (PERMS as $k => $l): ?><label style="display:flex;gap:10px;align-items:center;padding:5px 0"><input type="checkbox" name="perms[]" value="<?= $k ?>" checked> <?= e($l) ?></label><?php endforeach; ?></div>
    <button class="btn gold block">Create invite link</button>
    <p class="muted sm" style="margin:12px 0 0">Staff work under your shop name. They never see your wallet, statistics, payout data or settings.</p></form>

  <div class="span-7" style="display:grid;gap:22px;align-content:start">
    <?php if ($invites): ?><section class="card"><h3>Pending invites</h3>
      <?php foreach ($invites as $i): ?><div class="row" style="flex-wrap:nowrap;align-items:center;padding:12px 0;border-top:1px solid var(--line)"><div style="min-width:0"><b><?= e($i['email']) ?></b><div class="muted sm"><?= e(implode(', ', array_map(fn($p) => explode(' ', PERMS[$p] ?? $p)[0], explode(',', $i['perms'])))) ?> · <?= ago($i['created_at']) ?></div></div>
        <div style="flex:none;display:flex;gap:6px"><button type="button" class="btn sm" data-copy="<?= e($host . '/invite/' . $i['token']) ?>"><?= ico('link', 14) ?> Copy link</button>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="revoke"><input type="hidden" name="id" value="<?= $i['id'] ?>"><button class="icon-btn" title="Revoke"><?= ico('x', 16) ?></button></form></div></div><?php endforeach; ?></section><?php endif; ?>

    <?php foreach ($team as $m): $has = explode(',', $m['perms']); ?>
    <section class="card"><div class="card-h"><div class="row" style="flex-wrap:nowrap;align-items:center;gap:12px"><span class="av"><?= e(mb_strtoupper(mb_substr($m['name'] ?: $m['email'], 0, 1))) ?></span><div style="flex:none"><b><?= e($m['name'] ?: 'Staff') ?></b><div class="muted sm"><?= e($m['email']) ?> · <?= $m['last_seen'] ? 'seen ' . ago($m['last_seen']) : 'never signed in' ?></div></div></div></div>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="perms"><input type="hidden" name="id" value="<?= $m['id'] ?>">
        <div class="grid2"><?= field('Display name', 'name', $m['name']) ?><div class="fld"><span>Permissions</span><?php foreach (PERMS as $k => $l): ?><label style="display:flex;gap:8px;align-items:center;padding:3px 0;font-size:14px"><input type="checkbox" name="perms[]" value="<?= $k ?>" <?= in_array($k, $has, true) ? 'checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?></div></div>
        <button class="btn sm dark">Save</button></form>
      <div class="row" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--line);flex-wrap:nowrap;align-items:center">
        <form method="post" class="share-box" style="flex:1"><?= csrf_field() ?><input type="hidden" name="do" value="password"><input type="hidden" name="id" value="<?= $m['id'] ?>"><input type="password" name="new" minlength="8" placeholder="Set a new password" autocomplete="new-password"><button class="btn sm" style="flex:none">Set</button></form>
        <form method="post" data-confirm="Remove this team member?" style="flex:none"><?= csrf_field() ?><input type="hidden" name="do" value="remove"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn sm danger"><?= ico('trash', 14) ?> Remove</button></form></div></section>
    <?php endforeach; ?>
    <?php if (!$team && !$invites): ?><section class="card empty"><h3>No team members yet</h3><p>Invite a colleague to help with products, orders, messages and your blog.</p></section><?php endif; ?>
  </div>
</div>
<?php }, ['cabinet' => true]);
