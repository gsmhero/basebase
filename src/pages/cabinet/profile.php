<?php
$u = owner_only(require_role('vendor', 'supplier', 'admin'));
$vid = (int)$u['vid']; $uid = (int)$u['id'];
$v = row('SELECT * FROM vendors WHERE id = ?', [$vid]);
$soc = json_decode($v['socials'] ?? '{}', true) ?: [];
$personal = json_decode($v['personal'] ?? '{}', true) ?: [];
$base = ['VK' => 1, 'OK' => 1, 'Dzen' => 1, 'YouTube' => 1, 'VK Video' => 1];

// Payout data fields per user type (database/profile-fields.csv)
$types = require ROOT . '/src/profile_fields.php';
$tabLabel = ['self' => 'Самозанятый / ИП', 'company' => 'Компания', 'personal' => 'Физлицо'];
$ptype = isset($types[$v['payout_type']]) ? $v['payout_type'] : 'self';

function missing_required(array $type, array $data): array {
    $miss = [];
    foreach ($type['fields'] as $f) if ($f['required'] && trim((string)($data['f' . $f['id']] ?? '')) === '') $miss[] = $f['label'];
    return $miss;
}

if (is_post()) {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'profile') {
        $s = [];
        foreach ((array)($_POST['net'] ?? []) as $i => $name) {
            $name = mb_substr(trim((string)$name), 0, 40);
            if ($name === '') continue;
            $url = trim((string)($_POST['url'][$i] ?? ''));
            if ($url !== '' && !preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
            $s[$name] = ['url' => filter_var($url, FILTER_VALIDATE_URL) ? $url : '', 'followers' => max(0, (int)($_POST['fol'][$i] ?? 0))];
        }
        if (!empty($_POST['new_net'])) $s[mb_substr(trim((string)$_POST['new_net']), 0, 40)] = ['url' => '', 'followers' => 0];
        $avatar = $v['avatar'];
        q('UPDATE vendors SET blogging_type = ?, bio = ?, socials = ?, shop_name = ?, avatar = ? WHERE id = ?', [mb_substr(trim((string)($_POST['blogging_type'] ?? '')), 0, 190), mb_substr(trim((string)($_POST['bio'] ?? '')), 0, 2000), json_encode($s, JSON_UNESCAPED_UNICODE), trim((string)($_POST['shop_name'] ?? '')) ?: $v['shop_name'], $avatar, $vid]);
        flash('Public profile saved.');
    } elseif ($do === 'personal') {
        $t = $_POST['ptype'] ?? 'self';
        if (isset($types[$t])) {
            $data = [];
            foreach ($types[$t]['fields'] as $f) {
                $val = mb_substr(trim((string)($_POST['p'][$t]['f' . $f['id']] ?? '')), 0, 190);
                if ($f['type'] === 'date') { $d = DateTime::createFromFormat('Y-m-d', $val); $val = ($d && $d->format('Y-m-d') === $val) ? $val : ''; }
                elseif ($f['type'] === 'number') $val = preg_replace('/\D+/', '', $val);
                $data['f' . $f['id']] = $val;
            }
            $personal[$t] = $data;
            q('UPDATE vendors SET payout_type = ?, personal = ? WHERE id = ?', [$t, json_encode($personal, JSON_UNESCAPED_UNICODE), $vid]);
            $miss = missing_required($types[$t], $data);
            if ($miss) flash('Saved. ' . count($miss) . ' required field(s) are still empty — they are needed before you can withdraw money.', 'warn');
            else flash('Personal data saved. You can now withdraw money.');
        }
    } elseif ($do === 'withdraw') {
        $amt = round((float)str_replace(',', '.', (string)($_POST['amount'] ?? 0)), 2);
        $bal = wallet_balance($uid);
        $miss = missing_required($types[$ptype], $personal[$ptype] ?? []);
        if ($miss) flash('Complete the required fields in Personal data first (' . count($miss) . ' missing, e.g. “' . $miss[0] . '”).', 'warn');
        elseif ($amt < 100) flash('Minimum withdrawal is 100 ₽.', 'bad');
        elseif ($amt > $bal) flash('Not enough funds on the wallet.', 'bad');
        else { q("INSERT INTO wallet_tx (user_id, amount, type, status, note) VALUES (?,?,'withdraw','pending',?)", [$uid, -$amt, 'Withdrawal request (' . $tabLabel[$ptype] . ')']); flash('Withdrawal requested. We will process it shortly.'); }
    }
    redirect('/cabinet/profile');
}

$bal = wallet_balance($uid);
$tx = rows('SELECT * FROM wallet_tx WHERE user_id = ? ORDER BY id DESC LIMIT 10', [$uid]);
$nets = $soc + $base; foreach ($base as $k => $_) $nets[$k] = $soc[$k] ?? ['url' => '', 'followers' => 0];
$total = 0; foreach ($nets as $n) $total += (int)($n['followers'] ?? 0);
$progress = [];
foreach ($types as $k => $t) { $req = array_filter($t['fields'], fn($f) => $f['required']); $progress[$k] = [count($req) - count(missing_required($t, $personal[$k] ?? [])), count($req)]; }

