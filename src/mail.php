<?php
declare(strict_types=1);

/* ---------- SMTP mailer (no dependencies; SSL on 465, STARTTLS otherwise) ---------- */
function site_url(): string {
    $u = rtrim(env('SITE_URL'), '/');
    if ($u !== '') return $u;
    return (!empty($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'www.basebase.ru');
}

function mail_log(string $msg): void { $GLOBALS['smtp_last'] = $msg; @file_put_contents(ROOT . '/storage/mail.log', date('c') . ' ' . $msg . "\n", FILE_APPEND); }

function smtp_send(string $to, string $subject, string $html, string $text = ''): bool {
    $host = env('SMTP_HOST'); $port = (int)env('SMTP_PORT', '465'); $user = env('SMTP_USER'); $pass = env('SMTP_PASS');
    $from = env('SMTP_FROM', $user);
    $to = str_replace(["\r", "\n", '<', '>'], '', $to);
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    if ($host === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { mail_log('SMTP_HOST is empty or recipient invalid'); return false; }
    // SMTP_FROM may be "Name <addr@x>", a bare address, or just a display name (then the login is the sender address)
    $addr = ''; $fromName = 'basebase.ru';
    if (preg_match('/^(.*)<([^>]+)>\s*$/', $from, $m) && filter_var(trim($m[2]), FILTER_VALIDATE_EMAIL)) { $addr = trim($m[2]); $n = trim($m[1], " \t\"'"); if ($n !== '') $fromName = $n; }
    elseif (filter_var(trim($from), FILTER_VALIDATE_EMAIL)) $addr = trim($from);
    else { if (trim($from) !== '') $fromName = trim($from); $addr = filter_var($user, FILTER_VALIDATE_EMAIL) ? $user : ''; }
    $addr = str_replace(["\r", "\n", '<', '>'], '', $addr); $fromName = str_replace(["\r", "\n"], ' ', $fromName);
    if ($addr === '') { mail_log('No sender address: set SMTP_FROM to an email, or SMTP_USER to a full email address'); return false; }
    $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $en, $es, 10);
    if (!$fp) { mail_log("connect failed: $es"); return false; }
    stream_set_timeout($fp, 12);
    $read = function () use ($fp): string {
        $out = '';
        while (($l = fgets($fp, 1024)) !== false) { $out .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return $out;
    };
    $cmd = function (string $c, string $expect) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n"); $r = $read();
        if (!str_starts_with($r, $expect)) throw new RuntimeException("SMTP: '" . (str_starts_with($c, 'AUTH') || strlen($c) > 60 ? 'cmd' : $c) . "' -> " . trim($r));
        return $r;
    };
    try {
        $g = $read(); if (!str_starts_with($g, '220')) throw new RuntimeException('SMTP greeting: ' . trim($g));
        $dom = parse_url(site_url(), PHP_URL_HOST) ?: 'basebase.ru';
        $ehlo = $cmd('EHLO ' . $dom, '250');
        if ($port !== 465 && stripos($ehlo, 'STARTTLS') !== false) {
            $cmd('STARTTLS', '220');
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS failed');
            $cmd('EHLO ' . $dom, '250');
        }
        if ($user !== '') { $cmd('AUTH LOGIN', '334'); $cmd(base64_encode($user), '334'); $cmd(base64_encode($pass), '235'); }
        $cmd('MAIL FROM:<' . $addr . '>', '250');
        $cmd('RCPT TO:<' . $to . '>', '25');
        $cmd('DATA', '354');
        $b = 'bb' . bin2hex(random_bytes(8));
        $text = $text !== '' ? $text : trim(html_entity_decode(strip_tags(preg_replace('~<(br|/p|/div|/tr|/h\d)>~i', "\n", $html))));
        $msg = 'From: =?UTF-8?B?' . base64_encode($fromName) . "?= <$addr>\r\n"
             . "To: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
             . 'Date: ' . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(10)) . "@$dom>\r\nMIME-Version: 1.0\r\n"
             . "Content-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n"
             . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
             . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n--$b--\r\n";
        fwrite($fp, $msg . "\r\n.\r\n");
        $r = $read(); if (!str_starts_with($r, '250')) throw new RuntimeException('SMTP data: ' . trim($r));
        @fwrite($fp, "QUIT\r\n"); fclose($fp);
        return true;
    } catch (Throwable $e) { mail_log($e->getMessage() . ' (to ' . $to . ')'); @fclose($fp); return false; }
}

/** Wraps content in the branded email shell. $cta = [label, url] optional. */
function mail_html(string $heading, string $bodyHtml, ?array $cta = null): string {
    $btn = $cta ? '<p style="margin:24px 0"><a href="' . e($cta[1]) . '" style="background:#b8893a;color:#fff;text-decoration:none;font-weight:700;padding:13px 26px;border-radius:99px;display:inline-block">' . e($cta[0]) . '</a></p><p style="color:#6c707c;font-size:12px;word-break:break-all">' . e($cta[1]) . '</p>' : '';
    return '<!doctype html><html><body style="margin:0;background:#f6f4ef;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#15171e"><div style="max-width:560px;margin:0 auto;padding:24px 16px">'
        . '<div style="font:700 20px system-ui;margin-bottom:14px">basebase.ru</div><div style="background:#fff;border:1px solid #e8e5dc;border-radius:14px;padding:24px">'
        . '<h2 style="margin:0 0 14px;font-size:21px">' . e($heading) . '</h2>' . $bodyHtml . $btn . '</div>'
        . '<p style="color:#6c707c;font-size:12px;text-align:center;margin-top:16px">Sent by <a href="' . e(site_url()) . '" style="color:#6c707c">basebase.ru</a></p></div></body></html>';
}

