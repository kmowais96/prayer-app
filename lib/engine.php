<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/quotes.php';
require_once __DIR__ . '/push.php';

// ---------- task configuration ----------

const DEFAULT_TASKS = [
    ['key' => 'steps10k', 'label' => '10,000 steps', 'auto' => 'steps'],
    ['key' => 'quran_record', 'label' => 'Record Quran', 'office' => '18:15', 'off' => '09:30', 'ctx' => ['quran', 'task']],
    ['key' => 'seerah', 'label' => 'Read Seerah', 'office' => '19:00', 'off' => '10:30', 'ctx' => ['knowledge', 'task']],
    ['key' => 'nawawi', 'label' => 'Hadith Nawawi', 'office' => '19:30', 'off' => '11:00', 'ctx' => ['knowledge', 'task']],
    ['key' => 'tafseer', 'label' => 'Tafseer of Rahman', 'office' => '20:00', 'off' => '11:30', 'ctx' => ['knowledge', 'quran', 'task']],
    ['key' => 'tarbiyyah', 'label' => 'Tarbiyyah book', 'office' => '20:30', 'off' => '14:00', 'ctx' => ['knowledge', 'task']],
    ['key' => 'brand', 'label' => 'Build the brand', 'office' => '18:45', 'off' => '15:00', 'ctx' => ['time', 'task']],
    ['key' => 'game', 'label' => 'Islamic games', 'office' => '21:00', 'off' => '16:30', 'ctx' => ['time', 'task']],
    ['key' => 'diet', 'label' => "Follow your doctor's diet plan", 'office' => '18:00', 'off' => '10:00', 'ctx' => ['diet']],
    ['key' => 'jumuah_kahf', 'label' => 'Surah Al-Kahf (Friday)', 'office' => '09:00', 'off' => '09:00', 'dow' => [5], 'ctx' => ['quran', 'task']],
    ['key' => 'weighin', 'label' => 'Weekly weigh-in', 'office' => '17:45', 'off' => '10:00', 'dow' => [0], 'ctx' => ['weighin']],
    ['key' => 'tahajjud', 'label' => 'Tahajjud (optional)', 'optional' => true],
];

function tasks_cfg(): array
{
    $j = setting('tasks_cfg');
    $c = $j ? json_decode($j, true) : null;
    return is_array($c) && $c ? $c : DEFAULT_TASKS;
}

function task_time(array $t, string $date): ?string
{
    if (!empty($t['optional']) || !empty($t['auto'])) return null;
    return is_off_day($date) ? ($t['off'] ?? null) : ($t['office'] ?? null);
}

function ensure_day(string $date): void
{
    $db = db();
    $ins = $db->prepare('INSERT OR IGNORE INTO prayers(date,prayer) VALUES(?,?)');
    foreach (PRAYERS as $p) $ins->execute([$date, $p]);
    $it = $db->prepare('INSERT OR IGNORE INTO tasks(date,key,label) VALUES(?,?,?)');
    foreach (tasks_cfg() as $t) {
        if (isset($t['dow']) && !in_array(dow($date), $t['dow'], true)) continue;
        if (isset($t['enabled']) && !$t['enabled']) continue;
        $it->execute([$date, $t['key'], $t['label']]);
    }
    day_row($date);
}

// ---------- notification ----------

/**
 * Compose and send one notification. Non-essential ones are held back during office hours.
 * Returns true when it was sent (or attempted), so callers can advance their own timers.
 */