layout('Profile & wallet', function () use ($v, $bal, $tx, $nets, $total, $types, $personal, $tabLabel, $ptype, $progress) { ?>
<div class="card-h"><h2 style="margin:0">Profile &amp; wallet</h2><a class="btn sm" href="/shop/<?= e($v['slug']) ?>"><?= ico('eye', 14) ?> View public page</a></div>
<div class="dash-grid">
  <div class="span-5" style="display:grid;gap:22px;align-content:start">
    <section class="card"><h3>Wallet</h3><div class="wallet-n"><?= money($bal) ?></div><p class="muted sm">Payout as: <b><?= e($tabLabel[$ptype]) ?></b> · <?= $progress[$ptype][0] ?>/<?= $progress[$ptype][1] ?> required fields filled</p>
      <form method="post" class="share-box"><?= csrf_field() ?><input type="hidden" name="do" value="withdraw"><input name="amount" type="number" min="100" step="0.01" placeholder="Amount, ₽" required><button class="btn dark" style="flex:none">Withdraw money</button></form>
      <?php if ($tx): ?><div style="margin-top:16px"><?php foreach ($tx as $t): ?><div class="row" style="flex-wrap:nowrap;justify-content:space-between;padding:7px 0;border-top:1px solid var(--line)"><span class="sm"><?= e(mb_strimwidth((string)$t['note'], 0, 28, '…')) ?> <?= $t['status'] !== 'done' ? badge($t['status']) : '' ?></span><b class="sm" style="flex:none;color:var(--<?= $t['amount'] >= 0 ? 'ok' : 'bad' ?>)"><?= ($t['amount'] >= 0 ? '+' : '') . money($t['amount']) ?></b></div><?php endforeach; ?></div><?php endif; ?></section>

    <form class="card" method="post"><?= csrf_field() ?><input type="hidden" name="do" value="profile"><div class="card-h"><h3>Public profile</h3><button class="btn sm dark">Save</button></div>
      <?= field('Shop / blog name', 'shop_name', $v['shop_name']) ?>
      <?= field('Blogging type', 'blogging_type', $v['blogging_type'] ?? '', 'text', 'placeholder="Beauty, tech, lifestyle…"') ?>
      <label class="fld"><span>About</span><textarea name="bio" rows="3"><?= e($v['bio'] ?? '') ?></textarea></label>
      <div class="soc-row" style="margin:6px 0 8px"><h4 style="margin:0;grid-column:1/3">Social networks</h4><span class="muted sm" style="font-weight:600;padding-left:2px">Followers</span></div>
      <?php foreach ($nets as $name => $n): ?><div class="soc-row"><input type="hidden" name="net[]" value="<?= e($name) ?>"><b><?= e($name) ?></b><input name="url[]" value="<?= e($n['url'] ?? '') ?>" placeholder="Profile URL"><input name="fol[]" type="number" min="0" value="<?= e($n['followers'] ?? 0) ?>" placeholder="Followers"></div><?php endforeach; ?>
      <div class="row" style="justify-content:space-between;padding:6px 0 14px;border-top:1px solid var(--line);flex-wrap:nowrap"><span class="muted">Total followers</span><b><?= num($total) ?></b></div>
      <div class="share-box"><input name="new_net" placeholder="Network name"><button class="btn sm" style="flex:none">Add network</button></div>
    </form>
  </div>

  <section class="card span-7" data-tabs="<?= e($ptype) ?>">
    <div class="card-h"><h3>Personal data</h3><span class="muted sm">Used for payouts only · never shown publicly</span></div>
    <div class="tabs"><?php foreach ($types as $k => $t): ?><button type="button" data-tab="<?= $k ?>"><?= e($tabLabel[$k]) ?> <span class="muted sm"><?= $progress[$k][0] ?>/<?= $progress[$k][1] ?></span></button><?php endforeach; ?></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="personal"><input type="hidden" name="ptype" value="<?= e($ptype) ?>" data-tabval>
      <?php foreach ($types as $k => $t): ?><div data-pane="<?= $k ?>" hidden>
        <p class="muted sm" style="margin-top:0"><?= e($t['section']) ?> · <span style="color:var(--bad)">*</span> required to withdraw money</p>
        <div class="grid2"><?php foreach ($t['fields'] as $f): $val = $personal[$k]['f' . $f['id']] ?? ''; $lab = $f['label'] . ($f['required'] ? ' *' : ''); ?>
          <?= field($lab, "p[$k][f{$f['id']}]", $val, $f['type'] === 'date' ? 'date' : 'text', $f['type'] === 'number' ? 'inputmode="numeric" pattern="[0-9]*"' : 'maxlength="190"') ?>
        <?php endforeach; ?></div></div><?php endforeach; ?>
      <button class="btn dark">Save &amp; use for payouts</button>
      <p class="muted sm" style="margin:12px 0 0">The tab you save from becomes your payout type.</p>
    </form>
  </section>
</div>
<?php }, ['cabinet' => true]);
