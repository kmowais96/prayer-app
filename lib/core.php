<?php
declare(strict_types=1);

require_once __DIR__ . '/prayer.php';

const APP_ROOT = __DIR__ . '/..';
const PRAYERS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha'];

// ---------- database ----------

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $path = getenv('PRAYER_DB') ?: APP_ROOT . '/data/app.sqlite';
    if ($path !== ':memory:') {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $deny = $dir . '/.htaccess';
        if (!file_exists($deny)) file_put_contents($deny, "Require all denied\nDeny from all\n");
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $db): void
{
    $v = (int) $db->query('PRAGMA user_version')->fetchColumn();
    if ($v < 1) {
        $db->exec(<<<'SQL'
CREATE TABLE settings(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE tokens(hash TEXT PRIMARY KEY, created INTEGER, ua TEXT);
CREATE TABLE subs(id INTEGER PRIMARY KEY, endpoint TEXT UNIQUE, p256dh TEXT, auth TEXT, ua TEXT, created INTEGER);
CREATE TABLE prayers(
  date TEXT, prayer TEXT, status TEXT DEFAULT 'pending', prayed_at INTEGER, masjid INTEGER DEFAULT 0,
  sent INTEGER DEFAULT 0, last_push INTEGER DEFAULT 0, notyet INTEGER DEFAULT 0,
  masjid_nudged INTEGER DEFAULT 0, end_sent INTEGER DEFAULT 0, qada_sent INTEGER DEFAULT 0, nosleep_sent INTEGER DEFAULT 0,
  PRIMARY KEY(date, prayer));
CREATE TABLE tasks(
  date TEXT, key TEXT, label TEXT, done INTEGER DEFAULT 0, done_at INTEGER,
  sent INTEGER DEFAULT 0, last_push INTEGER DEFAULT 0, notyet INTEGER DEFAULT 0,
  PRIMARY KEY(date, key));
CREATE TABLE days(
  date TEXT PRIMARY KEY, up_at INTEGER, wake_due INTEGER, wake_checks INTEGER DEFAULT 0, wake_last INTEGER DEFAULT 0,
  bed_at INTEGER, steps INTEGER, weight REAL, phone_uses INTEGER DEFAULT 0, last_phone_push INTEGER DEFAULT 0,
  night_last INTEGER DEFAULT 0, tahajjud_sent INTEGER DEFAULT 0, steps_slot INTEGER DEFAULT -1, pin_last INTEGER DEFAULT 0,
  pin_dirty INTEGER DEFAULT 0, review_sent INTEGER DEFAULT 0, note TEXT);
CREATE TABLE events(id INTEGER PRIMARY KEY, ts INTEGER, type TEXT, val TEXT);
CREATE INDEX events_type_ts ON events(type, ts);
CREATE TABLE weekly(id INTEGER PRIMARY KEY, kind TEXT, date_set TEXT, text TEXT, ts INTEGER);
CREATE TABLE quotes(id TEXT PRIMARY KEY, en TEXT, src TEXT, who TEXT, grade TEXT, kind TEXT, tags TEXT, tier INTEGER);
CREATE TABLE quote_log(id INTEGER PRIMARY KEY, qid TEXT, ts INTEGER, kind TEXT, tier INTEGER);
CREATE INDEX quote_log_q ON quote_log(qid, ts);
CREATE TABLE pushes(id INTEGER PRIMARY KEY, ts INTEGER, kind TEXT, tag TEXT, title TEXT, body TEXT, qid TEXT, tier INTEGER, sent INTEGER);
SQL);
        $db->exec('PRAGMA user_version = 1');
    }
    if ($v < 2) {
        // When the app (or the cron) first laid eyes on this day. Used to stay silent about
        // prayer windows that had already closed before the app could ask about them.
        $db->exec('ALTER TABLE days ADD COLUMN seen_ts INTEGER');
        $db->exec('UPDATE days SET seen_ts = COALESCE(up_at, 0) WHERE seen_ts IS NULL');
        $db->exec('PRAGMA user_version = 2');
    }
}

// ---------- settings ----------

const DEFAULTS = [
    'lat' => '29.3759', 'lng' => '47.9774', 'tz' => 'Asia/Kuwait', 'asr_factor' => '1', // Kuwait City until the app sets a real location
    'calc_method' => 'kuwait', // Ministry of Awqaf and Islamic Affairs; see PT_METHODS in prayer.php
    'office_start' => '08:00', 'office_end' => '17:30', 'off_days' => '5,6',           // Fri, Sat
    'night_start' => '22:00', 'office_wake' => '07:00', 'pin_every_min' => '120',
    'norepeat_days' => '30', 'masjid_on' => '1', 'site_url' => 'https://app.kwisdomacademy.com',
    'pulse_on' => '1', 'pulse_every_min' => '15', 'goal_steps' => '10000', 'goal_weight_kg' => '15', 'goal_date' => '2026-11-30',
];

function setting(string $k, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k=?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? (DEFAULTS[$k] ?? $default) : $v;
}

function set_setting(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')->execute([$k, $v]);
}

// ---------- time ----------

function app_now(): int { return $GLOBALS['APP_NOW'] ?? time(); }

function tzobj(): DateTimeZone { return new DateTimeZone(setting('tz')); }

function local_date(int $ts): string { return (new DateTimeImmutable('@' . $ts))->setTimezone(tzobj())->format('Y-m-d'); }

function local_ts(string $date, string $hm): int { return (new DateTimeImmutable("$date $hm", tzobj()))->getTimestamp(); }

function add_days(string $date, int $n): string
{
    return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
}

/** 0 = Sunday ... 6 = Saturday */
function dow(string $date): int { return (int) (new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('w'); }

function hm(int $ts): string { return (new DateTimeImmutable('@' . $ts))->setTimezone(tzobj())->format('H:i'); }

function is_off_day(string $date): bool { return in_array((string) dow($date), explode(',', setting('off_days')), true); }

function in_office_hours(int $ts): bool
{
    $d = local_date($ts);
    if (is_off_day($d)) return false;
    return $ts >= local_ts($d, setting('office_start')) && $ts < local_ts($d, setting('office_end'));
}

// ---------- prayer windows ----------

function day_times(string $date): array
{
    static $cache = [];
    $key = $date . '|' . setting('lat') . '|' . setting('lng') . '|' . setting('asr_factor') . '|' . setting('calc_method');
    return $cache[$key] ??= prayer_times($date, (float) setting('lat'), (float) setting('lng'), setting('tz'),
        (int) setting('asr_factor'), 0, (string) setting('calc_method'));
}

/** [prayer => [start, end]] ; Isha ends at the middle of the night. */
function prayer_windows(string $date): array
{
    $t = day_times($date);
    $nextFajr = day_times(add_days($date, 1))['fajr'];
    $midnight = (int) ($t['maghrib'] + ($nextFajr - $t['maghrib']) / 2);
    return [
        'fajr' => [$t['fajr'], $t['sunrise']],
        'dhuhr' => [$t['dhuhr'], $t['asr']],
        'asr' => [$t['asr'], $t['maghrib']],
        'maghrib' => [$t['maghrib'], $t['isha']],
        'isha' => [$t['isha'], $midnight],
    ];
}

function prayer_label(string $p, string $date): string
{
    if ($p === 'dhuhr' && dow($date) === 5) return "Jumu'ah";
    return ucfirst($p);
}

/** The "night" that contains $ts is keyed by the date its evening began (before Fajr it is yesterday's). */
function night_date(int $ts): string
{
    $d = local_date($ts);
    return $ts < day_times($d)['fajr'] ? add_days($d, -1) : $d;
}

function day_row(string $date): array
{
    db()->prepare('INSERT OR IGNORE INTO days(date) VALUES(?)')->execute([$date]);
    $st = db()->prepare('SELECT * FROM days WHERE date=?');
    $st->execute([$date]);
    return $st->fetch();
}

function day_set(string $date, array $f): void
{
    day_row($date);
    $sets = implode(',', array_map(fn($k) => "$k=:$k", array_keys($f)));
    $st = db()->prepare("UPDATE days SET $sets WHERE date=:__d");
    $f['__d'] = $date;
    $st->execute($f);
}

function log_event(string $type, $val = null, ?int $ts = null): void
{
    db()->prepare('INSERT INTO events(ts,type,val) VALUES(?,?,?)')->execute([$ts ?? app_now(), $type, $val === null ? null : (string) $val]);
}
