<?php
declare(strict_types=1);

require_once __DIR__ . '/engine.php';

function public_settings(): array
{
    $keys = ['lat', 'lng', 'tz', 'asr_factor', 'calc_method', 'office_start', 'office_end', 'off_days', 'night_start', 'office_wake',
             'pin_every_min', 'pulse_on', 'pulse_every_min', 'masjid_on', 'goal_steps', 'goal_weight_kg', 'goal_date', 'norepeat_days', 'location_set'];
    $o = [];
    foreach ($keys as $k) $o[$k] = setting($k);
    return $o;
}

function build_state(): array
{
    $now = app_now();
    $date = local_date($now);
    ensure_day($date);
    $w = prayer_windows($date);
    $prayers = [];
    $next = null;
    foreach (PRAYERS as $p) {
        $r = prayer_row($date, $p);
        [$s, $e] = $w[$p];
        $phase = $now < $s ? 'upcoming' : ($now < $e ? 'now' : 'ended');
        if (!$next && $now < $s) $next = ['key' => $p, 'label' => prayer_label($p, $date), 'at' => $s];
        $prayers[] = [
            'key' => $p, 'label' => prayer_label($p, $date), 'start' => $s, 'end' => $e, 'phase' => $phase,
            'status' => $r['status'], 'prayed_at' => $r['prayed_at'], 'masjid' => (bool) $r['masjid'], 'nags' => (int) $r['sent'],
        ];
    }
    if (!$next) { // after Isha: tomorrow's Fajr
        $tom = add_days($date, 1);
        $next = ['key' => 'fajr', 'label' => 'Fajr', 'at' => day_times($tom)['fajr'], 'tomorrow' => true];
    }
    // yesterday's Isha can still be open after midnight
    $carry = null;
    $y = add_days($date, -1);
    $yr = prayer_row($y, 'isha');
    if ($yr && $yr['status'] === 'pending' && $now < prayer_windows($y)['isha'][1]) $carry = ['date' => $y, 'key' => 'isha', 'label' => 'Isha (last night)'];

    $cfg = [];
    foreach (tasks_cfg() as $t) $cfg[$t['key']] = $t;
    $st = db()->prepare('SELECT * FROM tasks WHERE date=?');
    $st->execute([$date]);
    $tasks = [];
    foreach ($st->fetchAll() as $r) {
        $t = $cfg[$r['key']] ?? [];
        $tasks[] = [
            'key' => $r['key'], 'label' => $r['label'], 'done' => (bool) $r['done'], 'time' => task_time($t, $date),
            'optional' => !empty($t['optional']), 'auto' => $t['auto'] ?? null, 'nags' => (int) $r['sent'],
        ];
    }
    $day = day_row($date);
    $weekly = [];
    foreach (['seerah', 'hadith', 'tafseer'] as $k) $weekly[$k] = weekly_latest($k);

    $last = db()->query('SELECT ts,kind,title,body,tier FROM pushes ORDER BY id DESC LIMIT 5')->fetchAll();
    $first = db()->query('SELECT weight FROM days WHERE weight IS NOT NULL ORDER BY date ASC LIMIT 1')->fetchColumn();
    $lastW = db()->query('SELECT weight FROM days WHERE weight IS NOT NULL ORDER BY date DESC LIMIT 1')->fetchColumn();

    return [
        'now' => $now, 'date' => $date, 'dow' => dow($date), 'off_day' => is_off_day($date), 'office_now' => in_office_hours($now),
        'prayers' => $prayers, 'next' => $next, 'carry' => $carry, 'tasks' => $tasks,
        'day' => ['up_at' => $day['up_at'], 'bed_at' => $day['bed_at'], 'steps' => (int) ($day['steps'] ?? 0), 'weight' => $day['weight'],
                  'wake_pending' => (bool) $day['wake_due'], 'phone_uses' => (int) $day['phone_uses']],
        'night' => (function () use ($now) { $nd = night_date($now); $d = day_row($nd); return ['date' => $nd, 'bed_at' => $d['bed_at'], 'phone_uses' => (int) $d['phone_uses']]; })(),
        'weekly' => $weekly,
        'weight' => ['first' => $first === false ? null : (float) $first, 'latest' => $lastW === false ? null : (float) $lastW,
                     'goal_loss' => (float) setting('goal_weight_kg'), 'goal_date' => setting('goal_date')],
        'settings' => public_settings(),
        'tasks_cfg' => tasks_cfg(),
        'subs' => (int) db()->query('SELECT COUNT(*) FROM subs')->fetchColumn(),
        'last_pushes' => $last,
        'tomorrow_fajr' => day_times(add_days($date, 1))['fajr'],
    ];
}

function build_times(string $from, int $days): array
{
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $d = add_days($from, $i);
        $out[$d] = day_times($d);
    }
    return $out;
}

