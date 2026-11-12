<?php
$u = require_role('admin');
q("INSERT IGNORE INTO agents (user_id) SELECT id FROM users WHERE role = 'agent'");
if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? ''; $id = (int)($_POST['id'] ?? 0);
    $total = platform_pct();
    if ($do === 'status' && in_array($_POST['status'] ?? '', ['active', 'suspended', 'pending'], true)) {
        $a = row('SELECT * FROM agents WHERE id = ?', [$id]);
        if ($a && ($_POST['status'] !== 'active' || $a['agent_key'])) {
            q('UPDATE agents SET status = ? WHERE id = ?', [$_POST['status'], $id]);
            if ($_POST['status'] === 'active' && !$a['api_secret']) q('UPDATE agents SET api_secret = ? WHERE id = ?', [bin2hex(random_bytes(24)), $id]);
            if ($_POST['status'] === 'active') notify((int)$a['user_id'], 'Your partner site is connected to Basebase.', '/cabinet/agent');
            flash('Agent status updated.');
        } else flash('This agent has not entered an AGENT_KEY yet.', 'warn');
    } elseif ($do === 'fee') {
        $f = round((float)str_replace(',', '.', (string)($_POST['fee'] ?? 0)), 2);
        if ($f < 0 || $f > $total) flash('Agency fee must be between 0% and the platform commission (' . $total . '%).', 'bad');
        else { q('UPDATE agents SET agency_fee = ? WHERE id = ?', [$f, $id]); flash('Agency fee set to ' . $f . '%. Basebase fee is ' . round($total - $f, 2) . '%.'); }
    } elseif ($do === 'assign') {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        $usr = row('SELECT id, role, owner_id FROM users WHERE email = ?', [$email]);
        if (!$usr) flash('No user with this email.', 'bad');
        elseif (in_array($usr['role'], ['admin', 'agent'], true) || $usr['owner_id']) flash('Only seller (Supplier / Reseller / Customer) accounts can be assigned to an agent.', 'bad');
        else { q('UPDATE users SET agent_id = ? WHERE id = ?', [$id, $usr['id']]); flash('Seller assigned to the agent. It applies to new orders only.'); }
    } elseif ($do === 'unassign') {
        q('UPDATE users SET agent_id = NULL WHERE id = ? AND agent_id = ?', [(int)($_POST['uid'] ?? 0), $id]); flash('Seller removed from the agent.');
    }
    redirect('/admin/agents');
}
$total = platform_pct();
$agents = rows('SELECT a.*, u.name, u.email, (SELECT COUNT(*) FROM users s WHERE s.agent_id = a.id) sellers,
    (SELECT COALESCE(SUM(amount),0) FROM wallet_tx w WHERE w.user_id = a.user_id AND w.ref LIKE "oi%:g") earned FROM agents a JOIN users u ON u.id = a.user_id ORDER BY a.id DESC');
$sellersBy = [];
foreach (rows('SELECT u.id, u.name, u.email, u.agent_id FROM users u WHERE u.agent_id IS NOT NULL ORDER BY u.id') as $s) $sellersBy[$s['agent_id']][] = $s;
$st = ['new' => 'mute', 'pending' => 'warn', 'active' => 'ok', 'suspended' => 'bad'];
layout('Agents manager', function () use ($agents, $total, $sellersBy, $st) { ?>
<div class="card-h"><div><h2 style="margin:0">Agents manager</h2><span class="muted"><?= count($agents) ?> agents · Platform commission <?= rtrim(rtrim(number_format($total, 2), '0'), '.') ?>% = Basebase fee + Agency fee</span></div></div>
<section class="card"><p class="muted sm" style="margin:0">Agents are users with the <b>Agent</b> account type who connect their Sysfera partner site with an AGENT_KEY. For every sale of their sellers the Agency fee (% of the margin) is taken <b>out of</b> the platform commission — the seller and blogger receive the same amount as without an agent. Basebase keeps the rest. Set the Agency fee for each agent below.</p></section>
<?php foreach ($agents as $a): $f = min((float)$a['agency_fee'], $total); ?>
<section class="card">
  <div class="card-h"><div><h3 style="margin:0"><?= e($a['name'] ?: $a['email']) ?> <span class="badge <?= $st[$a['status']] ?>"><?= e(ucfirst($a['status'])) ?></span></h3>
    <span class="muted sm"><?= e($a['email']) ?> · <?= $a['domain'] ? '<a class="link" target="_blank" rel="noopener" href="' . e($a['domain']) . '">' . e($a['domain']) . '</a>' : 'no site yet' ?> · key <?= $a['agent_key'] ? e(substr($a['agent_key'], 0, 6)) . '…' : '—' ?></span></div>
    <form method="post" style="display:flex;gap:6px;flex:none"><?= csrf_field() ?><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= $a['id'] ?>">
      <?php if ($a['status'] !== 'active'): ?><button name="status" value="active" class="btn sm dark">Approve</button><?php endif; ?>
      <?php if ($a['status'] === 'active'): ?><button name="status" value="suspended" class="btn sm danger">Suspend</button><?php endif; ?></form></div>
  <div class="row" style="gap:28px;align-items:flex-end">
    <form method="post" class="share-box" style="flex:none"><?= csrf_field() ?><input type="hidden" name="do" value="fee"><input type="hidden" name="id" value="<?= $a['id'] ?>">
      <label class="fld" style="margin:0"><span>Agency fee, % of margin</span><input name="fee" type="number" min="0" max="<?= e($total) ?>" step="0.5" value="<?= e($f) ?>" style="width:110px"></label><button class="btn sm dark" style="flex:none">Save</button></form>
    <div><span class="muted sm">Basebase fee</span><div><b><?= rtrim(rtrim(number_format($total - $f, 2), '0'), '.') ?>%</b></div></div>
    <div><span class="muted sm">Sellers</span><div><b><?= (int)$a['sellers'] ?></b></div></div>
    <div><span class="muted sm">Agency fee earned</span><div><b><?= money($a['earned']) ?></b></div></div>
  </div>
  <div style="margin-top:16px;border-top:1px solid var(--line);padding-top:12px">
    <b class="sm">Sellers of this agent</b>
    <?php foreach ($sellersBy[$a['id']] ?? [] as $s): ?><div class="row" style="flex-wrap:nowrap;justify-content:space-between;align-items:center;padding:6px 0"><span class="sm"><?= e($s['name'] ?: '—') ?> · <span class="muted"><?= e($s['email']) ?></span></span>
      <form method="post" data-confirm="Remove this seller from the agent?"><?= csrf_field() ?><input type="hidden" name="do" value="unassign"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="uid" value="<?= $s['id'] ?>"><button class="btn sm">Remove</button></form></div><?php endforeach; ?>
    <?php if (empty($sellersBy[$a['id']])): ?><p class="muted sm" style="margin:6px 0">No sellers yet. They are added automatically when a user registers through the agent's platform; you can also assign one by email:</p><?php endif; ?>
    <form method="post" class="share-box" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="assign"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="email" name="email" placeholder="Seller email" required><button class="btn sm" style="flex:none">Assign seller</button></form>
  </div>
</section>
<?php endforeach; ?>
<?php if (!$agents): ?><section class="card empty"><h3>No agents yet</h3><p>A user becomes an agent by choosing the <b>Agent</b> account type in Settings (or at registration), or you can set the role in Users manager.</p></section><?php endif; ?>
<?php }, ['cabinet' => true]);