function notify(string $kind, string $tag, string $title, array $ctx, int $tier, array $o = []): bool
{
    $now = app_now();
    if (empty($o['essential']) && in_office_hours($now)) return false;
    $q = ($o['quote'] ?? true) ? pick_quote($ctx, $tier, $now) : null;
    $body = $o['body'] ?? '';
    if ($q) {
        $b = quote_body($q);
        $body = $body !== '' ? $body . "\n" . $b : $b;
        quote_mark_sent($q['id'], $kind, $tier, $now);
    }
    $payload = [
        'title' => $title, 'body' => $body, 'tag' => $tag, 'kind' => $kind, 'tier' => $tier,
        'url' => $o['url'] ?? '/#/today', 'qid' => $q['id'] ?? null, 'ts' => $now,
    ];
    $sent = push_all($payload, $o['ttl'] ?? 600);
    db()->prepare('INSERT INTO pushes(ts,kind,tag,title,body,qid,tier,sent) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$now, $kind, $tag, $title, $body, $payload['qid'], $tier, $sent]);
    return true;
}

function clamp(int $v, int $lo, int $hi): int { return max($lo, min($hi, $v)); }

// ---------- prayers ----------

function prayer_row(string $date, string $p): ?array
{
    $st = db()->prepare('SELECT * FROM prayers WHERE date=? AND prayer=?');
    $st->execute([$date, $p]);
    return $st->fetch() ?: null;
}

function prayer_update(string $date, string $p, array $f): void
{
    $sets = implode(',', array_map(fn($k) => "$k=:$k", array_keys($f)));
    $f['__d'] = $date;
    $f['__p'] = $p;
    db()->prepare("UPDATE prayers SET $sets WHERE date=:__d AND prayer=:__p")->execute($f);
}

function masjid_applies(string $p, string $date): bool
{
    if (setting('masjid_on') !== '1') return false;
    if (in_array($p, ['fajr', 'maghrib', 'isha'], true)) return true;
    return is_off_day($date) || dow($date) === 5; // Dhuhr/Asr only when not at the office; Jumu'ah always
}

function prayer_tier(int $elapsed, int $notyet): int
{
    $base = $elapsed < 600 ? 1 : ($elapsed < 1500 ? 2 : ($elapsed < 2700 ? 3 : ($elapsed < 4500 ? 4 : 5)));
    return clamp($base + $notyet, 1, 5);
}

function tick_prayers(int $now, string $date, bool $create): void
{
    if ($create) ensure_day($date);
    $windows = prayer_windows($date);
    $day = day_row($date);
    foreach (PRAYERS as $p) {
        $row = prayer_row($date, $p);
        if (!$row) continue;
        [$start, $end] = $windows[$p];
        $label = prayer_label($p, $date);
        $tag = 'prayer-' . $p;

        if ($row['status'] === 'pending') {
            if ($now < $start) {
                // pre-adhan push to leave for the masjid
                if (!$row['masjid_nudged'] && $now >= $start - 600 && masjid_applies($p, $date)) {
                    notify('masjid', $tag, "Leave for the masjid: $label in 10 min", ['jamaah', 'salah'], 1,
                        ['essential' => $p === 'fajr' || $p === 'maghrib' || $p === 'isha' || dow($date) === 5, 'url' => "/#/pray/$p"]);
                    prayer_update($date, $p, ['masjid_nudged' => 1]);
                }
                continue;
            }
            if ($now >= $end) {
                prayer_update($date, $p, ['status' => 'missed', 'end_sent' => 1, 'last_push' => $now]);
                notify('missed', $tag, "$label time has ended", ['qada'], 3,
                    ['essential' => true, 'body' => 'Pray it now as qada, then tell the app.', 'url' => "/#/pray/$p"]);
                continue;
            }
            // inside the window: nag until confirmed
            $elapsed = $now - $start;
            $sent = (int) $row['sent'];
            if ($sent === 0) {
                $due = true;
            } else {
                if ($p === 'fajr' && !$day['up_at'] && $elapsed < 600) $gap = 60;
                elseif ($sent <= 2) $gap = 600;
                else $gap = 300;
                $due = $now >= (int) $row['last_push'] + $gap;
            }
            if ($due) {
                $tier = prayer_tier($elapsed, (int) $row['notyet']);
                $mins = intdiv($elapsed, 60);
                $title = $sent === 0 ? "$label: time to pray" : "$label: $mins min since adhan";
                $ctx = $p === 'fajr' ? ['fajr', 'wake', 'sleep', 'salah', 'time'] : ['salah', 'time', 'jamaah'];
                $body = $sent === 0 ? '' : "Did you pray $label? Tap to answer.";
                notify('prayer', $tag, $title, $ctx, $tier, ['essential' => true, 'body' => $body, 'url' => "/#/pray/$p", 'ttl' => 240]);
                prayer_update($date, $p, ['sent' => $sent + 1, 'last_push' => $now]);
            }
            continue;
        }

        if ($row['status'] === 'missed') {
            $n = (int) $row['qada_sent'];
            if ($n < 2 && $now >= $end + ($n + 1) * 900) {
                notify('qada', $tag, "$label is still unpaid", ['qada', 'salah'], 3 + $n, ['essential' => true, 'url' => "/#/pray/$p"]);
                prayer_update($date, $p, ['qada_sent' => $n + 1]);
            }
            continue;
        }

        // prayed on time or as qada: after Fajr, keep him from going back to sleep
        if ($p === 'fajr' && $row['prayed_at']) {
            $n = (int) $row['nosleep_sent'];
            if ($n < 2 && $now >= (int) $row['prayed_at'] + ($n + 1) * 900 && $now < $windows['fajr'][1] + 5400) {
                notify('nosleep', 'nosleep', 'Do not sleep. Start your goals.', ['nosleep', 'fajr', 'time'], 2 + $n, ['essential' => true]);
                prayer_update($date, $p, ['nosleep_sent' => $n + 1]);
            }
        }
    }
}

// ---------- wake-up ----------

function tick_wake(int $now, string $date): void
{
    $day = day_row($date);
    $wake = local_ts($date, setting('office_wake'));
    // Office days: he must be up by 07:00 even if Fajr passed. Push hard until he confirms.
    if (!is_off_day($date) && !$day['up_at'] && $now >= $wake && $now < local_ts($date, setting('office_start')) + 3600) {
        if ($now >= (int) $day['wake_last'] + 120) {
            $late = intdiv($now - $wake, 60);
            $tier = clamp(3 + intdiv($late, 10), 3, 5);
            notify('wake', 'wake', $late < 2 ? 'Wake up. Office day.' : "Still not up. $late min late.", ['wake', 'fajr', 'sleep'], $tier,
                ['essential' => true, 'body' => 'Stand up and tap I am up.', 'url' => '/#/up', 'ttl' => 120]);
            day_set($date, ['wake_last' => $now]);
        }
    }
    // After he says he is up, ask again a few minutes later to be sure he did not lie back down.
    if ($day['up_at'] && $day['wake_due'] && $now >= (int) $day['wake_due']) {
        $checks = (int) $day['wake_checks'];
        if ($checks >= 3) {
            day_set($date, ['up_at' => null, 'wake_due' => null, 'wake_checks' => 0]);
            log_event('back_asleep', $date, $now);
        } else {
            notify('wakecheck', 'wake-check', 'Are you still awake? Tap to confirm.', ['wakecheck', 'wake'], clamp(2 + $checks, 2, 5),
                ['essential' => true, 'url' => '/#/up', 'ttl' => 120]);
            day_set($date, ['wake_checks' => $checks + 1, 'wake_due' => $now + 240]);
        }
    }
}

// ---------- night ----------

function tick_night(int $now): void
{
    $nd = night_date($now);
    $day = day_row($nd);
    $start = local_ts($nd, setting('night_start'));
    $nextFajr = day_times(add_days($nd, 1))['fajr'];
    if ($now < $start || $now >= $nextFajr) return;
    $lastThird = prayer_last_third(day_times($nd), $nextFajr);

    if ($now < $lastThird && !$day['bed_at']) {
        if ($now >= (int) $day['night_last'] + 1200) {
            $mins = intdiv($now - $start, 60);
            $tier = clamp(1 + intdiv($mins, 40), 1, 5);
            notify('night', 'night', 'Time to sleep', ['night', 'sleep', 'time'], $tier,
                ['essential' => true, 'body' => 'Phone away, lights out, then tap I am in bed.', 'url' => '/#/bed']);
            day_set($nd, ['night_last' => $now]);
        }
    }
    if ($now >= $lastThird) {
        $n = (int) $day['tahajjud_sent'];
        $t = db()->prepare('SELECT done FROM tasks WHERE date=? AND key=?');
        $t->execute([$nd, 'tahajjud']);
        $done = (int) $t->fetchColumn();
        if (!$done && $n < 2 && $now >= $lastThird + $n * 1200) {
            notify('tahajjud', 'tahajjud', 'Last third of the night', ['tahajjud', 'night', 'time'], 2 + $n, ['essential' => true]);
            day_set($nd, ['tahajjud_sent' => $n + 1]);
        }
    }
}

/** Called when Shortcuts reports that an app was opened. */
function handle_phone_use(int $now): void
{
    $nd = night_date($now);
    $start = local_ts($nd, setting('night_start'));
    $nextFajr = day_times(add_days($nd, 1))['fajr'];
    log_event('phone_use', null, $now);
    if ($now < $start || $now >= $nextFajr) return;
    $day = day_row($nd);
    $uses = (int) $day['phone_uses'] + 1;
    day_set($nd, ['phone_uses' => $uses]);
    if ($now >= (int) $day['last_phone_push'] + 180) {
        $tier = clamp(3 + intdiv($uses, 6), 3, 5);
        notify('night_phone', 'night-phone', 'Put the phone down.', ['night_phone', 'night', 'sleep'], $tier, ['essential' => true, 'ttl' => 120]);
        day_set($nd, ['last_phone_push' => $now]);
    }
}

// ---------- daily tasks ----------

function task_ctx(string $key): array
{
    foreach (tasks_cfg() as $t) if ($t['key'] === $key) return $t['ctx'] ?? ['task'];
    return ['task'];
}

function tick_tasks(int $now, string $date): void
{
    if ($now >= local_ts($date, setting('night_start'))) return; // from night time the sleep chain takes over
    $cfg = [];
    foreach (tasks_cfg() as $t) $cfg[$t['key']] = $t;
    $st = db()->prepare('SELECT * FROM tasks WHERE date=? AND done=0');
    $st->execute([$date]);
    $due = [];
    foreach ($st->fetchAll() as $r) {
        $t = $cfg[$r['key']] ?? null;
        $at = $t ? task_time($t, $date) : null;
        if ($at && $now >= local_ts($date, $at)) $due[] = $r + ['at' => local_ts($date, $at)];
    }
    if (!$due) return;
    // One task reminder at a time, so a pile of undone goals is not a pile of buzzing. Rotate to the least-nagged one.
    usort($due, fn($a, $b) => [-(int) $a['notyet'], (int) $a['sent'], $a['at']] <=> [-(int) $b['notyet'], (int) $b['sent'], $b['at']]);
    $r = $due[0];
    $last = (int) db()->query("SELECT MAX(last_push) FROM tasks WHERE date=" . db()->quote($date))->fetchColumn();
    $gap = (int) $r['notyet'] > 0 ? 600 : 1200;
    if ($now < $last + $gap) return;
    $tier = clamp(1 + intdiv((int) $r['sent'], 2) + (int) $r['notyet'], 1, 5);
    $more = count($due) - 1;
    $title = $r['label'] . ($more > 0 ? " (+$more more)" : '');
    if (notify('task', 'task-' . $r['key'], $title, array_merge(task_ctx($r['key']), ['task']), $tier, ['url' => '/#/tasks'])) {
        db()->prepare('UPDATE tasks SET sent=sent+1, last_push=? WHERE date=? AND key=?')->execute([$now, $date, $r['key']]);
    }
}

// ---------- steps ----------

const STEP_SLOTS_OFFICE = [['17:45', 3000], ['19:30', 6000], ['21:00', 9000]];
const STEP_SLOTS_OFF = [['11:00', 2500], ['15:00', 5500], ['18:30', 8000], ['21:00', 10000]];

function tick_steps(int $now, string $date): void
{
    $day = day_row($date);
    $goal = (int) setting('goal_steps');
    $steps = (int) ($day['steps'] ?? 0);
    if ($steps >= $goal) return;
    $slots = is_off_day($date) ? STEP_SLOTS_OFF : STEP_SLOTS_OFFICE;
    foreach ($slots as $i => [$hm, $target]) {
        if ($i <= (int) $day['steps_slot'] || $now < local_ts($date, $hm)) continue;
        day_set($date, ['steps_slot' => $i]);
        if ($steps < $target) {
            $left = number_format(max(0, $goal - $steps));
            notify('steps', 'steps', number_format($steps) . ' steps. ' . $left . ' to go.', ['steps', 'time'], 1 + $i, ['url' => '/#/today']);
        }
        break;
    }
}

// ---------- weekly inputs, reviews, pinned summary ----------

function weekly_latest(string $kind): ?array
{
    $st = db()->prepare('SELECT * FROM weekly WHERE kind=? ORDER BY ts DESC, id DESC LIMIT 1');
    $st->execute([$kind]);
    return $st->fetch() ?: null;
}

function weekly_nag(int $now, string $date, string $kind, string $title, int $from, int $until): void
{
    if ($now < $from || $now >= $until) return;
    $w = weekly_latest($kind);
    if ($w && $w['date_set'] === $date) return;
    $key = "st.weekly.$kind";
    if ($now < (int) setting($key, '0') + 1800) return;
    set_setting($key, (string) $now);
    notify('weekly', 'weekly-' . $kind, $title, ['weekly', 'knowledge'], 2, ['url' => '/#/weekly']);
}

function tick_weekly(int $now, string $date): void
{
    $dow = dow($date);
    if ($dow === 4) { // Thursday evening
        $from = local_ts($date, '19:00');
        $until = local_ts($date, '22:00');
        weekly_nag($now, $date, 'seerah', "Enter next week's Seerah part", $from, $until);
        weekly_nag($now, $date, 'hadith', "Enter next week's Hadith", $from, $until);
    }
    if ($dow === 2) { // Tuesday after Fajr
        weekly_nag($now, $date, 'tafseer', 'Enter this week\'s Tafseer of Rahman portion', day_times($date)['fajr'] + 1200, local_ts($date, '12:00'));
    }
    $day = day_row($date);
    $rev = (int) $day['review_sent'];
    $at = local_ts($date, '20:00');
    if ($now >= $at && $dow === 0 && !($rev & 1)) {
        notify('review', 'review', 'Your weekly review is ready', ['time', 'deeds'], 1, ['essential' => true, 'url' => '/#/review']);
        day_set($date, ['review_sent' => $rev | 1]);
        $rev |= 1;
    }
    if ($now >= $at && substr($date, 8, 2) === '01' && !($rev & 2)) {
        notify('review', 'review', 'Your monthly review is ready', ['time', 'deeds'], 1, ['essential' => true, 'url' => '/#/review?p=month']);
        day_set($date, ['review_sent' => $rev | 2]);
    }
}

function today_summary(string $date, int $now): array
{
    $short = ['fajr' => 'F', 'dhuhr' => 'D', 'asr' => 'A', 'maghrib' => 'M', 'isha' => 'I'];
    $title = [];
    $prayed = 0;
    $next = null;
    $w = prayer_windows($date);
    foreach (PRAYERS as $p) {
        $r = prayer_row($date, $p);
        $ok = $r && in_array($r['status'], ['prayed', 'qada'], true);
        $miss = $r && $r['status'] === 'missed';
        $title[] = $short[$p] . ($ok ? '✓' : ($miss ? '✗' : '○'));
        if ($ok) $prayed++;
        if (!$next && $now < $w[$p][0]) $next = [$p, $w[$p][0]];
    }
    $t = db()->prepare('SELECT SUM(done) d, COUNT(*) n FROM tasks WHERE date=? AND key NOT IN (\'tahajjud\')');
    $t->execute([$date]);
    $tt = $t->fetch();
    $line = ($next ? prayer_label($next[0], $date) . ' in ' . max(0, intdiv($next[1] - $now, 60)) . ' min · ' : '') . 'Tasks ' . (int) $tt['d'] . '/' . (int) $tt['n'];
    return ['title' => implode(' ', $title), 'line' => $line, 'prayed' => $prayed];
}

function tick_pinned(int $now, string $date): void
{
    $day = day_row($date);
    $from = local_ts($date, '04:00');
    $until = local_ts($date, '23:30');
    if ($now < $from || $now >= $until) return;
    $every = max(15, (int) setting('pin_every_min')) * 60;
    $dirty = (int) $day['pin_dirty'] === 1 && $now >= (int) $day['pin_last'] + 120;
    if (!$dirty && $now < (int) $day['pin_last'] + $every) return;
    $s = today_summary($date, $now);
    notify('pin', 'today', $s['title'], ['time', 'deeds', 'dunya'], 1, ['essential' => true, 'body' => $s['line'], 'ttl' => 3600]);
    day_set($date, ['pin_last' => $now, 'pin_dirty' => 0]);
}


// ---------- pulse: a fresh line every 15 minutes while awake ----------

/** Awake = tapped "I am up" today (or the Sleep Focus-off shortcut), not yet in bed, before bedtime reminders start. */
function pulse_awake(int $now, string $date): bool
{
    $d = day_row($date);
    if (!$d['up_at'] || $d['bed_at']) return false;
    return $now < local_ts($date, setting('night_start'));
}

/** Everything a pulse could be about right now. Each has a stable key so they can be rotated fairly. */
function pulse_foci(string $date, int $now): array
{
    $f = [];
    $w = prayer_windows($date);
    foreach (PRAYERS as $p) {
        [$a, $b] = $w[$p];
        $r = prayer_row($date, $p);
        $label = prayer_label($p, $date);
        if ($now >= $a && $now < $b && $r && $r['status'] === 'pending') {
            $f['prayer'] = ['title' => "Have you prayed $label?", 'body' => 'The time ends in ' . fmt_min(intdiv($b - $now, 60)) . '.',
                'ctx' => ['salah', 'time'], 'tier' => 2];
            break;
        }
        if ($now < $a) {
            $f['prayer'] = ['title' => "$label in " . fmt_min(intdiv($a - $now, 60)), 'body' => 'At ' . hm($a) . '. Be ready before the adhan.',
                'ctx' => ['salah', 'time'], 'tier' => 1];
            break;
        }
    }
    $cfg = [];
    foreach (tasks_cfg() as $t) $cfg[$t['key']] = $t;
    $st = db()->prepare("SELECT * FROM tasks WHERE date=? AND done=0 AND key NOT IN ('tahajjud','steps10k')");
    $st->execute([$date]);
    foreach ($st->fetchAll() as $r) {
        $t = $cfg[$r['key']] ?? null;
        if (!$t) continue;
        $at = task_time($t, $date);
        $later = $at && $now < local_ts($date, $at);
        $f['task:' . $r['key']] = [
            'title' => ($later ? 'Today at ' . $at . ': ' : 'Now: ') . $r['label'],
            'body' => pulse_line($later ? ['Put the time aside before it arrives.', 'Clear the way for it now, so nothing else takes the slot.', 'Decide where and how you will do it.', 'Tell yourself the plan once, then keep it.']
                : ['Ten minutes is enough to start.', 'Open it now. The start is the hard part.', 'Phone down, one thing in front of you.', 'Do it before the next prayer, not after.'], $now),
            'ctx' => array_merge($t['ctx'] ?? ['task'], ['task']),
            'tier' => clamp(1 + intdiv((int) $r['sent'], 2) + (int) $r['notyet'], 1, 4), 'task' => $r['key'],
        ];
    }
    $day = day_row($date);
    $goal = (int) setting('goal_steps');
    $steps = (int) ($day['steps'] ?? 0);
    if ($steps < $goal) {
        $f['steps'] = ['title' => 'Steps: ' . number_format($steps) . ' of ' . number_format($goal),
            'body' => number_format($goal - $steps) . ' to go. ' . pulse_line(['A short walk, with dhikr.', 'Go outside. Fresh air and a lecture.', 'Walk to the shop or the masjid instead of sitting.', 'Stand, stretch, walk a lap.'], $now), 'ctx' => ['steps'], 'tier' => 2];
    }
    $f['time'] = ['title' => 'Make this hour count', 'body' => pulse_line(['What is the one thing worth finishing before the next prayer?', 'This hour will not come back. What is it for?', 'Close what distracts you. Do the one that matters.', 'Small steps today are the life you are building.'], $now),
        'ctx' => ['time', 'deeds'], 'tier' => 2];
    return $f;
}

function pulse_line(array $opts, int $now): string
{
    return $opts[intdiv($now, 900) % count($opts)];
}

function fmt_min(int $m): string
{
    $m = max(0, $m);
    return $m >= 60 ? intdiv($m, 60) . ' h ' . ($m % 60) . ' min' : $m . ' min';
}

function tick_pulse(int $now, string $date): void
{
    if (setting('pulse_on') !== '1' || !pulse_awake($now, $date)) return;
    $every = max(10, (int) setting('pulse_every_min')) * 60;
    $day = day_row($date);
    $last = max((int) setting('pulse_last', '0'), (int) $day['up_at']);
    if ($now < $last + $every) return;
    // Never stack on top of another push in the last 4 minutes (a prayer call, a task); retry next minute.
    if ((int) db()->query('SELECT COALESCE(MAX(ts),0) FROM pushes WHERE kind<>\'pulse\'')->fetchColumn() > $now - 240) return;
    $foci = pulse_foci($date, $now);
    if (!$foci) return;
    $hist = json_decode(setting('pulse_hist', '[]'), true) ?: [];
    if (isset($foci['prayer']) && !in_array('prayer', array_slice($hist, -3), true)) {
        $key = 'prayer';
    } else {
        $key = null; $oldest = PHP_INT_MAX;
        foreach (array_keys($foci) as $k) {
            $pos = array_search($k, array_reverse($hist), true);
            $age = $pos === false ? PHP_INT_MAX : $pos;      // never used, or how many pulses ago
            if ($key === null || $age > $oldest) { $key = $k; $oldest = $age; }
        }
    }
    $p = $foci[$key];
    if (notify('pulse', 'pulse', $p['title'], $p['ctx'], $p['tier'], ['essential' => true, 'body' => $p['body'], 'ttl' => 900])) {
        $hist[] = $key;
        set_setting('pulse_hist', json_encode(array_slice($hist, -12)));
        set_setting('pulse_last', (string) $now);
        if (!empty($p['task'])) db()->prepare('UPDATE tasks SET sent=sent+1 WHERE date=? AND key=?')->execute([$date, $p['task']]);
    }
}

// ---------- tick ----------

function engine_tick(?int $now = null): void
{
    $now ??= app_now();
    $GLOBALS['APP_NOW'] = $now;
    $date = local_date($now);
    ensure_day($date);
    $steps = [
        fn() => tick_prayers($now, add_days($date, -1), false),
        fn() => tick_prayers($now, $date, true),
        fn() => tick_wake($now, $date),
        fn() => tick_night($now),
        fn() => tick_tasks($now, $date),
        fn() => tick_steps($now, $date),
        fn() => tick_weekly($now, $date),
        fn() => tick_pulse($now, $date),
        fn() => tick_pinned($now, $date),
    ];
    foreach ($steps as $i => $s) {
        try { $s(); } catch (Throwable $e) {
            error_log("tick step $i failed: " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        }
    }
}

// ---------- actions used by the API ----------

function act_prayed(string $date, string $p, bool $masjid, bool $undo = false): array
{
    $now = app_now();
    ensure_day($date);
    [$start, $end] = prayer_windows($date)[$p];
    if ($undo) {
        prayer_update($date, $p, ['status' => $now >= $end ? 'missed' : 'pending', 'prayed_at' => null, 'masjid' => 0]);
    } else {
        prayer_update($date, $p, ['status' => $now <= $end ? 'prayed' : 'qada', 'prayed_at' => $now, 'masjid' => $masjid ? 1 : 0]);
        if ($p === 'fajr') {
            $d = day_row($date);
            if (!$d['up_at']) day_set($date, ['up_at' => $now]);
            day_set($date, ['wake_due' => null, 'wake_checks' => 0]); // a prayed Fajr settles the wake check
        }
    }
    day_set($date, ['pin_dirty' => 1]);
    log_event($undo ? 'unprayed' : 'prayed', "$date $p", $now);
    return prayer_row($date, $p);
}

function act_notyet_prayer(string $date, string $p): void
{
    $now = app_now();
    $r = prayer_row($date, $p);
    if (!$r) return;
    // fire the next reminder in about two minutes, one tier harder
    prayer_update($date, $p, ['notyet' => (int) $r['notyet'] + 1, 'last_push' => $now - 300 + 120]);
    log_event('notyet', "$date $p", $now);
}

function act_task_toggle(string $date, string $key, ?bool $done = null): array
{
    $now = app_now();
    ensure_day($date);
    $st = db()->prepare('SELECT * FROM tasks WHERE date=? AND key=?');
    $st->execute([$date, $key]);
    $r = $st->fetch();
    if (!$r) return [];
    $new = $done === null ? !(bool) $r['done'] : $done;
    db()->prepare('UPDATE tasks SET done=?, done_at=? WHERE date=? AND key=?')->execute([(int) $new, $new ? $now : null, $date, $key]);
    day_set($date, ['pin_dirty' => 1]);
    log_event($new ? 'task_done' : 'task_undone', "$date $key", $now);
    return ['key' => $key, 'done' => $new];
}

function act_notyet_task(string $date, string $key): void
{
    $now = app_now();
    db()->prepare('UPDATE tasks SET notyet=notyet+1, last_push=? WHERE date=? AND key=?')->execute([$now - 1200 + 120, $date, $key]);
    log_event('notyet', "$date $key", $now);
}

function act_up(): array
{
    $now = app_now();
    $date = local_date($now);
    ensure_day($date);
    $d = day_row($date);
    if ($now < day_times($date)['fajr'] - 5400) {
        log_event('up_early', null, $now);
        return ['ok' => true, 'note' => 'Too early to count as the morning wake-up.'];
    }
    if (!$d['up_at']) {
        day_set($date, ['up_at' => $now, 'wake_due' => $now + 240, 'wake_checks' => 0, 'pin_dirty' => 1]);
        log_event('up', null, $now);
        return ['ok' => true, 'check_in' => 240];
    }
    day_set($date, ['wake_due' => null, 'wake_checks' => 0]);
    log_event('up_confirm', null, $now);
    return ['ok' => true, 'confirmed' => true];
}

function act_bed(): array
{
    $now = app_now();
    $nd = night_date($now);
    $d = day_row($nd);
    if (!$d['bed_at']) day_set($nd, ['bed_at' => $now, 'pin_dirty' => 1]);
    log_event('bed', null, $now);
    return ['ok' => true, 'night' => $nd];
}

function act_event(string $type, $val): array
{
    $now = app_now();
    $date = local_date($now);
    ensure_day($date);
    switch ($type) {
        case 'steps':
            $n = (int) $val;
            day_set($date, ['steps' => $n, 'pin_dirty' => 1]);
            if ($n >= (int) setting('goal_steps')) act_task_toggle($date, 'steps10k', true);
            log_event('steps', $n, $now);
            break;
        case 'phone_use':
            handle_phone_use($now);
            break;
        case 'sleep_focus':
        case 'bed':
            return act_bed();
        case 'wake':
            return act_up();
        case 'weight':
            day_set($date, ['weight' => (float) $val]);
            act_task_toggle($date, 'weighin', true);
            log_event('weight', $val, $now);
            break;
        default:
            log_event($type, is_scalar($val) ? $val : json_encode($val), $now);
    }
    return ['ok' => true];
}
