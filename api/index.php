<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/state.php';
require_once __DIR__ . '/../lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

set_exception_handler(function (Throwable $e) {
    error_log('api error: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    out(['error' => 'server error'], 500);
});

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = trim(preg_replace('#^.*?/api/#', '', $path . (str_ends_with($path, '/') ? '' : '')), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $in = $raw ? (json_decode($raw, true) ?: []) : [];
}
$q = $_GET;
$key = $q['k'] ?? $in['k'] ?? null;

// ---- public ----
if ($route === 'status') out(['configured' => configured(), 'vapid' => vapid()['publicKey']]);
if ($route === 'claim' && $method === 'POST') {
    $r = do_claim((string) ($in['passcode'] ?? ''));
    $r ? out($r) : out(['error' => 'already set up, or passcode too short (6+)'], 403);
}
if ($route === 'login' && $method === 'POST') {
    $r = do_login((string) ($in['passcode'] ?? ''));
    $r ? out($r) : out(['error' => 'wrong passcode'], 401);
}

// ---- Shortcuts (long key in the URL) ----
$viaKey = event_key_ok($key);
$viaToken = token_ok(request_token());
if ($route === 'event' && ($viaKey || $viaToken)) {
    $type = (string) ($in['type'] ?? $q['type'] ?? '');
    $val = $in['value'] ?? $q['value'] ?? null;
    if ($type === '') out(['error' => 'type required'], 400);
    out(act_event($type, $val));
}
if ($route === 'fajr.json' && ($viaKey || $viaToken)) {
    $now = app_now();
    $date = local_date($now);
    $tom = add_days($date, 1);
    $f = day_times($tom)['fajr'];
    out(['date' => $tom, 'fajr' => hm($f), 'hour' => (int) substr(hm($f), 0, 2), 'minute' => (int) substr(hm($f), 3, 2), 'ts' => $f,
         'today_fajr' => hm(day_times($date)['fajr']), 'office_wake' => setting('office_wake')]);
}

// ---- everything else needs the app token ----
if (!$viaToken) out(['error' => 'unauthorized'], 401);

$date = local_date(app_now());
$dateIn = isset($in['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $in['date']) ? $in['date'] : $date;
$prayerIn = isset($in['prayer']) && in_array($in['prayer'], PRAYERS, true) ? $in['prayer'] : null;

switch ($method . ' ' . $route) {
    case 'GET state':
        out(build_state());
    case 'GET times':
        out(build_times($q['from'] ?? $date, min(30, max(1, (int) ($q['days'] ?? 14)))));
    case 'GET review':
        out(build_review(($q['period'] ?? 'week') === 'month' ? 'month' : 'week'));
    case 'GET log':
        out(db()->query('SELECT ts,kind,title,body,tier,sent FROM pushes ORDER BY id DESC LIMIT 60')->fetchAll());
    case 'GET quote':
        quotes_ready();
        $st = db()->prepare('SELECT * FROM quotes WHERE id=?');
        $st->execute([(string) ($q['id'] ?? '')]);
        $r = $st->fetch();
        $r ? out($r + ['grade_label' => grade_label($r['grade'])]) : out(['error' => 'not found'], 404);
    case 'POST prayed':
        if (!$prayerIn) out(['error' => 'prayer required'], 400);
        out(act_prayed($dateIn, $prayerIn, !empty($in['masjid']), !empty($in['undo'])));
    case 'POST notyet':
        if (($in['kind'] ?? 'prayer') === 'task') act_notyet_task($dateIn, (string) ($in['key'] ?? ''));
        elseif ($prayerIn) act_notyet_prayer($dateIn, $prayerIn);
        day_set($date, ['pin_dirty' => 1]);
        out(['ok' => true]);
    case 'POST up':
        out(act_up());
    case 'POST bed':
        out(act_bed());
    case 'POST task/toggle':
        out(act_task_toggle($dateIn, (string) ($in['key'] ?? ''), isset($in['done']) ? (bool) $in['done'] : null));
    case 'POST tasks/config':
        if (!is_array($in['tasks'] ?? null)) out(['error' => 'tasks required'], 400);
        $clean = [];
        foreach ($in['tasks'] as $t) {
            if (empty($t['key']) || empty($t['label'])) continue;
            $clean[] = array_intersect_key($t + ['enabled' => true], array_flip(['key', 'label', 'office', 'off', 'ctx', 'dow', 'optional', 'auto', 'enabled']));
        }
        set_setting('tasks_cfg', json_encode($clean));
        ensure_day($date);
        out(['ok' => true, 'tasks' => tasks_cfg()]);
    case 'POST weekly':
        $kind = (string) ($in['kind'] ?? '');
        $text = trim((string) ($in['text'] ?? ''));
        if (!in_array($kind, ['seerah', 'hadith', 'tafseer'], true) || $text === '') out(['error' => 'kind/text required'], 400);
        db()->prepare('INSERT INTO weekly(kind,date_set,text,ts) VALUES(?,?,?,?)')->execute([$kind, $date, mb_substr($text, 0, 400), app_now()]);
        out(['ok' => true]);
    case 'POST event':
        out(act_event((string) ($in['type'] ?? ''), $in['value'] ?? null));
    case 'POST settings':
        $allowed = array_keys(DEFAULTS);
        foreach ($in as $k => $v) {
            if (!in_array($k, $allowed, true) || !is_scalar($v)) continue;
            if ($k === 'tz' && !in_array($v, timezone_identifiers_list(), true)) continue;
            set_setting($k, (string) $v);
        }
        if (isset($in['lat'], $in['lng'])) set_setting('location_set', '1');
        ensure_day($date);
        out(['ok' => true, 'settings' => public_settings()]);
    case 'POST push/subscribe':
        $ep = (string) ($in['endpoint'] ?? '');
        $k = $in['keys'] ?? [];
        if (!str_starts_with($ep, 'https://') || empty($k['p256dh']) || empty($k['auth'])) out(['error' => 'bad subscription'], 400);
        db()->prepare('INSERT INTO subs(endpoint,p256dh,auth,ua,created) VALUES(?,?,?,?,?)
            ON CONFLICT(endpoint) DO UPDATE SET p256dh=excluded.p256dh, auth=excluded.auth, ua=excluded.ua')
            ->execute([$ep, $k['p256dh'], $k['auth'], substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120), app_now()]);
        out(['ok' => true]);
    case 'POST push/test':
        $ok = notify('test', 'test', 'Test notification', ['salah', 'time'], 2, ['essential' => true, 'body' => 'If you can read this, push works.']);
        out(['ok' => $ok, 'devices' => (int) db()->query('SELECT COUNT(*) FROM subs')->fetchColumn()]);
    case 'POST logout':
        db()->prepare('DELETE FROM tokens WHERE hash=?')->execute([token_hash((string) request_token())]);
        out(['ok' => true]);
}
out(['error' => 'not found', 'route' => $route], 404);
