<?php
$u = owner_only(require_role('agent'));
$uid = (int)$u['id'];
q('INSERT IGNORE INTO agents (user_id) VALUES (?)', [$uid]);
$ag = row('SELECT * FROM agents WHERE user_id = ?', [$uid]);

if (is_post() && ($_POST['do'] ?? '') === 'secret' && $ag['status'] === 'active') {
    csrf_check();
    q('UPDATE agents SET api_secret = ? WHERE id = ?', [bin2hex(random_bytes(24)), $ag['id']]);
    flash('New API secret generated. Update it on your partner site — the old one no longer works.');
    redirect('/cabinet/agent');
}
if (is_post()) {
    csrf_check();
    $key = trim((string)($_POST['agent_key'] ?? ''));
    $dom = mb_strtolower(trim((string)($_POST['domain'] ?? '')));
    if ($dom !== '' && !preg_match('~^https?://~', $dom)) $dom = 'https://' . $dom;
    $host = (string)parse_url($dom, PHP_URL_HOST);
    if (!preg_match('/^[A-Za-z0-9_-]{16,80}$/', $key)) flash('The AGENT_KEY looks wrong. Copy it exactly from your Sysfera site: Basebase → Connection → AGENT_KEY (16–80 letters, digits, - or _).', 'bad');
    elseif ($host === '' || !str_contains($host, '.')) flash('Enter the address of your partner site, for example https://shop.example.com', 'bad');
    elseif (val('SELECT 1 FROM agents WHERE agent_key = ? AND id <> ?', [$key, $ag['id']])) flash('This AGENT_KEY is already connected to another account.', 'bad');
    else {
        // Agents are approved automatically; only an administrator can suspend one, and a suspended agent stays suspended.
        $same = $ag['agent_key'] === $key && $ag['status'] === 'active';
        $new = $ag['status'] === 'suspended' ? 'suspended' : 'active';
        q('UPDATE agents SET agent_key = ?, domain = ?, status = ? WHERE id = ?', [$key, rtrim($dom, '/'), $new, $ag['id']]);
        if ($new === 'active' && !$ag['api_secret']) q('UPDATE agents SET api_secret = ? WHERE id = ?', [bin2hex(random_bytes(24)), $ag['id']]);
        if ($new === 'suspended') flash('Saved, but your account is suspended by Basebase. Contact support.', 'bad');
        elseif (!$same) {
            notify(admin_user_id(), 'New agent connected automatically: ' . ($u['name'] ?: $u['email']) . ' (' . $host . ') — you can suspend it in Agents.', '/admin/agents');
            flash('Connected. Your partner site is active — copy the API secret below into your Sysfera site: Basebase → Connection.');
        } else flash('Saved.');
    }
    redirect('/cabinet/agent');
}

