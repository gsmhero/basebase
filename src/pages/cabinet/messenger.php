<?php
$u = require_perm(require_login(), 'messages'); $me = (int)$u['id'];
$with = (int)($seg[2] ?? 0);
$peer = $with && $with !== $me ? row('SELECT id, name, email, role FROM users WHERE id = ?', [$with]) : null;
$pid = (int)($_GET['product'] ?? $_POST['product'] ?? 0);
$prod = $pid ? row('SELECT id, title FROM products WHERE id = ?', [$pid]) : null;

if (is_post() && $peer) {
    csrf_check();
    $body = trim((string)($_POST['body'] ?? ''));
    if ($body !== '') {
        q('INSERT INTO messages (from_id, to_id, product_id, body, sent_by) VALUES (?,?,?,?,?)', [$me, $peer['id'], $prod['id'] ?? null, mb_substr($body, 0, 4000), $u['sub_id']]);
        emit_for_user((int)$peer['id'], 'message.received', ['from_user_id' => $me, 'from_name' => $u['name'], 'body' => mb_substr($body, 0, 4000), 'product_id' => $prod['id'] ?? null]);
        notify((int)$peer['id'], 'New message from ' . (($u['sub'] ? $u['shop_name'] : $u['name']) ?: 'a user'), '/cabinet/messenger/' . $me);
    }
    redirect('/cabinet/messenger/' . $peer['id']);
}

$threads = rows('SELECT t.other, u.name, u.role, m.body, m.created_at, (SELECT COUNT(*) FROM messages x WHERE x.from_id = t.other AND x.to_id = ? AND x.is_read = 0) unread
   FROM (SELECT IF(from_id = ?, to_id, from_id) AS other, MAX(id) AS mid FROM messages WHERE from_id = ? OR to_id = ? GROUP BY other) t
   JOIN messages m ON m.id = t.mid JOIN users u ON u.id = t.other ORDER BY m.id DESC', [$me, $me, $me, $me]);
$conv = [];
if ($peer) {
    q('UPDATE messages SET is_read = 1 WHERE to_id = ? AND from_id = ?', [$me, $peer['id']]);
    $conv = rows('SELECT m.*, p.title, sb.name AS by_name FROM messages m LEFT JOIN products p ON p.id = m.product_id LEFT JOIN users sb ON sb.id = m.sent_by WHERE (from_id = ? AND to_id = ?) OR (from_id = ? AND to_id = ?) ORDER BY m.id ASC LIMIT 300', [$me, $peer['id'], $peer['id'], $me]);
    if (!array_filter($threads, fn($t) => (int)$t['other'] === (int)$peer['id'])) array_unshift($threads, ['other' => $peer['id'], 'name' => $peer['name'], 'role' => $peer['role'], 'body' => 'New conversation', 'created_at' => date('c'), 'unread' => 0]);
}

layout('Messenger', function () use ($threads, $peer, $conv, $me, $prod) { ?>
<div class="card-h"><h2 style="margin:0">Messenger</h2></div>
<div class="card chat">
  <div class="threads"><?php foreach ($threads as $t): ?>
    <a class="thread <?= $peer && (int)$peer['id'] === (int)$t['other'] ? 'on' : '' ?>" href="/cabinet/messenger/<?= $t['other'] ?>"><span class="av"><?= e(mb_strtoupper(mb_substr($t['name'] ?: '?', 0, 1))) ?></span><div style="min-width:0"><b><?= e($t['name'] ?: 'User') ?><?= $t['unread'] ? ' <span class="badge warn">' . $t['unread'] . '</span>' : '' ?></b><small><?= e(mb_strimwidth($t['body'], 0, 40, '…')) ?></small></div></a>
  <?php endforeach; if (!$threads) echo '<p class="muted" style="padding:20px">No conversations yet. Open a product and press “Ask seller”.</p>'; ?></div>
  <div class="conv">
  <?php if ($peer): ?>
    <div class="side-user" style="padding:14px 18px;margin:0"><span class="av"><?= e(mb_strtoupper(mb_substr($peer['name'] ?: '?', 0, 1))) ?></span><div><b><?= e($peer['name'] ?: 'User') ?></b><small><?= e(ucfirst($peer['role'])) ?></small></div></div>
    <div class="msgs"><?php foreach ($conv as $m): ?><div class="msg <?= (int)$m['from_id'] === $me ? 'me' : '' ?>"><?php if ($m['title']): ?><a class="sm" style="text-decoration:underline" href="/product/<?= $m['product_id'] ?>">re: <?= e($m['title']) ?></a><br><?php endif; ?><?= nl2br(e($m['body'])) ?><small><?= ago($m['created_at']) ?><?= $m['by_name'] ? ' · ' . e($m['by_name']) : '' ?></small></div><?php endforeach; if (!$conv) echo '<p class="muted center" style="margin:auto">Say hello 👋</p>'; ?></div>
    <form class="compose" method="post"><?= csrf_field() ?><?php if ($prod): ?><input type="hidden" name="product" value="<?= $prod['id'] ?>"><?php endif; ?>
      <input name="body" placeholder="<?= $prod ? 'About “' . e($prod['title']) . '”…' : 'Write a message…' ?>" autocomplete="off" required autofocus><button class="btn dark"><?= ico('arrow', 16) ?> Send</button></form>
  <?php else: ?><div class="empty" style="margin:auto"><h3>Select a conversation</h3></div><?php endif; ?>
  </div>
</div>
<?php }, ['cabinet' => true]);
