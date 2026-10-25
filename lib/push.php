<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';
require_once APP_ROOT . '/vendor/autoload.php';

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

function vapid(): array
{
    $pub = setting('vapid_public');
    $prv = setting('vapid_private');
    if (!$pub || !$prv) {
        $k = VAPID::createVapidKeys();
        set_setting('vapid_public', $k['publicKey']);
        set_setting('vapid_private', $k['privateKey']);
        return ['publicKey' => $k['publicKey'], 'privateKey' => $k['privateKey']];
    }
    return ['publicKey' => $pub, 'privateKey' => $prv];
}

/**
 * Send one payload to every subscribed device.
 * Tests can set $GLOBALS['PUSH_SINK'] = fn(array $payload): int to capture instead of sending.
 * @return int number of devices that accepted the message
 */
function push_all(array $payload, int $ttl = 600): int
{
    if (isset($GLOBALS['PUSH_SINK'])) return ($GLOBALS['PUSH_SINK'])($payload);
    $subs = db()->query('SELECT * FROM subs')->fetchAll();
    if (!$subs) return 0;
    $v = vapid();
    $wp = new WebPush(['VAPID' => ['subject' => setting('site_url'), 'publicKey' => $v['publicKey'], 'privateKey' => $v['privateKey']]], [], 20);
    $topic = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payload['tag'] ?? '')), 0, 32);
    $opts = ['TTL' => $ttl, 'urgency' => 'high'];
    if ($topic !== '') $opts['topic'] = $topic;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    foreach ($subs as $s) {
        $wp->queueNotification(Subscription::create([
            'endpoint' => $s['endpoint'],
            'keys' => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
        ]), $json, $opts);
    }
    $ok = 0;
    foreach ($wp->flush() as $report) {
        if ($report->isSuccess()) { $ok++; continue; }
        if ($report->isSubscriptionExpired()) {
            db()->prepare('DELETE FROM subs WHERE endpoint=?')->execute([$report->getEndpoint()]);
        } else {
            error_log('push failed: ' . $report->getReason());
        }
    }
    return $ok;
}