/* ---------- one-time links (set password / reset / track order) ---------- */
function make_login_link(int $uid, string $purpose, int $ttl): string {
    $raw = bin2hex(random_bytes(24));
    q('INSERT INTO login_tokens (user_id, token_hash, purpose, expires_at) VALUES (?,?,?,?)', [$uid, hash('sha256', $raw), $purpose, time() + $ttl]);
    return site_url() . '/reset/' . $raw;
}
function find_login_token(string $raw): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $raw)) return null;
    return row('SELECT * FROM login_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $raw), time()]) ?: null;
}

/* ---------- emails ---------- */
/** Order confirmation to the customer. Guest accounts get a one-time "set password & track order" link. */
function mail_order(int $oid): bool {
    try {
        $o = row('SELECT o.*, u.email, u.is_guest, u.id AS uid FROM orders o JOIN users u ON u.id = o.customer_id WHERE o.id = ?', [$oid]);
        if (!$o) return false;
        $items = rows('SELECT title, variant, qty, price FROM order_items WHERE order_id = ?', [$oid]);
        $no = 'BB-' . (1000 + $oid);
        $rows = ''; $sum = 0.0;
        foreach ($items as $i) { $sum += $i['price'] * $i['qty']; $rows .= '<tr><td style="padding:6px 0">' . e($i['title']) . ($i['variant'] ? ' <span style="color:#6c707c">· ' . e($i['variant']) . '</span>' : '') . ' × ' . (int)$i['qty'] . '</td><td style="padding:6px 0;text-align:right;white-space:nowrap">' . e(money($i['price'] * $i['qty'])) . '</td></tr>'; }
        $paid = $o['payment_status'] === 'paid';
        $body = '<p>Thank you for your order <b>' . $no . '</b>. ' . ($paid ? 'Paid ' . e(money($sum)) . ($o['payment_method'] === 'test' ? ' (test payment, no real money)' : '') . '.' : 'You pay ' . e(money($sum)) . ' on delivery.') . ' The seller will contact you shortly.</p>'
            . '<table style="width:100%;border-collapse:collapse;border-top:1px solid #e8e5dc;border-bottom:1px solid #e8e5dc">' . $rows . '<tr><td style="padding:8px 0"><b>Total</b></td><td style="padding:8px 0;text-align:right"><b>' . e(money($sum)) . '</b></td></tr></table>'
            . '<p style="color:#6c707c;margin-top:14px">Delivery: ' . e($o['delivery_method']) . ', ' . e($o['address']) . '</p>';
        if ($o['is_guest']) {
            $link = make_login_link((int)$o['uid'], 'access', 7 * 86400);
            $body .= '<p>We created an account for <b>' . e($o['email']) . '</b> so you can follow the order and message the seller. Set a password to sign in:</p>';
            $cta = ['Track order & set password', $link];
        } else $cta = ['View order', site_url() . '/cabinet/orders/' . $oid];
        return smtp_send($o['email'], "Order $no — thank you!", mail_html("Order $no placed", $body, $cta));
    } catch (Throwable $e) { mail_log('mail_order: ' . $e->getMessage()); return false; }
}

function mail_reset(int $uid): bool {
    $u = row('SELECT email, name FROM users WHERE id = ?', [$uid]);
    if (!$u) return false;
    $link = make_login_link($uid, 'reset', 3600);
    return smtp_send($u['email'], 'Reset your basebase password', mail_html('Reset your password', '<p>Hi' . ($u['name'] ? ', ' . e($u['name']) : '') . '! Use the button below to choose a new password. The link works once and expires in 1 hour. If you did not ask for it, ignore this email.</p>', ['Choose a new password', $link]));
}

function mail_invite(string $email, string $shop, string $token): bool {
    $link = site_url() . '/invite/' . $token;
    return smtp_send($email, "You're invited to manage $shop on basebase.ru", mail_html('Join ' . $shop, '<p>You have been invited to help manage <b>' . e($shop) . '</b> on basebase.ru — products, orders, messages or blog, depending on the permissions you were given.</p>', ['Accept invite', $link]));
}

/** Welcome email for a seller account created from a partner site: link to set the password. */
function mail_managed_seller(int $uid, string $domain): bool {
    $u = row('SELECT email, name FROM users WHERE id = ?', [$uid]);
    if (!$u) return false;
    $link = make_login_link($uid, 'access', 14 * 86400);
    $host = parse_url($domain, PHP_URL_HOST) ?: 'a partner site';
    return smtp_send($u['email'], 'Your basebase.ru seller account', mail_html('Your seller account is ready', '<p>Hi' . ($u['name'] ? ', ' . e($u['name']) : '') . '! Your products from <b>' . e($host) . '</b> are being published on <b>basebase.ru</b>. Orders, messages and your wallet are managed from there, and you can also sign in on basebase.ru. Set a password to sign in:</p>', ['Set password', $link]));
}
