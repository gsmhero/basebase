<?php
/*
 * Embeddable shop widget: /embed/product/{id}  /embed/set/{id}  /embed/group/{vendor_id}?grp=Name   (+ ?shop={reseller id})
 * Placed in an <iframe> on any site. Guests buy right inside it — no session or cookies needed (stateless signed token).
 */
header_remove('X-Frame-Options');
header('Content-Security-Policy: frame-ancestors *');
header('Cache-Control: no-store');

$kind = $seg[1] ?? ''; $eid = (int)($seg[2] ?? 0);
$host = (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'www.basebase.ru');
$shop = (int)($_GET['shop'] ?? $_POST['shop'] ?? 0);
$grp = (string)($_GET['grp'] ?? $_POST['grp'] ?? '');

function emb_key(): string { return hash('sha256', 'embed|' . env('DB_PASS') . '|' . env('DB_NAME')); }
function emb_token(): string { $t = time(); return $t . '.' . hash_hmac('sha256', (string)$t, emb_key()); }
function emb_ok(string $tok): bool {
    $x = explode('.', $tok);
    return count($x) === 2 && ctype_digit($x[0]) && time() - (int)$x[0] < 21600 && hash_equals(hash_hmac('sha256', $x[0], emb_key()), $x[1]);
}

/* ---------- what is being embedded ---------- */
$items = []; $title = ''; $link = $host . '/'; $fixed = false; $vendorId = 0;
if ($kind === 'product') {
    $p = row('SELECT * FROM products WHERE id = ? AND status = "active"', [$eid]);
    if ($p) { $items[] = ['p' => $p, 'qty' => 1]; $title = $p['title']; $link = "$host/product/{$p['id']}"; $vendorId = (int)$p['vendor_id']; }
} elseif ($kind === 'set') {
    $set = row('SELECT s.*, v.shop_name FROM product_sets s JOIN vendors v ON v.id = s.vendor_id WHERE s.id = ?', [$eid]);
    if ($set) {
        foreach (rows('SELECT p.*, si.qty AS set_qty FROM set_items si JOIN products p ON p.id = si.product_id WHERE si.set_id = ? AND p.status = "active"', [$eid]) as $p) $items[] = ['p' => $p, 'qty' => (int)$p['set_qty']];
        $title = $set['title']; $link = "$host/set/{$set['id']}"; $fixed = true; $shop = (int)$set['vendor_id'];
    }
} elseif ($kind === 'group') {
    $vs = row('SELECT * FROM vendors WHERE id = ?', [$eid]);
    if ($vs && $grp !== '') {
        foreach (rows('SELECT p.* FROM collection_items ci JOIN products p ON p.id = ci.product_id WHERE ci.vendor_id = ? AND ci.grp = ? AND p.status = "active" ORDER BY ci.created_at DESC LIMIT 24', [$eid, $grp]) as $p) $items[] = ['p' => $p, 'qty' => 0];
        $title = $grp; $link = "$host/shop/{$vs['slug']}?group=" . urlencode($grp); $shop = $eid;
    }
}
if ($shop && !val('SELECT 1 FROM vendors WHERE id = ?', [$shop])) $shop = 0;
if ($shop && $kind === 'product') $link .= '?shop=' . $shop;

$err = ''; $done = null; $guestNew = false;

