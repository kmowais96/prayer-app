<?php
declare(strict_types=1);
// Run every minute: * * * * * php /path/to/cron/tick.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/state.php';

@mkdir(APP_ROOT . '/data', 0700, true);
$lock = fopen(APP_ROOT . '/data/tick.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0); // a previous run is still going
ini_set('log_errors', '1');
ini_set('error_log', APP_ROOT . '/data/tick.log');
try {
    engine_tick(time());
} catch (Throwable $e) {
    error_log('tick failed: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
}
$log = APP_ROOT . '/data/tick.log';
if (is_file($log) && filesize($log) > 262144) file_put_contents($log, substr((string) file_get_contents($log), -65536));
