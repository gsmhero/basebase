<?php
$u = require_login(); if ($u['sub'] && !has_perm($u, 'orders') && !has_perm($u, 'messages')) deny();
if (is_post()) { csrf_check(); q('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [$u['id']]); redirect('/cabinet/notifications'); }
$list = rows('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 60', [$u['id']]);
q('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id <= ?', [$u['id'], $list[0]['id'] ?? 0]);
layout('Notifications', function () use ($list) { ?>
<div class="card-h"><h2 style="margin:0">Notifications</h2></div>
<section class="card"><?php foreach ($list as $n): ?>
  <a class="row" href="<?= e($n['url'] ?: '#') ?>" style="flex-wrap:nowrap;align-items:center;padding:12px 0;border-bottom:1px solid var(--line)"><span class="tool" style="flex:none;background:var(--accent-soft);color:var(--accent-d)"><?= ico('bell', 18) ?></span><span><?= e($n['body']) ?><br><span class="muted sm"><?= ago($n['created_at']) ?></span></span></a>
<?php endforeach; if (!$list) echo '<div class="empty"><h3>All quiet</h3><p>Order updates and messages will show up here.</p></div>'; ?></section>
<?php }, ['cabinet' => true]);
