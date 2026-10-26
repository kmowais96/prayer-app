<?php
// The situations the live app actually got wrong: installed in the evening, and never tapped.
// Run: php tests/scenarios_test.php
declare(strict_types=1);
putenv('PRAYER_DB=:memory:');
require __DIR__ . '/../lib/engine.php';

$fails = 0;
function check(bool $ok, string $msg): void { global $fails; echo ($ok ? "ok   " : "FAIL ") . $msg . "\n"; if (!$ok) $fails++; }

/** Run the engine minute by minute between two local times on $date. Returns the pushes. */
function run_day(string $date, string $from, string $to, array $script = [], array $settings = []): array
{
    $sent = [];
    db()->exec('DELETE FROM prayers; DELETE FROM tasks; DELETE FROM days; DELETE FROM pushes; DELETE FROM quote_log; DELETE FROM events');
    foreach (['pulse_last' => '0', 'pulse_hist' => '[]', 'pulse_used' => '{}', 'pulse_used_date' => ''] as $k => $v) set_setting($k, $v);
    foreach ($settings as $k => $v) set_setting($k, $v);
    $GLOBALS['PUSH_SINK'] = function (array $p) use (&$sent) { $sent[] = $p; return 1; };
    $start = local_ts($date, $from);
    $end = local_ts($date, $to);
    ksort($script);
    for ($now = $start; $now <= $end; $now += 60) {
        $GLOBALS['APP_NOW'] = $now;
        foreach ($script as $at => $fn) if ($at <= $now) { $fn(); unset($script[$at]); }
        engine_tick($now);
    }
    return $sent;
}

set_setting('tz', 'Asia/Kuwait');
set_setting('calc_method', 'kuwait');
set_setting('lat', '29.3759');
set_setting('lng', '47.9774');
$date = '2026-10-26'; // Monday, office day
$w = prayer_windows($date);
echo "Kuwait " . $date . ": " . implode('  ', array_map(fn($p) => ucfirst($p) . ' ' . hm($w[$p][0]), PRAYERS)) . "\n\n";

// ---- 1. installed at 18:00, after Fajr, Dhuhr and Asr have all closed ----
echo "--- installed at 18:00 (the Fajr-at-6pm case) ---\n";
$s = run_day($date, '18:00', '21:30');
$stale = array_values(array_filter($s, fn($p) => in_array($p['kind'], ['missed', 'qada'], true)
    && in_array($p['group'], ['prayer-fajr', 'prayer-dhuhr', 'prayer-asr'], true)));
check(count($stale) === 0, 'nothing about Fajr, Dhuhr or Asr after the install: ' . count($stale)
    . ($stale ? ' (' . implode('; ', array_map(fn($p) => hm($p['ts']) . ' ' . $p['title'], $stale)) . ')' : ''));
$names = array_values(array_unique(array_map(fn($p) => $p['title'], array_filter($s, fn($p) => $p['kind'] === 'pulse'))));
$gone = array_values(array_filter($names, fn($t) => preg_match('/^(Fajr|Dhuhr|Asr)\b/', $t) && !str_contains($t, 'tomorrow')));
check(count($gone) === 0, 'no pulse names a prayer whose time has gone: ' . json_encode($gone));
$rows = db()->prepare('SELECT prayer,status FROM prayers WHERE date=?'); $rows->execute([$date]);
$st = []; foreach ($rows->fetchAll() as $r) $st[$r['prayer']] = $r['status'];
check($st['fajr'] === 'missed' && $st['dhuhr'] === 'missed', 'the gaps are still recorded for the review: ' . json_encode($st));
$pulses = array_values(array_filter($s, fn($p) => $p['kind'] === 'pulse'));
check(count($pulses) >= 8, 'motivation arrives without tapping anything: ' . count($pulses) . ' pulses');
echo "  first lines:\n";
foreach (array_slice($pulses, 0, 6) as $p) echo "   [" . hm($p['ts']) . "] " . $p['title'] . " | " . str_replace("\n", " / ", $p['body']) . "\n";

// ---- 2. a whole day, never tapping anything ----
echo "\n--- a full day with no taps at all ---\n";
$s = run_day($date, '03:00', '22:00');
$pulses = array_values(array_filter($s, fn($p) => $p['kind'] === 'pulse'));
$pt = array_map(fn($p) => $p['ts'], $pulses);
check(count($pulses) >= 30, 'pulses run all day on the presumed waking hour: ' . count($pulses));
check(min($pt) >= local_ts($date, '07:00'), 'none before the presumed waking hour: first ' . hm(min($pt)));
check(max($pt) < local_ts($date, '22:00'), 'none after bedtime: last ' . hm(max($pt)));
$late = array_values(array_filter($s, fn($p) => $p['kind'] === 'qada' && $p['ts'] > $p['ts'])); // placeholder
foreach (PRAYERS as $p) {
    $r = prayer_row($date, $p);
    $nags = array_filter($s, fn($x) => $x['tag'] === 'prayer-' . $p && in_array($x['kind'], ['missed', 'qada'], true));
    $ends = $w[$p][1];
    $tooLate = array_filter($nags, fn($x) => $x['ts'] > $ends + 7200);
    check(count($tooLate) === 0, "no $p reminder more than 2 h after its window closed");
}
$titles = array_map(fn($p) => $p['title'], $s);
$long = array_values(array_filter($titles, fn($t) => mb_strlen($t) > 32));
check(count($long) === 0, 'every title fits the lock screen: ' . json_encode(array_slice($long, 0, 3)));
$bodies = array_map(fn($p) => $p['body'], $s);
$longb = array_values(array_filter($bodies, fn($b) => mb_strlen(explode("\n", $b)[0]) > 110));
check(count($longb) === 0, 'every body line fits: ' . json_encode(array_slice($longb, 0, 2)));

// ---- 3. evening after Isha: the next prayer is tomorrow's Fajr ----
echo "\n--- after Isha, the next prayer is tomorrow ---\n";
$s = run_day($date, '19:30', '21:30', [
    $w['maghrib'][0] + 120 => fn() => act_prayed('2026-10-26', 'maghrib', true),
    $w['isha'][0] + 120 => fn() => act_prayed('2026-10-26', 'isha', true),
]);
$pf = prayer_focus($date, local_ts($date, '21:00'));
check($pf['state'] === 'tomorrow' && local_date($pf['start']) === '2026-10-27', 'focus rolls over to tomorrow Fajr ' . hm($pf['start']));
$pin = array_values(array_filter($s, fn($p) => $p['kind'] === 'pin'));
check($pin && str_contains(end($pin)['body'], 'Fajr'), 'pinned card points at Fajr: ' . (end($pin)['body'] ?? ''));
echo "  " . (end($pin)['title'] ?? '') . " | " . str_replace("\n", " / ", end($pin)['body'] ?? '') . "\n";

// ---- 4. a day off starts later ----
echo "\n--- Friday, a day off ---\n";
$fri = '2026-10-30';
$s = run_day($fri, '03:00', '12:00');
$pulses = array_values(array_filter($s, fn($p) => $p['kind'] === 'pulse'));
check(!$pulses || min(array_map(fn($p) => $p['ts'], $pulses)) >= local_ts($fri, '09:00'), 'no 7am pulses on a day off: first ' . ($pulses ? hm(min(array_map(fn($p) => $p['ts'], $pulses))) : 'none'));
check(count(array_filter($s, fn($p) => $p['kind'] === 'wake')) === 0, 'no office wake siren on a day off');

exit($fails ? 1 : 0);
