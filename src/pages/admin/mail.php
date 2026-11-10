<?php
$u = require_role('admin');
$res = null;
if (is_post()) {
    csrf_check();
    $to = mb_strtolower(trim((string)($_POST['to'] ?? '')));
    $GLOBALS['smtp_last'] = '';
    $ok = filter_var($to, FILTER_VALIDATE_EMAIL) ? smtp_send($to, 'basebase.ru test email', mail_html('SMTP works', '<p>This is a test email from basebase.ru. If you can read it, order confirmations and password resets will be delivered.</p>')) : false;
    $res = [$ok, $ok ? 'Accepted by the SMTP server for ' . $to . '. If it is not in the inbox, check Spam and the sender domain (SPF/DKIM).' : ($GLOBALS['smtp_last'] ?: 'Invalid email address.')];
}
$log = is_file(ROOT . '/storage/mail.log') ? array_slice(file(ROOT . '/storage/mail.log', FILE_IGNORE_NEW_LINES), -25) : [];
layout('Mail diagnostics', function () use ($res, $log) { ?>
<div class="wrap" style="padding:24px 0;max-width:820px">
  <h1 style="font-size:28px">Mail diagnostics</h1>
  <section class="card"><h3>Configuration</h3>
    <p class="sm" style="margin:0">Host: <b><?= e(env('SMTP_HOST') ?: '— not set —') ?></b> · Port: <b><?= e(env('SMTP_PORT') ?: '— not set (465) —') ?></b> · User: <b><?= e(env('SMTP_USER') ?: '— not set —') ?></b> · Password: <b><?= env('SMTP_PASS') !== '' ? 'set (' . strlen(env('SMTP_PASS')) . ' chars)' : '— not set —' ?></b> · From: <b><?= e(env('SMTP_FROM') ?: '— not set —') ?></b></p></section>
  <section class="card" style="margin-top:16px"><h3>Send a test email</h3>
    <?php if ($res): ?><div class="flash <?= $res[0] ? 'ok' : 'bad' ?>" style="margin-bottom:12px"><?= e($res[1]) ?></div><?php endif; ?>
    <form method="post" class="share-box"><?= csrf_field() ?><input type="email" name="to" placeholder="your@email" required><button class="btn dark" style="flex:none">Send test</button></form></section>
  <section class="card" style="margin-top:16px"><h3>Recent mail errors</h3>
    <?php if ($log): ?><pre class="sm" style="white-space:pre-wrap;margin:0"><?= e(implode("\n", $log)) ?></pre><?php else: ?><p class="muted sm" style="margin:0">No errors logged.</p><?php endif; ?></section>
</div>
<?php });
