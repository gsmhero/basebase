<?php
declare(strict_types=1);

/* Money movement shared by the cabinet and the partner API. */

const ITEM_STATUSES = ['pending' => 'Pending', 'prepare' => 'Prepare', 'shipped' => 'Shipped', 'on_delivery' => 'On delivery', 'delivered' => 'Delivered', 'archived' => 'Archived', 'returned' => 'Returned'];

/** Credits (or, on return, reverses) the wallets of everybody who earns on an order item: seller, blogger, agent, platform. */
function settle_item(array $it, bool $on): void {
    $s = (int)val('SELECT user_id FROM vendors WHERE id = ?', [$it['vendor_id']]);
    $net = round($it['price'] * $it['qty'] - $it['commission'] - $it['reseller_fee'], 2);
    $legs = [['oi' . $it['id'] . ':s', $s, $net, 'sale', 'Sale: ' . $it['title']]];
    if ($it['reseller_id']) $legs[] = ['oi' . $it['id'] . ':r', (int)val('SELECT user_id FROM vendors WHERE id = ?', [$it['reseller_id']]), (float)$it['reseller_fee'], 'commission', 'Commission: ' . $it['title']];
    $agentFee = (float)($it['agent_fee'] ?? 0);
    if ($agentFee > 0 && $it['agent_id']) $legs[] = ['oi' . $it['id'] . ':g', (int)val('SELECT user_id FROM agents WHERE id = ?', [$it['agent_id']]), $agentFee, 'commission', 'Agency fee: ' . $it['title']];
    $legs[] = ['oi' . $it['id'] . ':a', admin_user_id(), round((float)$it['commission'] - $agentFee, 2), 'commission', 'Platform margin: ' . $it['title']];
    foreach ($legs as [$ref, $uid, $amt, $type, $note]) {
        if (!$uid || $amt <= 0) continue;
        if ($on) {
            $ins = q('INSERT IGNORE INTO wallet_tx (user_id, amount, type, ref, note) VALUES (?,?,?,?,?)', [$uid, $amt, $type, $ref, $note]);
            if ($ins->rowCount()) emit_for_user($uid, 'wallet.credited', ['amount' => $amt, 'type' => $type, 'note' => $note, 'balance' => wallet_balance($uid)]);
        } elseif (val('SELECT 1 FROM wallet_tx WHERE ref = ?', [$ref])) q('INSERT IGNORE INTO wallet_tx (user_id, amount, type, ref, note) VALUES (?,?,?,?,?)', [$uid, -$amt, 'adjust', $ref . ':rev', 'Return: ' . $it['title']]);
    }
}

/** Changes the status of one order item: settles wallets, updates the order, notifies the customer and the partner site. */
function apply_item_status(array $it, string $new): void {
    if (!isset(ITEM_STATUSES[$new])) return;
    $oid = (int)$it['order_id'];
    q('UPDATE order_items SET item_status = ? WHERE id = ?', [$new, $it['id']]);
    if ($new === 'delivered' && $it['item_status'] !== 'delivered') settle_item($it, true);
    if ($new === 'returned' && $it['item_status'] === 'delivered') settle_item($it, false);
    $all = array_column(rows('SELECT item_status FROM order_items WHERE order_id = ?', [$oid]), 'item_status');
    $cur = (string)val('SELECT status FROM orders WHERE id = ?', [$oid]);
    $os = !array_diff($all, ['delivered', 'archived']) ? 'done' : (array_intersect($all, ['shipped', 'on_delivery']) ? 'shipped' : $cur);
    q('UPDATE orders SET status = ? WHERE id = ?', [$os, $oid]);
    notify((int)val('SELECT customer_id FROM orders WHERE id = ?', [$oid]), 'Order BB-' . (1000 + $oid) . ': “' . $it['title'] . '” is now ' . ITEM_STATUSES[$new], '/cabinet/orders/' . $oid);
    emit_for_vendor((int)$it['vendor_id'], 'order.status_changed', ['order_id' => $oid, 'item_id' => (int)$it['id'], 'status' => $new, 'previous' => $it['item_status']]);
}

/* ---------- payouts ---------- */
/** Rows in other tables that point at $table (foreign keys), deleted bottom-up like ON DELETE CASCADE would, except the tables in $keep (history that must survive). */
function purge_refs(string $table, array $ids, array $keep, int $depth = 0): void {
    if (!$ids || $depth > 6) return;
    $in = implode(',', array_map('intval', $ids));
    foreach (rows('SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?', [$table]) as $r) {
        $t = $r['t']; $c = $r['c'];
        if (in_array($t, $keep, true)) continue;
        try { $child = array_map('intval', array_column(rows("SELECT id FROM `$t` WHERE `$c` IN ($in)"), 'id')); } catch (Throwable $e) { $child = []; }
        if ($child && !($t === $table && $depth > 5)) purge_refs($t, $child, $keep, $depth + 1);
        q("DELETE FROM `$t` WHERE `$c` IN ($in)");
    }
}

/**
 * Admin deletes a user: the account, the shop and all its products go; whatever is in the wallet moves to the admin wallet with a note saying whose it was.
 * Orders keep their history (buyers and sellers still need it). Agents (partner sites) and administrators cannot be deleted here.
 * @return array{0:bool,1:string}
 */