$total = platform_pct();
$fee = min((float)$ag['agency_fee'], $total);
$sellers = rows('SELECT u.id, u.name, u.email, v.shop_name, (SELECT COUNT(*) FROM products p WHERE p.vendor_id = v.id) pc,
    (SELECT COUNT(*) FROM order_items oi WHERE oi.vendor_id = v.id) sales FROM users u LEFT JOIN vendors v ON v.user_id = u.id WHERE u.agent_id = ? ORDER BY u.id DESC', [$ag['id']]);
$earned = (float)val("SELECT COALESCE(SUM(amount),0) FROM wallet_tx WHERE user_id = ? AND ref LIKE 'oi%:g'", [$uid]);
$st = ['new' => ['Not connected', 'mute'], 'pending' => ['Waiting for confirmation', 'warn'], 'active' => ['Connected', 'ok'], 'suspended' => ['Suspended', 'bad']][$ag['status']];
webhooks_flush(3);
layout('Partner site', function () use ($ag, $total, $fee, $sellers, $earned, $st) { ?>
<div class="card-h"><div><h2 style="margin:0">Partner site</h2><span class="muted">Connect your Sysfera platform and earn an Agency fee on your sellers' sales.</span></div><span class="badge <?= $st[1] ?>"><?= $st[0] ?></span></div>
<div class="dash-grid">
  <form class="card span-12" method="post"><?= csrf_field() ?><h3>1. Connect your platform</h3>
    <p class="muted sm" style="margin-top:0">Install Sysfera Platform on your own domain and add the <b>Warehouse Manager</b> and <b>Sysfera WMS → Basebase</b> components. Then, on your Sysfera site, open <b>Basebase → Connection</b> in the left menu: the <b>AGENT_KEY</b> is shown there in the first block (“0. AGENT_KEY”). Copy it and paste it here together with the address of your site.</p>
    <div class="grid2"><?= field('Partner site address', 'domain', $ag['domain'], 'text', 'placeholder="https://shop.your-domain.ru" required') ?><?= field('AGENT_KEY', 'agent_key', $ag['agent_key'] ?? '', 'text', 'placeholder="Paste the key from Basebase → Connection" required autocomplete="off"') ?></div>
    <button class="btn dark">Save connection</button>
    <?php if ($ag['status'] === 'pending'): ?><p class="muted sm" style="margin:12px 0 0">Waiting for Basebase to confirm the connection. You will get a notification.</p><?php endif; ?></form>

  <section class="card span-12"><h3>2. Your Agency fee</h3>
    <p style="margin:0 0 8px">Your Agency fee is <b><?= rtrim(rtrim(number_format($fee, 2), '0'), '.') ?>%</b> of the margin (price − promo price) on every sale of your sellers.</p>
    <p class="muted sm" style="margin:0">It is part of the platform commission (<?= rtrim(rtrim(number_format($total, 2), '0'), '.') ?>%), shared with Basebase — sellers and bloggers never pay more. Basebase keeps <?= rtrim(rtrim(number_format(max(0, $total - $fee), 2), '0'), '.') ?>%. Money arrives in your wallet when the order item is marked as Delivered.</p>
    <div class="kpis" style="margin-top:16px"><div class="kpi"><small>Your sellers</small><b><?= count($sellers) ?></b></div><div class="kpi gold"><small>Agency fee earned</small><b><?= money($earned) ?></b></div></div></section>

  <section class="card span-12"><h3>3. API access</h3>
    <?php if ($ag['status'] === 'active' && $ag['api_secret']): ?>
      <p class="muted sm" style="margin-top:0">Enter these three values on your Sysfera site: Basebase → Connection, block “Access to Basebase”. Keep the secret private: it signs every request between your site and Basebase.</p>
      <div class="grid2"><label class="fld"><span>Agent ID</span><input readonly value="<?= (int)$ag['id'] ?>"></label><label class="fld"><span>API address</span><input readonly value="<?= e(site_url() . '/api/v1') ?>"></label></div>
      <div class="share-box"><input readonly type="password" value="<?= e($ag['api_secret']) ?>" id="apisec"><button type="button" class="btn sm" style="flex:none" data-copy="<?= e($ag['api_secret']) ?>">Copy secret</button>
        <form method="post" data-confirm="Generate a new secret? The old one stops working immediately." style="flex:none"><?= csrf_field() ?><input type="hidden" name="do" value="secret"><button class="btn sm danger">Regenerate</button></form></div>
    <?php else: ?><p class="muted" style="margin:0">Available after Basebase confirms your connection.</p><?php endif; ?></section>

  <section class="card span-12"><h3>4. Sellers from your platform</h3>
    <?php if ($sellers): ?><table class="tbl"><thead><tr><th>Seller</th><th>Shop</th><th class="num">Products</th><th class="num">Sold items</th></tr></thead><tbody>
      <?php foreach ($sellers as $s): ?><tr><td><b><?= e($s['name'] ?: '—') ?></b><div class="muted sm"><?= e($s['email']) ?></div></td><td><?= e($s['shop_name'] ?: '—') ?></td><td class="num"><?= (int)$s['pc'] ?></td><td class="num"><?= (int)$s['sales'] ?></td></tr><?php endforeach; ?></tbody></table>
    <?php else: ?><p class="muted" style="margin:0">No sellers yet. Users who register on your platform and export products to Basebase will appear here.</p><?php endif; ?></section>
</div>
<?php }, ['cabinet' => true]);
