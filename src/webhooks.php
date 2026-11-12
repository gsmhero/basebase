<?php
declare(strict_types=1);

/* Outgoing events to an Agent's partner site: {domain}/api/basebase/webhook, signed like every API call (see docs/BASEBASE-SYSFERA-API-v1.md). */

/** Signature headers for a request to / from the partner site. */
function bb_sign(string $secret, int $ts, string $method, string $uri, string $body): string {
    return hash_hmac('sha256', $ts . "\n" . strtoupper($method) . "\n" . $uri . "\n" . $body, $secret);
}

/** Queue an event for the agent a user (seller) belongs to. Nothing happens for users without an active agent. */
function emit_for_user(int $userId, string $event, array $data): void {
    try {
        $ag = row("SELECT a.id FROM users u JOIN agents a ON a.id = u.agent_id WHERE u.id = ? AND a.status = 'active' AND a.api_secret IS NOT NULL AND a.domain <> ''", [$userId]);
        if (!$ag) return;
        $data['seller_id'] = $userId;
        q('INSERT INTO webhook_events (agent_id, event, payload, event_uid, next_try) VALUES (?,?,?,?,?)', [$ag['id'], $event, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), bin2hex(random_bytes(12)), time()]);
        static $hooked = false;
        if (!$hooked && function_exists('fastcgi_finish_request')) { $hooked = true; register_shutdown_function(function () { @fastcgi_finish_request(); webhooks_flush(5); }); }
    } catch (Throwable $e) { mail_log('emit ' . $event . ': ' . $e->getMessage()); }
}
function emit_for_vendor(int $vendorId, string $event, array $data): void {
    $uid = (int)val('SELECT user_id FROM vendors WHERE id = ?', [$vendorId]);
    if ($uid) emit_for_user($uid, $event, $data);
}

/** Delivers due events (with back-off). Called after responses and on every API call. */
function webhooks_flush(int $limit = 10): void {
    try {
        $due = rows("SELECT e.*, a.domain, a.api_secret FROM webhook_events e JOIN agents a ON a.id = e.agent_id WHERE e.status = 'pending' AND e.next_try <= ? ORDER BY e.id LIMIT " . (int)$limit, [time()]);
        foreach ($due as $e) {
            $uri = '/api/basebase/webhook';
            $body = json_encode(['event_id' => $e['event_uid'], 'event' => $e['event'], 'created_at' => date('c', strtotime($e['created_at'])), 'data' => json_decode($e['payload'], true)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ts = time();
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0,
                'header' => "Content-Type: application/json\r\nX-BB-Agent: {$e['agent_id']}\r\nX-BB-Timestamp: $ts\r\nX-BB-Signature: " . bb_sign($e['api_secret'], $ts, 'POST', $uri, $body) . "\r\n", 'content' => $body]]);
            $res = @file_get_contents(rtrim($e['domain'], '/') . $uri, false, $ctx);
            $code = 0; foreach ($http_response_header ?? [] as $h) if (preg_match('~^HTTP/\S+ (\d{3})~', $h, $m)) $code = (int)$m[1];
            if ($res !== false && $code >= 200 && $code < 300) q("UPDATE webhook_events SET status = 'sent', attempts = attempts + 1 WHERE id = ?", [$e['id']]);
            else {
                $n = (int)$e['attempts'] + 1;
                q('UPDATE webhook_events SET attempts = ?, status = ?, next_try = ? WHERE id = ?', [$n, $n >= 8 ? 'failed' : 'pending', time() + (int)(60 * (2 ** $n)), $e['id']]);
            }
        }
    } catch (Throwable $e) { mail_log('webhooks_flush: ' . $e->getMessage()); }
}