function delete_user_account(int $uid): array {
    $x = row('SELECT * FROM users WHERE id = ?', [$uid]);
    $admin = admin_user_id();
    if (!$x) return [false, 'User not found.'];
    if ($x['role'] === 'admin' || $uid === $admin) return [false, 'Administrators cannot be deleted here.'];
    if ($x['role'] === 'agent' || val('SELECT 1 FROM agents WHERE user_id = ?', [$uid])) return [false, 'This user is an Agent (partner site). Change the role or remove the agent first.'];
    $files = [];
    $pdo = db(); $pdo->beginTransaction();
    try {
        $who = trim(($x['name'] ?: '') . ' <' . $x['email'] . '>');
        $moved = 0.0;
        q("UPDATE wallet_tx SET status = 'rejected' WHERE user_id = ? AND type = 'withdraw' AND status = 'pending'", [$uid]);   // an unpaid request goes back to the balance
        $bal = round(wallet_balance($uid), 2);
        if ($bal > 0) {
            q("INSERT INTO wallet_tx (user_id, amount, type, status, ref, note) VALUES (?,?, 'adjust', 'done', ?, ?)", [$admin, $bal, 'deleted:' . $uid, mb_substr('From wallet of ' . $who . ' (user #' . $uid . ' deleted)', 0, 255)]);
            $moved = $bal;
        }
        $vids = array_map('intval', array_column(rows('SELECT id FROM vendors WHERE user_id = ?', [$uid]), 'id'));
        $pids = $vids ? array_map('intval', array_column(rows('SELECT id FROM products WHERE vendor_id IN (' . implode(',', $vids) . ')'), 'id')) : [];
        if ($pids) foreach (rows('SELECT image, gallery FROM products WHERE id IN (' . implode(',', $pids) . ')') as $p) {
            $list = [(string)$p['image']]; $g = json_decode((string)$p['gallery'], true); if (is_array($g)) foreach ($g as $i) $list[] = (string)(is_array($i) ? ($i['url'] ?? '') : $i);
            foreach ($list as $f) if (preg_match('~^/uploads/[A-Za-z0-9._-]+$~', $f)) $files[$f] = 1;
        }
        $keep = ['order_items', 'orders'];   // sales history stays (rows keep their numbers; the shop name is copied into them where the page needs it)
        purge_refs('products', $pids, $keep); purge_refs('vendors', $vids, $keep); purge_refs('users', [$uid], $keep);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        if ($pids) q('DELETE FROM products WHERE id IN (' . implode(',', $pids) . ')');
        if ($vids) q('DELETE FROM vendors WHERE id IN (' . implode(',', $vids) . ')');
        q('DELETE FROM users WHERE id = ?', [$uid]);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $e2) {}
        return [false, 'Could not delete the user: ' . $e->getMessage()];
    }
    foreach (array_keys($files) as $f) @unlink(ROOT . '/public' . $f);
    if ($moved > 0) notify($admin, 'User ' . $who . ' deleted; ' . money($moved) . ' moved to your wallet.', '/admin/users');
    return [true, 'User deleted: shop and products removed' . ($moved > 0 ? ', ' . money($moved) . ' moved to the admin wallet' : '') . '.'];
}

function missing_required(array $type, array $data): array {
    $miss = [];
    foreach ($type['fields'] as $f) if ($f['required'] && trim((string)($data['f' . $f['id']] ?? '')) === '') $miss[] = $f['label'];
    return $miss;
}

/** Payout gateway stub (test mode). Returns ['ok'|'unknown'|'error', message]. Replace with the real payment system. */
function payout_send(float $amt, array $data): array {
    $cents = (int)round(fmod($amt, 1) * 100);
    if ($cents === 99) return ['unknown', ''];                                   // test: unknown error -> pending for admin
    if ($cents === 98) return ['error', 'The bank rejected the transfer (test).']; // test: real error shown to the user
    return ['ok', ''];
}

/** Withdraws money from a user's wallet to their bank account. $v is the user's vendors row. Returns [flash type 'ok'|'warn'|'bad', message, code]. */
function withdraw_money(int $uid, array $v, float $amt): array {
    $types = require ROOT . '/src/profile_fields.php';
    $label = ['self' => 'Самозанятый / ИП', 'company' => 'Компания', 'personal' => 'Физлицо'];
    $personal = json_decode($v['personal'] ?? '{}', true) ?: [];
    $pt = isset($types[$v['payout_type'] ?? '']) ? $v['payout_type'] : 'self';
    $miss = missing_required($types[$pt], $personal[$pt] ?? []);
    if ($miss) return ['warn', 'Complete the required fields in Personal data first (' . count($miss) . ' missing, e.g. “' . $miss[0] . '”).', 'profile_incomplete'];
    if ($amt < 100) return ['bad', 'Minimum withdrawal is 100 ₽.', 'too_small'];
    if ($amt > wallet_balance($uid)) return ['bad', 'Not enough funds on the wallet.', 'insufficient'];
    [$res, $perr] = payout_send($amt, $personal[$pt] ?? []);
    $note = 'Withdrawal to bank (' . $label[$pt] . ')';
    if ($res === 'ok') { q("INSERT INTO wallet_tx (user_id, amount, type, status, note) VALUES (?,?,'withdraw','done',?)", [$uid, -$amt, $note]); return ['ok', money($amt) . ' sent to your bank account.', 'sent']; }
    if ($res === 'unknown') {
        q("INSERT INTO wallet_tx (user_id, amount, type, status, note) VALUES (?,?,'withdraw','pending',?)", [$uid, -$amt, $note . ' — under review']);
        notify(admin_user_id(), 'Payout needs review: ' . money($amt), '/admin/users');
        return ['warn', 'The payment system did not confirm the transfer. It is under review by the administrator.', 'review'];
    }
    return ['bad', 'Transfer failed: ' . $perr . ' Your balance was not changed.', 'failed'];
}
