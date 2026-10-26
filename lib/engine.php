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
    $day = day_row($date);
    if ($day['seen_ts'] === null) day_set($date, ['seen_ts' => app_now()]);
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
/**
 * A locked iPhone shows about 30 characters of title and two short lines of body.
 * Past that the phone cuts mid-word, so cut here instead, on a word, and keep the
 * useful half first. These are the only length rules in the app.
 */
const T_MAX = 32;
const B_MAX = 110;

function cap(string $s, int $max): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max - 1);
    $sp = mb_strrpos($cut, ' ');
    $end = ($sp !== false && $sp > $max / 2) ? $sp : $max - 1;
    return rtrim(mb_substr($cut, 0, $end), " ,.;:") . '…';
}

function notify(string $kind, string $tag, string $title, array $ctx, int $tier, array $o = []): bool
{
    $now = app_now();
    if (empty($o['essential']) && in_office_hours($now)) return false;
    $title = cap($title, $o['tmax'] ?? T_MAX);
    $body = cap((string) ($o['body'] ?? ''), B_MAX);
    // A quote only rides along when it fits in what is left of the two body lines.
    // That is what keeps a reminder readable at a glance instead of a wall of text.
    $q = null;
    if ($o['quote'] ?? true) {
        $room = ($o['qmax'] ?? B_MAX) - ($body === '' ? 0 : mb_strlen($body) + 1);
        if ($room >= 45) $q = pick_quote($ctx, $tier, $now, $room);
    }
    if ($q) {
        $b = quote_body($q, max(45, ($o['qmax'] ?? B_MAX) - ($body === '' ? 0 : mb_strlen($body) + 1)));
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
                // The window has closed. If he was never asked inside it - a fresh install, or the
                // cron was down - there is nothing to escalate. Record the gap, say nothing: a push
                // about this morning's Fajr arriving in the evening is noise, not a reminder.
                $asked = (int) $row['sent'] > 0;
                prayer_update($date, $p, ['status' => 'missed', 'end_sent' => $asked ? 1 : 0,
                    'qada_sent' => $asked ? (int) $row['qada_sent'] : 2, 'last_push' => $now]);
                if ($asked) {
                    notify('missed', $tag, "$label time has ended", ['qada'], 3,
                        ['essential' => true, 'body' => 'Pray it as qada now, then tap it in.', 'url' => "/#/pray/$p"]);
                }
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
                // Time left, not time gone: a closing window moves a person, a counter does not.
                $title = $sent === 0 ? "$label: time to pray" : "$label ends in " . fmt_min(intdiv($end - $now, 60));
                $ctx = $p === 'fajr' ? ['fajr', 'wake', 'sleep', 'salah', 'time'] : ['salah', 'time', 'jamaah'];
                $body = $sent === 0 ? '' : 'Wudu, pray, then tap it in.';
                notify('prayer', $tag, $title, $ctx, $tier, ['essential' => true, 'body' => $body, 'url' => "/#/pray/$p", 'ttl' => 240]);
                prayer_update($date, $p, ['sent' => $sent + 1, 'last_push' => $now]);
            }
            continue;
        }

        if ($row['status'] === 'missed') {
            $n = (int) $row['qada_sent'];
            // Two reminders, inside two hours of the window closing. After that it is history,
            // and history belongs on the review screen, not on the lock screen.
            if ($n < 2 && $now >= $end + ($n + 1) * 900 && $now < $end + 7200) {
                notify('qada', $tag, "$label is still unpaid", ['qada', 'salah'], 3 + $n,
                    ['essential' => true, 'body' => 'Pray it now, then tap it in.', 'url' => "/#/pray/$p"]);
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
    // Only the latest slot that is due matters. Skipping straight to it means a day first
    // seen in the evening gets one step reminder, not one for every slot it slept through.
    $last = -1;
    foreach ($slots as $i => [$hm,]) if ($now >= local_ts($date, $hm)) $last = $i;
    foreach ($slots as $i => [$hm, $target]) {
        if ($i !== $last || $i <= (int) $day['steps_slot']) continue;
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
    foreach (PRAYERS as $p) {
        $r = prayer_row($date, $p);
        $ok = $r && in_array($r['status'], ['prayed', 'qada'], true);
        $miss = $r && $r['status'] === 'missed';
        $title[] = $short[$p] . ($ok ? '✓' : ($miss ? '✗' : '○'));
        if ($ok) $prayed++;
    }
    $pf = prayer_focus($date, $now);
    $t = db()->prepare('SELECT SUM(done) d, COUNT(*) n FROM tasks WHERE date=? AND key NOT IN (\'tahajjud\')');
    $t->execute([$date]);
    $tt = $t->fetch();
    $head = $pf['state'] === 'due'
        ? $pf['label'] . ' now'
        : $pf['label'] . ' ' . ($pf['state'] === 'tomorrow' ? hm($pf['start']) : 'in ' . fmt_min(intdiv($pf['start'] - $now, 60)));
    $line = $head . ' · Tasks ' . (int) $tt['d'] . '/' . (int) $tt['n'];
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


// ---------- pulse: a fresh line every few minutes while awake ----------

/**
 * The prayer the clock is actually on right now. Three states:
 *   due      - the window is open and he has not confirmed it
 *   soon     - the next adhan today
 *   tomorrow - after Isha has begun and every window today is settled
 * It is never a prayer whose time has already gone. That was the "Fajr at 6pm" bug.
 */
function prayer_focus(string $date, int $now): array
{
    $w = prayer_windows($date);
    foreach (PRAYERS as $p) {
        [$a, $b] = $w[$p];
        if ($now >= $b) continue;                       // gone: never speak about it again
        $r = prayer_row($date, $p);
        if ($now >= $a) {
            if ($r && $r['status'] === 'pending') {
                return ['p' => $p, 'label' => prayer_label($p, $date), 'state' => 'due', 'start' => $a, 'end' => $b];
            }
            continue;                                   // already prayed or recorded: look further ahead
        }
        return ['p' => $p, 'label' => prayer_label($p, $date), 'state' => 'soon', 'start' => $a, 'end' => $b];
    }
    $tom = add_days($date, 1);
    $f = prayer_windows($tom)['fajr'];
    return ['p' => 'fajr', 'label' => 'Fajr', 'state' => 'tomorrow', 'start' => $f[0], 'end' => $f[1]];
}

/**
 * When he is awake, without needing him to tap anything.
 * Best signal first: he tapped "I am up"; else he prayed Fajr in the app; else presume the
 * usual waking hour (later on a day off). Ends when he taps "I am in bed", or at bedtime.
 */
function awake_window(string $date, int $now): array
{
    $d = day_row($date);
    $until = $d['bed_at'] ? (int) $d['bed_at'] : local_ts($date, setting('night_start'));
    if ($d['up_at']) return [(int) $d['up_at'], $until];
    $fajr = prayer_row($date, 'fajr');
    if ($fajr && $fajr['prayed_at']) return [(int) $fajr['prayed_at'], $until];
    $presume = local_ts($date, setting('office_wake')) + (is_off_day($date) ? 7200 : 0);
    return [$presume, $until];
}

function pulse_awake(int $now, string $date): bool
{
    [$from, $until] = awake_window($date, $now);
    return $now >= $from && $now < $until;
}

/**
 * The $n-th phrasing for a focus, or null once its phrasings are used up for the day.
 * Null is the signal to let a quote carry the line instead, which is what keeps "a new line
 * every time" true: the inline action lines are finite, the quote library is not.
 */
function rot(array $opts, int $n): ?string
{
    return $n < count($opts) ? $opts[$n] : null;
}

/** "40 min", "1 h 20 min", "4 h" - past two hours the minutes stop mattering. */
function fmt_min(int $m): string
{
    $m = max(0, $m);
    if ($m < 60) return $m . ' min';
    $h = intdiv($m, 60);
    $r = $m % 60;
    return ($r && $h < 2) ? $h . ' h ' . $r . ' min' : $h . ' h';
}

/**
 * Everything a pulse could be about right now, keyed so they can be rotated fairly.
 * $used says how many times each focus has already spoken today, so each one works through
 * its phrasings before handing over to the quote library.
 *
 * Every line is written as an implementation intention - when, where, how - because a specific
 * next action is what moves a person. "Be productive" does not.
 */
function pulse_foci(string $date, int $now, array $used = []): array
{
    $f = [];
    $n = fn(string $k) => (int) ($used[$k] ?? 0);

    $pf = prayer_focus($date, $now);
    $near = $pf['state'] === 'due' || $pf['state'] === 'tomorrow' || $pf['start'] - $now <= 5400;
    if ($near && $pf['state'] === 'due') {
        $left = intdiv($pf['end'] - $now, 60);
        $f['prayer'] = [
            'title' => $pf['label'] . ': ' . fmt_min($left) . ' left',
            'body' => rot(['Stop, wudu, pray. Then come back to this.', 'Go now. It gets heavier the longer you sit.',
                'Phone down, wudu, pray. Two minutes to start.', 'Pray it before you do anything else.',
                'Leave what you are on. It will wait.', 'Masjid if you can, home if you cannot. Now either way.'], $n('prayer')),
            'ctx' => ['salah', 'time'], 'tier' => $left < 20 ? 4 : 2, 'url' => '/#/pray/' . $pf['p'],
        ];
    } elseif ($near && $pf['state'] === 'soon') {
        $in = intdiv($pf['start'] - $now, 60);
        $f['prayer'] = [
            'title' => $pf['label'] . ' in ' . fmt_min($in),
            'body' => $in <= 20
                ? 'At ' . hm($pf['start']) . '. Wudu now, be ready before the adhan.'
                : 'At ' . hm($pf['start']) . '. ' . (rot(['Finish what you are on before then.', 'Plan to be at the masjid, not on the way.',
                    'Leave the last ten minutes free.', 'Decide now what you finish before it.',
                    'Wrap up early and go with time to spare.'], $n('prayer')) ?? 'Be ready before the adhan.'),
            'ctx' => ['salah', 'time'], 'tier' => 1, 'url' => '/#/today',
        ];
    } elseif ($near) {
        $f['prayer'] = [
            'title' => 'Fajr ' . hm($pf['start']) . ' tomorrow',
            'body' => rot(['Sleep early tonight and you win the morning.', 'Phone on charge away from the bed.',
                'Set nothing else. Just be up for it.', 'The day starts there. Protect it.',
                'Witr, then sleep. Nothing after that.'], $n('prayer')),
            'ctx' => ['fajr', 'sleep', 'night'], 'tier' => 2, 'url' => '/#/today',
        ];
    }

    $cfg = [];
    foreach (tasks_cfg() as $t) $cfg[$t['key']] = $t;
    $st = db()->prepare("SELECT * FROM tasks WHERE date=? AND done=0 AND key NOT IN ('tahajjud','steps10k')");
    $st->execute([$date]);
    foreach ($st->fetchAll() as $r) {
        $t = $cfg[$r['key']] ?? null;
        if (!$t) continue;
        $key = 'task:' . $r['key'];
        $at = task_time($t, $date);
        $later = $at && $now < local_ts($date, $at);
        // Nine tasks share one phrasing list, so a per-task list would collide across tasks.
        // Each task gets its one specific instruction, then hands over to the quote library,
        // which is where "a new line every time" actually comes from.
        $line = $n($key) > 0 ? null
            : ($later ? 'Keep ' . $at . ' free for it. Decide now where you will sit.'
                      : 'Ten minutes, starting now. Phone in another room.');
        // "Read Seerah — not done" fits; "Follow your doctor's diet plan — not done" does not,
        // and a title cut mid-word reads like a bug. The body already says what to do.
        $nowTitle = mb_strlen($r['label']) + 6 <= T_MAX ? $r['label'] . ' — now' : $r['label'];
        $f[$key] = [
            'title' => $later ? $at . ': ' . $r['label'] : $nowTitle,
            'body' => $line,
            'ctx' => array_merge($t['ctx'] ?? ['task'], ['task']),
            'tier' => clamp(1 + intdiv((int) $r['sent'], 2) + (int) $r['notyet'], 1, 4), 'task' => $r['key'], 'url' => '/#/tasks',
        ];
    }

    $day = day_row($date);
    $goal = (int) setting('goal_steps');
    $steps = (int) ($day['steps'] ?? 0);
    if ($steps < $goal) {
        $line = rot(['Walk 20 minutes after the next prayer.', 'Go outside now with a lecture on.',
            'Walk to the masjid instead of driving.', 'Stand up and do one lap now.',
            'Park further away and walk the rest.'], $n('steps'));
        $f['steps'] = [
            'title' => 'Steps ' . number_format($steps) . ' / ' . number_format($goal),
            'body' => $line === null ? null : number_format($goal - $steps) . ' to go. ' . $line,
            'ctx' => ['steps'], 'tier' => 2, 'url' => '/#/today',
        ];
    }

    $kg = (float) setting('goal_weight_kg');
    $target = $kg > 0 ? '-' . rtrim(rtrim(number_format($kg, 1), '0'), '.') . ' kg by ' . setting('goal_date') : '';
    $f['goal'] = [
        'title' => rot(['Why you started', 'The goal, out loud', 'Remember the target', 'Keep the aim in view'], $n('goal') % 4),
        'body' => rot([
            $target !== '' ? $target . '. Earned this hour, not next month.' : 'Your goal is earned this hour, not next month.',
            'Prayer on time, food as the doctor said, body moving. Today.',
            'One hour done well, five times a day. That is the whole plan.',
            'You are building the man you will be in a year.',
            'Nobody else is going to do this for you.',
        ], $n('goal')),
        'ctx' => ['deeds', 'time', 'dunya'], 'tier' => 2, 'url' => '/#/today',
    ];

    $f['time'] = [
        'title' => rot(['Make this hour count', 'What is this hour for?', 'One thing, finished', 'Use it before it goes'], $n('time') % 4),
        'body' => rot(['Name the one thing you finish before the next adhan.', 'Close what distracts you. Start the one that matters.',
            'This hour does not come back. Spend it on purpose.', 'Pick one task and finish it, not three and none.',
            'Choose the hard one first. The rest gets easy.'], $n('time')),
        'ctx' => ['time', 'deeds'], 'tier' => 2, 'url' => '/#/today',
    ];
    return $f;
}

function tick_pulse(int $now, string $date): void
{
    if (setting('pulse_on') !== '1' || !pulse_awake($now, $date)) return;
    $every = max(10, (int) setting('pulse_every_min')) * 60;
    [$from] = awake_window($date, $now);
    $last = max((int) setting('pulse_last', '0'), $from);
    if ($now < $last + $every) return;
    // Never stack on top of another push in the last 4 minutes (a prayer call, a task); retry next minute.
    if ((int) db()->query('SELECT COALESCE(MAX(ts),0) FROM pushes WHERE kind<>\'pulse\'')->fetchColumn() > $now - 240) return;

    $hist = json_decode(setting('pulse_hist', '[]'), true) ?: [];
    $used = json_decode(setting('pulse_used', '{}'), true) ?: [];
    if ((setting('pulse_used_date') ?? '') !== $date) { $used = []; }
    $foci = pulse_foci($date, $now, $used);
    if (!$foci) return;

    // An open prayer window outranks everything, but not twice in a row.
    $due = isset($foci['prayer']) && str_contains($foci['prayer']['title'], 'left');
    if ($due && !in_array('prayer', array_slice($hist, -2), true)) {
        $key = 'prayer';
    } else {
        $key = null; $oldest = -1;
        foreach (array_keys($foci) as $k) {
            $pos = array_search($k, array_reverse($hist), true);
            $age = $pos === false ? PHP_INT_MAX : $pos;      // never used, or how many pulses ago
            if ($age > $oldest) { $key = $k; $oldest = $age; }
        }
    }
    $p = $foci[$key];

    // One short action line, or one short quote - never both, or it stops being readable at a glance.
    $opts = ['essential' => true, 'url' => $p['url'] ?? '/#/today', 'ttl' => 900];
    $turn = (int) ($used[$key] ?? 0);
    if ($p['body'] === null || $turn % 3 === 2) {
        $opts['body'] = '';
        $opts['qmax'] = 95;
    } else {
        $opts['body'] = $p['body'];
        $opts['quote'] = false;
    }
    if (notify('pulse', 'pulse', $p['title'], $p['ctx'], $p['tier'], $opts)) {
        $hist[] = $key;
        $used[$key] = $turn + 1;
        set_setting('pulse_hist', json_encode(array_slice($hist, -12)));
        set_setting('pulse_used', json_encode($used));
        set_setting('pulse_used_date', $date);
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
    $errs = [];
    foreach ($steps as $i => $s) {
        try { $s(); } catch (Throwable $e) {
            $errs[] = "step $i: " . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
            error_log("tick step $i failed: " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
        }
    }
    tick_diag($now, $date, $errs);
}

/**
 * One line of state per tick, so a reminder that never arrives can be diagnosed from the
 * server instead of guessed at. Overwritten every minute; data/ is never served (see .htaccess).
 */
function tick_diag(int $now, string $date, array $errs = []): void
{
    try {
        [$from, $until] = awake_window($date, $now);
        $db = db();
        $lastOther = (int) $db->query('SELECT COALESCE(MAX(ts),0) FROM pushes WHERE kind<>\'pulse\'')->fetchColumn();
        $st = $db->prepare('SELECT kind, COUNT(*) c, MAX(ts) last FROM pushes WHERE ts>=? GROUP BY kind');
        $st->execute([local_ts($date, '00:00')]);
        $today = [];
        foreach ($st->fetchAll() as $r) $today[$r['kind']] = $r['c'] . ' last ' . hm((int) $r['last']);
        $d = day_row($date);
        $t = $db->prepare('SELECT COUNT(*) n, SUM(done) d FROM tasks WHERE date=?');
        $t->execute([$date]);
        $tc = $t->fetch();
        @file_put_contents(APP_ROOT . '/data/tick.json', json_encode([
            'at' => hm($now), 'date' => $date, 'office' => in_office_hours($now) ? 'yes' : 'no',
            'pulse_on' => setting('pulse_on'), 'every_min' => setting('pulse_every_min'),
            'pulse_last' => ((int) setting('pulse_last', '0')) ? hm((int) setting('pulse_last')) : 'never',
            'awake' => hm($from) . '-' . hm($until) . ($now >= $from && $now < $until ? ' (in)' : ' (OUT)'),
            'up_at' => $d['up_at'] ? hm((int) $d['up_at']) : null, 'bed_at' => $d['bed_at'] ? hm((int) $d['bed_at']) : null,
            'last_non_pulse_push' => $lastOther ? hm($lastOther) . ' (' . intdiv($now - $lastOther, 60) . ' min ago)' : 'never',
            'pushes_today' => $today, 'tasks' => (int) $tc['n'] . ' of which done ' . (int) $tc['d'],
            'subs' => (int) $db->query('SELECT COUNT(*) FROM subs')->fetchColumn(),
            'errors' => $errs,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) { /* diagnostics must never break a tick */ }
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
