<?php
// Simulates a day minute by minute with a fake phone, checks the reminder cadence.
// Run: PRAYER_DB=:memory: php tests/engine_test.php
declare(strict_types=1);
putenv('PRAYER_DB=:memory:');
require __DIR__ . '/../lib/engine.php';

$sent = [];
$GLOBALS['PUSH_SINK'] = function (array $p) use (&$sent) { $sent[] = $p; return 1; };
set_setting('tz', 'Asia/Riyadh');

$fails = 0;
function check(bool $ok, string $msg): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $msg . "\n"; if (!$ok) $fails++; }

$date = '2026-10-25'; // a Sunday = office day
$w = prayer_windows($date);
$t = fn(string $hm) => local_ts($date, $hm);

// ---- scripted behaviour ----
$script = [
    // time => callback
    $w['fajr'][0] + 12 * 60 => fn() => act_up(),
    $w['fajr'][0] + 20 * 60 => fn() => act_prayed($GLOBALS['date'], 'fajr', true),
    $w['dhuhr'][0] + 27 * 60 => fn() => act_prayed($GLOBALS['date'], 'dhuhr', false),
    $w['asr'][0] + 3 * 60 => fn() => act_notyet_prayer($GLOBALS['date'], 'asr'),
    $w['asr'][0] + 30 * 60 => fn() => act_prayed($GLOBALS['date'], 'asr', true),
    // maghrib: ignored -> missed
    $w['isha'][0] + 5 * 60 => fn() => act_prayed($GLOBALS['date'], 'isha', true),
    $t('18:20') => fn() => act_task_toggle($GLOBALS['date'], 'quran_record', true),
    $w['fajr'][0] + 25 * 60 => fn() => act_up(), // answers the wake check
    $t('22:50') => fn() => act_bed(),
];
$GLOBALS['date'] = $date;

$start = local_ts($date, '00:00');
$end = local_ts(add_days($date, 1), '04:30');
$GLOBALS['APP_NOW'] = $start;
day_set(add_days($date, -1), ['bed_at' => $start - 3600, 'tahajjud_sent' => 2]); // last night is history
ksort($script);
for ($now = $start; $now <= $end; $now += 60) {
    $GLOBALS['APP_NOW'] = $now;
    foreach ($script as $at => $fn) if ($at <= $now) { $fn(); unset($script[$at]); }
    engine_tick($now);
}

function times(array $sent, string $kind, ?string $tag = null): array
{
    return array_values(array_map(fn($p) => hm($p['ts']), array_filter($sent, fn($p) => $p['kind'] === $kind && ($tag === null || $p['tag'] === $tag))));
}

$fajrPush = array_values(array_filter($sent, fn($p) => $p['tag'] === 'prayer-fajr' && $p['kind'] === 'prayer'));
check(count($fajrPush) >= 8 && count($fajrPush) <= 14, 'fajr nags (1/min while asleep, then 5 min): ' . count($fajrPush));
check(end($fajrPush)['ts'] <= $w['fajr'][0] + 20 * 60, 'fajr nags stop when prayed');

$dh = array_values(array_filter($sent, fn($p) => $p['tag'] === 'prayer-dhuhr' && $p['kind'] === 'prayer'));
$offs = array_map(fn($p) => intdiv($p['ts'] - $w['dhuhr'][0], 60), $dh);
check($offs === [0, 10, 20, 25], 'dhuhr cadence 0,10,20,+5: ' . json_encode($offs));
check($dh[3]['tier'] > $dh[0]['tier'], 'tier rises: ' . implode(',', array_column($dh, 'tier')));

$as = array_values(array_filter($sent, fn($p) => $p['tag'] === 'prayer-asr' && $p['kind'] === 'prayer'));
check(count($as) >= 3, 'asr notyet brings reminder sooner: ' . json_encode(array_map(fn($p) => intdiv($p['ts'] - $w['asr'][0], 60), $as)));

$mg = array_values(array_filter($sent, fn($p) => $p['tag'] === 'prayer-maghrib'));
check(in_array('missed', array_column($mg, 'kind'), true), 'maghrib marked missed with qada push');
check(count(array_filter($mg, fn($p) => $p['kind'] === 'qada')) === 2, 'two qada nags after missed');

check(count(times($sent, 'nosleep')) === 2, 'two do-not-sleep nudges after fajr: ' . json_encode(times($sent, 'nosleep')));
check(count(times($sent, 'wakecheck')) >= 1, 'wake check fired after I am up: ' . json_encode(times($sent, 'wakecheck')));

$office = array_filter($sent, fn($p) => in_array($p['kind'], ['task', 'steps', 'weekly']) && in_office_hours($p['ts']));
check(count($office) === 0, 'no tasks/steps during office hours');
$tasks = times($sent, 'task');
check(count($tasks) > 0 && min($tasks) >= '17:30', 'tasks start after office: first ' . min($tasks));
check(count(times($sent, 'night')) > 0 && max(times($sent, 'night')) < '23:00', 'night chain stops after bed: ' . json_encode(times($sent, 'night')));
check(count(times($sent, 'tahajjud')) === 2, 'tahajjud x2 at last third: ' . json_encode(times($sent, 'tahajjud')));
check(count(times($sent, 'review')) === 1, 'sunday weekly review: ' . json_encode(times($sent, 'review')));
check(count(times($sent, 'pin')) >= 12, 'pinned today: ' . count(times($sent, 'pin')));
check(count(times($sent, 'wake')) === 0, 'no 7:00 siren because he was up');

// quote repetition
$q = array_filter(array_column($sent, 'qid'));
$dups = count($q) - count(array_unique($q));
check($dups <= 5, "quote repeats within one day: $dups of " . count($q));

echo "total pushes: " . count($sent) . "\n";
$by = [];
foreach ($sent as $p) $by[$p['kind']] = ($by[$p['kind']] ?? 0) + 1;
ksort($by);
echo json_encode($by) . "\n";
echo "\nSample bodies:\n";
foreach ([0, 40, 100, 200, 300] as $i) if (isset($sent[$i])) echo "[" . hm($sent[$i]['ts']) . " t{$sent[$i]['tier']} {$sent[$i]['kind']}] {$sent[$i]['title']}\n   " . str_replace("\n", "\n   ", $sent[$i]['body']) . "\n";
exit($fails ? 1 : 0);
