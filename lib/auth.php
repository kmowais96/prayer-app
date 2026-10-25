<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';

function token_hash(string $t): string { return hash('sha256', $t); }

function issue_token(): string
{
    $t = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO tokens(hash,created,ua) VALUES(?,?,?)')->execute([token_hash($t), app_now(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120)]);
    return $t;
}

function request_token(): ?string
{
    $h = $_SERVER['HTTP_X_AUTH'] ?? '';
    if ($h === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) $h = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
    return $h !== '' ? $h : null;
}

function token_ok(?string $t): bool
{
    if (!$t) return false;
    $st = db()->prepare('SELECT 1 FROM tokens WHERE hash=?');
    $st->execute([token_hash($t)]);
    return (bool) $st->fetchColumn();
}

function event_key_ok(?string $k): bool
{
    $real = setting('event_key');
    return $k && $real && hash_equals($real, $k);
}

function configured(): bool { return (bool) setting('pass_hash'); }

/** Claim the app with a passcode. Works exactly once. */
function do_claim(string $pass): ?array
{
    if (configured() || strlen($pass) < 6) return null;
    set_setting('pass_hash', password_hash($pass, PASSWORD_DEFAULT));
    set_setting('event_key', bin2hex(random_bytes(20)));
    return ['token' => issue_token(), 'event_key' => setting('event_key')];
}

function do_login(string $pass): ?array
{
    $now = app_now();
    if ((int) setting('login_lock', '0') > $now) return null;
    $hash = setting('pass_hash');
    if ($hash && password_verify($pass, $hash)) {
        set_setting('login_fail', '0');
        return ['token' => issue_token(), 'event_key' => setting('event_key')];
    }
    $n = (int) setting('login_fail', '0') + 1;
    set_setting('login_fail', (string) $n);
    if ($n >= 5) { set_setting('login_lock', (string) ($now + 300)); set_setting('login_fail', '0'); }
    return null;
}