function build_review(string $period): array
{
    $days = $period === 'month' ? 30 : 7;
    $today = local_date(app_now());
    $from = add_days($today, -($days - 1));
    $db = db();

    $q = $db->prepare('SELECT * FROM prayers WHERE date BETWEEN ? AND ?');
    $q->execute([$from, $today]);
    $pr = ['fajr' => [], 'dhuhr' => [], 'asr' => [], 'maghrib' => [], 'isha' => []];
    $tot = ['prayed' => 0, 'qada' => 0, 'missed' => 0, 'masjid' => 0];
    foreach ($q->fetchAll() as $r) {
        if ($r['status'] === 'pending') continue;
        $pr[$r['prayer']][] = $r['status'];
        $tot[$r['status']]++;
        if ($r['masjid'] && in_array($r['status'], ['prayed', 'qada'], true)) $tot['masjid']++;
    }
    $per = [];
    foreach ($pr as $p => $list) {
        $n = count($list);
        $per[$p] = ['total' => $n, 'on_time' => count(array_filter($list, fn($s) => $s === 'prayed')), 'pct' => $n ? round(100 * count(array_filter($list, fn($s) => $s === 'prayed')) / $n) : null];
    }
    $done = $tot['prayed'] + $tot['qada'] + $tot['missed'];

    $q = $db->prepare('SELECT key, SUM(done) d, COUNT(*) n FROM tasks WHERE date BETWEEN ? AND ? AND key != \'tahajjud\' GROUP BY key');
    $q->execute([$from, $today]);
    $tasks = array_map(fn($r) => ['key' => $r['key'], 'done' => (int) $r['d'], 'total' => (int) $r['n'], 'pct' => $r['n'] ? round(100 * $r['d'] / $r['n']) : 0], $q->fetchAll());

    $q = $db->prepare('SELECT date, up_at, bed_at, steps, weight, phone_uses FROM days WHERE date BETWEEN ? AND ? ORDER BY date');
    $q->execute([$from, $today]);
    $rows = $q->fetchAll();
    $steps = array_values(array_filter(array_map(fn($r) => $r['steps'] === null ? null : (int) $r['steps'], $rows), fn($v) => $v !== null));
    $goal = (int) setting('goal_steps');
    $ups = array_filter(array_column($rows, 'up_at'));
    $beds = array_filter(array_column($rows, 'bed_at'));

    $q = $db->prepare('SELECT COUNT(*) FROM tasks WHERE date BETWEEN ? AND ? AND key=\'tahajjud\' AND done=1');
    $q->execute([$from, $today]);
    $tahajjud = (int) $q->fetchColumn();

    $q = $db->prepare('SELECT COUNT(*) FROM events WHERE type=\'back_asleep\' AND ts >= ?');
    $q->execute([local_ts($from, '00:00')]);
    $backAsleep = (int) $q->fetchColumn();
    $q = $db->prepare('SELECT COUNT(*) c, COALESCE(AVG(sent),0) a FROM pushes WHERE ts >= ?');
    $q->execute([local_ts($from, '00:00')]);
    $pc = $q->fetch();

    $weights = array_values(array_filter(array_map(fn($r) => $r['weight'] === null ? null : ['date' => $r['date'], 'kg' => (float) $r['weight']], $rows)));

    return [
        'period' => $period, 'from' => $from, 'to' => $today, 'days' => $days,
        'prayers' => ['total' => $tot, 'completed_windows' => $done, 'on_time_pct' => $done ? round(100 * $tot['prayed'] / $done) : null, 'per_prayer' => $per],
        'tasks' => $tasks,
        'steps' => ['avg' => $steps ? (int) round(array_sum($steps) / count($steps)) : null, 'days_hit_goal' => count(array_filter($steps, fn($s) => $s >= $goal)), 'logged_days' => count($steps), 'goal' => $goal, 'series' => array_map(fn($r) => ['date' => $r['date'], 'steps' => $r['steps'] === null ? null : (int) $r['steps']], $rows)],
        'sleep' => [
            'avg_bed' => clock_avg(array_map(fn($t) => hm_minutes((int) $t), $beds), true),
            'avg_up' => clock_avg(array_map(fn($t) => hm_minutes((int) $t), $ups), false),
            'nights_logged' => count($beds),
            'phone_uses_at_night' => array_sum(array_map('intval', array_column($rows, 'phone_uses'))),
            'back_asleep' => $backAsleep,
        ],
        'tahajjud_days' => $tahajjud,
        'weight' => $weights,
        'pushes' => ['total' => (int) $pc['c'], 'per_day' => round((int) $pc['c'] / $days, 1)],
    ];
}

function hm_minutes(int $ts): int
{
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone(tzobj());
    return (int) $d->format('G') * 60 + (int) $d->format('i');
}

/** Average clock time (minutes since midnight -> "HH:MM"). Bedtimes straddle midnight, so shift them by 12 h first. */
function clock_avg(array $mins, bool $night): ?string
{
    if (!$mins) return null;
    $shift = $night ? 720 : 0;
    $m = (int) round(array_sum(array_map(fn($x) => ($x + $shift) % 1440, $mins)) / count($mins));
    $m = ($m - $shift + 1440) % 1440;
    return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
}
