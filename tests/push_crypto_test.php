<?php
// Builds a real encrypted web-push request for a throwaway subscription and checks it is produced (not delivered).
putenv('PRAYER_DB=:memory:');
require __DIR__ . '/../lib/push.php';
use Minishlink\WebPush\{WebPush, Subscription};
$key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$d = openssl_pkey_get_details($key);
$pub = "\x04" . $d['ec']['x'] . $d['ec']['y'];
$b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$v = vapid();
$wp = new WebPush(['VAPID' => ['subject' => 'https://app.example.com', 'publicKey' => $v['publicKey'], 'privateKey' => $v['privateKey']]], [], 5);
$wp->queueNotification(Subscription::create(['endpoint' => 'https://127.0.0.1:9/push/abc', 'keys' => ['p256dh' => $b64($pub), 'auth' => $b64(random_bytes(16))]]),
    json_encode(['title' => 'x', 'body' => 'مرحبا ☪ test']), ['TTL' => 60, 'urgency' => 'high', 'topic' => 'prayer-fajr']);
$t = microtime(true);
foreach ($wp->flush() as $r) {
    $reason = $r->getReason();
    $crypto = !preg_match('/encrypt|key|curve|invalid/i', $reason) || preg_match('/connect|resolve|refused|SSL|timed/i', $reason);
    echo ($crypto ? "ok   " : "FAIL ") . "request was built and attempted: " . substr($reason, 0, 120) . sprintf(" (%.2fs)\n", microtime(true) - $t);
    exit($crypto ? 0 : 1);
}