/* ---------- order ---------- */
if ($items && is_post()) {
    $f = array_map(fn($k) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, 190), ['cname', 'email', 'phone', 'address', 'delivery', 'notes']);
    [$cname, $email, $phone, $address, $delivery, $notes] = $f; $email = mb_strtolower($email);
    $pay = ($_POST['pay'] ?? 'cod') === 'test' ? 'test' : 'cod';
    $lines = [];
    foreach ($items as $it) {
        $p = $it['p']; $pid = (int)$p['id'];
        $qty = $fixed ? (int)$it['qty'] : max(0, min(999, (int)($_POST['qty'][$pid] ?? 0)));
        if ($qty < 1) continue;
        $variant = '';
        $vars = json_decode($p['variants'] ?? '[]', true) ?: [];
        if ($vars) {
            $variant = (string)($_POST['var'][$pid] ?? '');
            if (!in_array($variant, array_column($vars, 'name'), true)) $variant = (string)$vars[0]['name'];
        }
        $lines[] = ['pid' => $pid, 'qty' => $qty, 'variant' => $variant, 'unit' => eff_price($p, $variant), 'shop_id' => $shop, 'title' => $p['title']];
    }
    if (($_POST['website'] ?? '') !== '' || !emb_ok((string)($_POST['tok'] ?? ''))) $err = 'The form has expired. Please reload the widget and try again.';
    elseif (!$lines) $err = 'Choose at least one item.';
    elseif ($cname === '' || $phone === '' || $address === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Fill in name, a valid email, phone and delivery address.';
    elseif ($pay === 'test' && preg_replace('/\D+/', '', (string)($_POST['card'] ?? '')) !== '4242424242424242') $err = 'Test payment declined. Use the test card 4242 4242 4242 4242.';
    elseif ($email === mb_strtolower(env('ADMIN_EMAIL', 'admin@basebase.ru'))) $err = 'Please use another email address.';
    else {
        try {
            $ex = row('SELECT id, is_guest FROM users WHERE email = ?', [$email]);
            if ($ex) $cid = (int)$ex['id'];
            else {
                q("INSERT INTO users (email, name, phone, password_hash, role, is_guest, last_seen) VALUES (?,?,?,?,'customer',1,NOW())", [$email, $cname, $phone, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
                $cid = (int)db()->lastInsertId(); $guestNew = true;
            }
            $oid = place_order($cid, $lines, ['name' => $cname, 'phone' => $phone, 'address' => $address, 'delivery' => $delivery, 'notes' => $notes], $pay);
            $mailed = mail_order($oid);
            $done = ['oid' => $oid, 'mailed' => $mailed, 'pay' => $pay, 'total' => array_sum(array_map(fn($l) => $l['unit'] * $l['qty'], $lines))];
        } catch (RuntimeException $e) { $err = $e->getMessage(); }
    }
}
$tok = emb_token();
$posted = fn($k, $d = '') => e($_POST[$k] ?? $d);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?= e($title ?: 'basebase') ?> — basebase.ru</title>
<style>
:root{color-scheme:light}
*{box-sizing:border-box}
body{margin:0;background:transparent;color:#15171e;font:400 14px/1.45 system-ui,-apple-system,'Segoe UI',Roboto,sans-serif}
.w{background:#fff;border:1px solid #e8e5dc;border-radius:14px;overflow:hidden;max-width:720px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;padding:12px}
.it{display:flex;flex-direction:column;border:1px solid #e8e5dc;border-radius:12px;overflow:hidden;background:#fff}
.th{display:grid;place-items:center;aspect-ratio:1/1;background:#f0ede5;font:700 44px system-ui;color:#fff;overflow:hidden}
.th img{width:100%;height:100%;object-fit:cover}
.bd{padding:10px 12px 12px;display:flex;flex-direction:column;gap:4px;flex:1}
.bd b{font-size:14px;line-height:1.3}
.it b{display:block}.m{color:#6c707c;font-size:12.5px}
.pr{font-weight:700;white-space:nowrap}
select,input:not([type=radio]){font:inherit;border:1px solid #e0dcd0;border-radius:8px;padding:7px 9px;background:#fff;color:#15171e;width:100%}
.q{width:64px!important;text-align:center}
.ctl{display:flex;gap:6px;margin-top:auto;padding-top:6px;flex-wrap:wrap;align-items:center}.ctl select{width:auto;max-width:160px}
.bar{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 14px;flex-wrap:wrap}
.btn{border:0;border-radius:99px;background:#b8893a;color:#fff;font:700 14px system-ui;padding:11px 22px;cursor:pointer}
.btn:hover{background:#8a6420}.btn[disabled]{opacity:.45;cursor:default}
.tot{font:700 18px system-ui}
#co{padding:4px 14px 14px;border-top:1px solid #f0ede5}
.g2{display:grid;grid-template-columns:1fr 1fr;gap:10px}@media(max-width:520px){.g2{grid-template-columns:1fr}.grid{grid-template-columns:repeat(2,1fr);gap:8px;padding:8px}}
label.f{display:block;margin:10px 0 0}label.f span{display:block;font-size:12px;color:#6c707c;margin-bottom:3px}
.rd{display:flex;align-items:center;gap:8px;margin:6px 0}
.err{background:#fbe1e1;color:#8c2323;padding:10px 14px;font-weight:600}
.ok{padding:26px 18px;text-align:center}.ok h2{margin:0 0 6px;font-size:20px}
.ft{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 14px;background:#faf8f3;border-top:1px solid #f0ede5;font-size:12.5px;color:#6c707c}
.ft a{color:#15171e;font-weight:700;display:inline-flex;align-items:center;gap:7px}
.ft svg{height:18px;width:auto;display:block}
.hp{position:absolute;left:-9999px}
</style></head><body>
<div class="w" id="w">
<?php if (!$items): ?>
  <div class="ok"><h2>Not available</h2><p class="m">This product is no longer available.</p></div>
<?php elseif ($done): ?>
  <div class="ok"><h2>Thank you! Order BB-<?= 1000 + $done['oid'] ?> placed</h2>
    <p><?= $done['pay'] === 'test' ? 'Paid ' : 'To pay on delivery: ' ?><b><?= money($done['total']) ?></b><?= $done['pay'] === 'test' ? ' (test payment, no real money)' : '' ?>. The seller will contact you shortly.</p>
    <p class="m"><?= $done['mailed'] ? 'We sent the order details to <b>' . e($email) . '</b>' . ($guestNew ? ' with a link to set your password and track the order.' : '.') : 'We could not send the confirmation email, but your order is saved.' ?></p>
    <a class="btn" style="display:inline-block;text-decoration:none" href="<?= e($link) ?>" target="_blank" rel="noopener">More on basebase.ru</a></div>
<?php else: ?>
  <?php if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
  <form method="post" id="f" autocomplete="on">
    <input type="hidden" name="tok" value="<?= e($tok) ?>"><input type="hidden" name="shop" value="<?= $shop ?>"><input type="hidden" name="grp" value="<?= e($grp) ?>">
    <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
    <div class="grid">
    <?php foreach ($items as $it): $p = $it['p']; $pid = (int)$p['id']; $vars = json_decode($p['variants'] ?? '[]', true) ?: []; $qv = $_POST['qty'][$pid] ?? $it['qty']; ?>
    <div class="it" data-price="<?= eff_price($p) ?>">
      <a class="th" href="<?= e($host . '/product/' . $pid . ($shop ? '?shop=' . $shop : '')) ?>" target="_blank" rel="noopener" style="<?= $p['image'] ? '' : 'background:hsl(' . (($pid * 47) % 360) . ' 45% 55%)' ?>"><?= $p['image'] ? '<img src="' . e($host . $p['image']) . '" alt="' . e($p['title']) . '">' : e(mb_strtoupper(mb_substr(trim($p['title']), 0, 1))) ?></a>
      <div class="bd"><b><?= e($p['title']) ?></b>
        <div class="pr"><?= money(eff_price($p) * ($fixed ? $it['qty'] : 1)) ?></div>
        <div class="ctl">
          <?php if ($vars): ?><select name="var[<?= $pid ?>]" class="v"><?php foreach ($vars as $v): if ((int)$v['stock'] < 1) continue; ?><option value="<?= e($v['name']) ?>" data-price="<?= e(eff_price($p, $v['name'])) ?>" <?= ($_POST['var'][$pid] ?? '') === $v['name'] ? 'selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
          <?php if ($fixed): ?><span class="m">× <?= (int)$it['qty'] ?></span><?php else: ?><input class="q" type="number" name="qty[<?= $pid ?>]" min="0" max="999" value="<?= (int)$qv ?>" aria-label="Quantity"><?php endif; ?>
        </div></div>
    </div>
    <?php endforeach; ?>
    </div>
    <div class="bar"><span class="tot" id="tot"></span><button type="button" class="btn" id="buy">Buy now</button></div>
    <div id="co" <?= $err ? '' : 'hidden' ?>>
      <div class="g2"><label class="f"><span>Full name</span><input name="cname" required value="<?= $posted('cname') ?>" autocomplete="name"></label>
        <label class="f"><span>Email</span><input type="email" name="email" required value="<?= $posted('email') ?>" autocomplete="email"></label>
        <label class="f"><span>Phone</span><input type="tel" name="phone" required value="<?= $posted('phone') ?>" autocomplete="tel"></label>
        <label class="f"><span>Delivery</span><select name="delivery"><?php foreach (['CDEK', 'OZON Shipping', 'Russian Post', 'Pickup'] as $d): ?><option <?= ($_POST['delivery'] ?? '') === $d ? 'selected' : '' ?>><?= $d ?></option><?php endforeach; ?></select></label></div>
      <label class="f"><span>Delivery address</span><input name="address" required value="<?= $posted('address') ?>" autocomplete="street-address"></label>
      <label class="f"><span>Notes for the seller (optional)</span><input name="notes" value="<?= $posted('notes') ?>"></label>
      <div style="margin-top:10px"><span class="m">Payment</span>
        <label class="rd"><input type="radio" name="pay" value="cod" <?= ($_POST['pay'] ?? 'cod') !== 'test' ? 'checked' : '' ?>> On delivery</label>
        <label class="rd"><input type="radio" name="pay" value="test" <?= ($_POST['pay'] ?? '') === 'test' ? 'checked' : '' ?>> Test payment <span class="m">(no real money)</span></label>
        <div id="card" <?= ($_POST['pay'] ?? '') === 'test' ? '' : 'hidden' ?>><input name="card" value="<?= $posted('card', '4242 4242 4242 4242') ?>" inputmode="numeric" placeholder="Test card number"><div class="m" style="margin-top:4px">Test card 4242 4242 4242 4242 succeeds.</div></div></div>
      <div style="margin-top:14px"><button class="btn" style="width:100%" id="go">Place order</button></div>
    </div>
  </form>
<?php endif; ?>
  <div class="ft"><a href="<?= e($link) ?>" target="_blank" rel="noopener" title="Open on basebase.ru"><?= logo('') ?> basebase.ru</a><span>Buy right here · secure checkout</span></div>
</div>
<script>
(function(){
  var f=document.getElementById('f');
  function fmt(n){return n.toLocaleString('ru-RU',{maximumFractionDigits:2})+' ₽'}
  function calc(){
    if(!f)return;var s=0,n=0;
    f.querySelectorAll('.it').forEach(function(r){
      var q=r.querySelector('.q'),v=r.querySelector('select.v'),p=parseFloat(r.dataset.price);
      if(v&&v.selectedOptions[0])p=parseFloat(v.selectedOptions[0].dataset.price)||p;
      var c=q?parseInt(q.value||0,10):parseInt((r.querySelector('.m')||{}).textContent.replace(/\D/g,'')||1,10);
      if(c>0){s+=p*c;n+=c}
      if(!q){var pr=r.querySelector('.pr');if(pr)pr.textContent=fmt(p*c)}
    });
    document.getElementById('tot').textContent=n?fmt(s):'Choose quantity';
    document.getElementById('buy').disabled=!n;
  }
  if(f){
    f.addEventListener('input',calc);f.addEventListener('change',calc);calc();
    document.getElementById('buy').onclick=function(){var c=document.getElementById('co');c.hidden=!c.hidden;if(!c.hidden)c.querySelector('input').focus();send()};
    f.querySelectorAll('input[name=pay]').forEach(function(r){r.addEventListener('change',function(){document.getElementById('card').hidden=f.querySelector('input[name=pay]:checked').value!=='test';send()})});
  }
  function send(){try{parent.postMessage({bb:'h',h:document.getElementById('w').offsetHeight+4},'*')}catch(e){}}
  window.addEventListener('load',send);window.addEventListener('resize',send);
  if(window.ResizeObserver)new ResizeObserver(send).observe(document.getElementById('w'));
})();
</script>
</body></html>
