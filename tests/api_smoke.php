<?php
// php tests/api_smoke.php http://127.0.0.1:8099   (needs a fresh dev server: tools/dev-router.php)
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8099', '/') . '/api';
function call(string $method, string $url, ?array $body = null, ?string $tok = null): array {
    $h = "Content-Type: application/json\r\n" . ($tok ? "X-Auth: $tok\r\n" : '');
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $h, 'content' => $body !== null ? json_encode($body) : '', 'ignore_errors' => true]]);
    $r = file_get_contents($url, false, $ctx);
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), json_decode((string) $r, true)];
}
$fails = 0;
function ok(bool $c, string $m): void { global $fails; echo ($c ? 'ok   ' : 'FAIL ') . $m . "\n"; if (!$c) $fails++; }

[$c, $d] = call('GET', "$base/status"); ok($c === 200 && $d['configured'] === false && strlen($d['vapid']) > 60, 'status before claim');
[$c] = call('POST', "$base/claim", ['passcode' => 'abc']); ok($c === 403, 'short passcode refused');
[$c, $d] = call('POST', "$base/claim", ['passcode' => 'secret123']); ok($c === 200 && !empty($d['token']), 'claim works once');
$tok = $d['token']; $key = $d['event_key'];
[$c] = call('POST', "$base/claim", ['passcode' => 'another1']); ok($c === 403, 'second claim refused');
[$c] = call('POST', "$base/login", ['passcode' => 'nope123']); ok($c === 401, 'wrong passcode refused');
[$c, $d] = call('POST', "$base/login", ['passcode' => 'secret123']); ok($c === 200, 'login works');
[$c] = call('GET', "$base/state"); ok($c === 401, 'state needs token');
[$c, $s] = call('GET', "$base/state", null, $tok); ok($c === 200 && count($s['prayers']) === 5 && count($s['tasks']) >= 8, 'state: ' . count($s['tasks']) . ' tasks, next ' . $s['next']['label']);
[$c, $d] = call('POST', "$base/event?k=$key", ['type' => 'steps', 'value' => 4200]); ok($c === 200, 'steps event via key');
[$c] = call('POST', "$base/event?k=wrong", ['type' => 'steps', 'value' => 1]); ok($c === 401, 'event with wrong key refused');
[$c, $d] = call('GET', "$base/fajr.json?k=$key"); ok($c === 200 && preg_match('/^\d\d:\d\d$/', $d['fajr']), 'fajr.json ' . $d['date'] . ' ' . $d['fajr']);
[$c, $d] = call('POST', "$base/prayed", ['prayer' => 'fajr', 'masjid' => true], $tok); ok($c === 200 && in_array($d['status'], ['prayed', 'qada']), 'prayed fajr -> ' . $d['status']);
[$c, $d] = call('POST', "$base/task/toggle", ['key' => 'quran_record'], $tok); ok($c === 200 && $d['done'] === true, 'task toggle');
[$c, $d] = call('POST', "$base/weekly", ['kind' => 'seerah', 'text' => 'Hijra to Madinah'], $tok); ok($c === 200, 'weekly saved');
[$c, $d] = call('POST', "$base/settings", ['lat' => 17.385, 'lng' => 78.4867, 'tz' => 'Asia/Kolkata', 'asr_factor' => 1], $tok); ok($c === 200 && $d['settings']['tz'] === 'Asia/Kolkata', 'settings saved');
[$c, $s] = call('GET', "$base/state", null, $tok); ok($s['prayers'][0]['start'] > 0, 'state after tz change: fajr ' . date('H:i', $s['prayers'][0]['start'] + 19800));
[$c, $d] = call('GET', "$base/review?period=month", null, $tok); ok($c === 200 && isset($d['prayers']['per_prayer']), 'review month');
[$c, $d] = call('GET', "$base/times?days=3", null, $tok); ok($c === 200 && count($d) === 3, 'times 3 days');
[$c, $d] = call('POST', "$base/push/test", [], $tok); ok($c === 200 && $d['devices'] === 0, 'push test (no devices yet)');
[$c, $d] = call('GET', "$base/log", null, $tok); ok($c === 200 && count($d) >= 1, 'log has the test push');
echo $fails ? "$fails FAILED\n" : "all good\n";
exit($fails ? 1 : 0);
