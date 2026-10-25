<?php
// Usage: php tests/prayer_test.php path/to/ref.json  (reference times from the `adhan` package)
require __DIR__ . '/../lib/prayer.php';
$ref = json_decode(file_get_contents($argv[1]), true);
$fail = 0;
foreach ($ref as $name => $r) {
    $t = prayer_times($r['date'], $r['lat'], $r['lng'], $r['tz'], $r['asr']);
    $map = ['fajr'=>'fajr','sunrise'=>'sunrise','dhuhr'=>'dhuhr','asr'=>'asr_t','maghrib'=>'maghrib','isha'=>'isha'];
    foreach ($map as $k => $rk) {
        $diff = ($t[$k] - $r[$rk]) / 60;
        $ok = abs($diff) <= 2.0;
        if (!$ok) $fail++;
        printf("%-9s %-8s %s  diff %+.1f min %s\n", $name, $k, date('H:i', $t[$k]) , $diff, $ok ? 'ok' : 'FAIL');
    }
}
exit($fail ? 1 : 0);
