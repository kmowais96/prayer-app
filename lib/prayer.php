<?php
// Prayer times with the same equations as PrayTimes.org; checked against the `adhan` package in tests/.
//
// A "method" is only the twilight convention each authority publishes: how far below the horizon
// the sun must be for Fajr and for Isha. Everything else (noon, Asr shadow, sunset) is astronomy
// and is identical for all of them.
const PT_METHODS = [
    // Kuwait: Ministry of Awqaf and Islamic Affairs.
    'kuwait' => ['fajr' => 18.0, 'isha_angle' => 17.5],
    // Saudi Arabia: Umm al-Qura, which fixes Isha at 90 minutes after Maghrib (120 in Ramadan).
    'ummalqura' => ['fajr' => 18.5, 'isha_min' => 90, 'isha_min_ramadan' => 120],
    // Muslim World League, the common default elsewhere.
    'mwl' => ['fajr' => 18.0, 'isha_angle' => 17.0],
];

function pt_method(string $name): array { return PT_METHODS[$name] ?? PT_METHODS['kuwait']; }

function pt_deg2rad(float $d): float { return $d * M_PI / 180; }
function pt_rad2deg(float $r): float { return $r * 180 / M_PI; }
function pt_fix(float $a, float $m): float { $a -= $m * floor($a / $m); return $a; }

function pt_julian(int $y, int $m, int $d): float {
    if ($m <= 2) { $y--; $m += 12; }
    $a = floor($y / 100);
    $b = 2 - $a + floor($a / 4);
    return floor(365.25 * ($y + 4716)) + floor(30.6001 * ($m + 1)) + $d + $b - 1524.5;
}

function pt_sun(float $jd): array {
    $D = $jd - 2451545.0;
    $g = pt_fix(357.529 + 0.98560028 * $D, 360);
    $q = pt_fix(280.459 + 0.98564736 * $D, 360);
    $L = pt_fix($q + 1.915 * sin(pt_deg2rad($g)) + 0.020 * sin(pt_deg2rad(2 * $g)), 360);
    $e = 23.439 - 0.00000036 * $D;
    $RA = pt_rad2deg(atan2(cos(pt_deg2rad($e)) * sin(pt_deg2rad($L)), cos(pt_deg2rad($L)))) / 15;
    $eqt = $q / 15 - pt_fix($RA, 24);
    $decl = pt_rad2deg(asin(sin(pt_deg2rad($e)) * sin(pt_deg2rad($L))));
    return [$decl, $eqt];
}

function pt_midday(float $jd, float $t): float {
    [, $eqt] = pt_sun($jd + $t);
    return pt_fix(12 - $eqt, 24);
}

// Time (hours) when the sun is $angle degrees below the horizon; $ccw = before noon.
function pt_angle_time(float $jd, float $lat, float $angle, float $t, bool $ccw = false): float {
    [$decl] = pt_sun($jd + $t);
    $noon = pt_midday($jd, $t);
    $x = (-sin(pt_deg2rad($angle)) - sin(pt_deg2rad($decl)) * sin(pt_deg2rad($lat)))
        / (cos(pt_deg2rad($decl)) * cos(pt_deg2rad($lat)));
    $x = max(-1.0, min(1.0, $x));
    $h = pt_rad2deg(acos($x)) / 15;
    return $noon + ($ccw ? -$h : $h);
}

function pt_asr_time(float $jd, float $lat, float $factor, float $t): float {
    [$decl] = pt_sun($jd + $t);
    $x = $factor + tan(pt_deg2rad(abs($lat - $decl)));
    $angle = -pt_rad2deg(atan2(1, $x)); // -arccot(x)
    return pt_angle_time($jd, $lat, $angle, $t);
}

// Hijri month 1..12 for a Gregorian date (tabular; good to +-1 day, enough for the Ramadan Isha rule).
function pt_hijri_month(int $y, int $m, int $d): int {
    $jd = (int) floor(pt_julian($y, $m, $d) + 0.5);
    $l = $jd - 1948440 + 10632;
    $n = intdiv($l - 1, 10631);
    $l = $l - 10631 * $n + 354;
    $j = intdiv(10985 - $l, 5316) * intdiv(50 * $l, 17719) + intdiv($l, 5670) * intdiv(43 * $l, 15238);
    $l = $l - intdiv(30 - $j, 15) * intdiv(17719 * $j, 50) - intdiv($j, 16) * intdiv(15238 * $j, 43) + 29;
    return intdiv(24 * $l, 709);
}

/**
 * @return array<string,int> unix timestamps: fajr, sunrise, dhuhr, asr, maghrib, isha
 */
function prayer_times(string $date, float $lat, float $lng, string $tz = 'UTC', int $asrFactor = 1, float $elev = 0, string $method = 'kuwait'): array {
    $mp = pt_method($method);
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    $jd = pt_julian($y, $m, $d) - $lng / (15 * 24);
    $rise = 0.833 + 0.0347 * sqrt(max(0.0, $elev));
    $t = [5, 6, 12, 13, 18, 19]; // initial guesses (hours): fajr, sunrise, dhuhr, asr, sunset, isha
    for ($i = 0; $i < 2; $i++) { // one refinement pass, as PrayTimes does
        $g = array_map(fn($h) => $h / 24, $t);
        $t = [
            pt_angle_time($jd, $lat, $mp['fajr'], $g[0], true),
            pt_angle_time($jd, $lat, $rise, $g[1], true),
            pt_midday($jd, $g[2]),
            pt_asr_time($jd, $lat, $asrFactor, $g[3]),
            pt_angle_time($jd, $lat, $rise, $g[4]),
            isset($mp['isha_angle']) ? pt_angle_time($jd, $lat, $mp['isha_angle'], $g[5]) : $t[5],
        ];
    }
    $shift = -$lng / 15; // solar hours -> UTC hours; the zone only labels the date, so DST days stay correct
    if (isset($mp['isha_angle'])) {
        $isha = $t[5];
    } else {
        $ramadan = pt_hijri_month($y, $m, $d) === 9;
        $isha = $t[4] + ($ramadan ? $mp['isha_min_ramadan'] : $mp['isha_min']) / 60;
    }
    $hours = [
        'fajr' => $t[0], 'sunrise' => $t[1], 'dhuhr' => $t[2],
        'asr' => $t[3], 'maghrib' => $t[4], 'isha' => $isha,
    ];
    $base = gmmktime(0, 0, 0, $m, $d, $y);
    $out = [];
    foreach ($hours as $k => $h) {
        $out[$k] = (int) round($base + ($h + $shift) * 3600);
    }
    return $out;
}

/** Fajr time of the next day, for night/tahajjud maths. */
function prayer_next_fajr(string $date, float $lat, float $lng, string $tz, int $asr = 1, string $method = 'kuwait'): int {
    $next = (new DateTime($date, new DateTimeZone($tz)))->modify('+1 day')->format('Y-m-d');
    return prayer_times($next, $lat, $lng, $tz, $asr, 0, $method)['fajr'];
}

/** Start of the last third of the night (Maghrib to next Fajr): the best time for tahajjud. */
function prayer_last_third(array $times, int $nextFajr): int {
    $night = $nextFajr - $times['maghrib'];
    return (int) ($times['maghrib'] + $night * 2 / 3);
}
