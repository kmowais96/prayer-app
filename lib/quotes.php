<?php
declare(strict_types=1);

require_once __DIR__ . '/core.php';

/** Load the shipped corpus into SQLite (idempotent: new ids are added, existing rows are refreshed). */
function quotes_import(): int
{
    $db = db();
    $n = 0;
    $ins = $db->prepare('INSERT INTO quotes(id,en,src,who,grade,kind,tags,tier) VALUES(?,?,?,?,?,?,?,?)
        ON CONFLICT(id) DO UPDATE SET en=excluded.en, src=excluded.src, who=excluded.who, grade=excluded.grade,
        kind=excluded.kind, tags=excluded.tags, tier=excluded.tier');
    $db->beginTransaction();
    $dir = APP_ROOT . '/data-src';
    if (is_file("$dir/quotes.ndjson")) {
        foreach (file("$dir/quotes.ndjson", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $q = json_decode($line, true);
            if (!$q) continue;
            $ins->execute([$q['id'], $q['en'], $q['src'] ?? '', $q['who'] ?? '', $q['grade'] ?? 'ungraded', $q['kind'] ?? 'hadith', json_encode($q['tags'] ?? []), (int) ($q['tier'] ?? 1)]);
            $n++;
        }
    }
    if (is_file("$dir/athar.json")) {
        foreach (json_decode(file_get_contents("$dir/athar.json"), true) as $q) {
            $ins->execute([$q['id'], $q['en'], $q['src'], $q['who'], $q['grade'], 'athar', json_encode($q['tags']), (int) $q['tier']]);
            $n++;
        }
    }
    if (is_file("$dir/nudges.json")) {
        foreach (json_decode(file_get_contents("$dir/nudges.json"), true) as $i => $q) {
            $ins->execute(['own:' . substr(md5($q['en']), 0, 10), $q['en'], '', '', 'own', 'nudge', json_encode($q['ctx']), (int) $q['tier']]);
            $n++;
        }
    }
    $db->commit();
    return $n;
}

function quotes_ready(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $have = (int) db()->query('SELECT COUNT(*) FROM quotes')->fetchColumn();
    if ($have === 0) quotes_import();
    // re-import when the shipped files change (deploys replace them)
    $sig = md5_file(APP_ROOT . '/data-src/quotes.ndjson') . md5_file(APP_ROOT . '/data-src/nudges.json') . md5_file(APP_ROOT . '/data-src/athar.json');
    if (setting('quotes_sig') !== $sig) {
        quotes_import();
        set_setting('quotes_sig', $sig);
    }
}

function grade_label(string $g): string
{
    return match ($g) {
        'sahih' => 'Sahih',
        'hasan' => 'Hasan',
        'daif' => "Weak (da'if)",
        'athar' => 'Athar, authentic report',
        'ungraded' => 'Saying of the salaf, ungraded',
        default => '',
    };
}

/**
 * Pick a quote for the given context tags and tier.
 * Skips anything sent in the last N days (30, relaxing to 20 then 10 when the pool is short).
 */
function pick_quote(array $tags, int $tier, ?int $now = null): ?array
{
    quotes_ready();
    $now ??= app_now();
    $db = db();
    $tier = max(1, min(5, $tier));
    $rows = $db->query('SELECT q.*, (SELECT MAX(ts) FROM quote_log l WHERE l.qid=q.id) AS last FROM quotes q')->fetchAll();
    if (!$rows) return null;

    $scored = [];
    foreach ($rows as $r) {
        $rt = json_decode($r['tags'], true) ?: [];
        $match = count(array_intersect($tags, $rt));
        $isNudge = $r['kind'] === 'nudge';
        // nudges only fit when their own context matches; hadith may match loosely
        if ($isNudge && $match === 0) continue;
        $diff = abs((int) $r['tier'] - $tier);
        $tierScore = match (true) { $diff === 0 => 4, $diff === 1 => 2, $diff === 2 => 0, default => -3 };
        $r['score'] = $match * 3 + $tierScore + ($isNudge ? 1 : 0);
        $scored[] = $r;
    }
    $base = max(0, (int) setting('norepeat_days'));
    $pool = [];
    foreach (array_values(array_unique(array_filter([$base, 20, 10], fn($d) => $d <= $base))) as $days) {
        $cut = $now - $days * 86400;
        $pool = array_filter($scored, fn($r) => $r['last'] === null || (int) $r['last'] < $cut);
        if (count($pool) >= 5) break;
    }
    if (!$pool) { // every quote was sent within the last 10 days: fall back to the least recently used
        $pool = $scored;
        usort($pool, fn($a, $b) => (int) $a['last'] <=> (int) $b['last']);
        $pool = array_slice($pool, 0, 10);
    }
    usort($pool, fn($a, $b) => [$b['score'], $a['last'] ?? 0] <=> [$a['score'], $b['last'] ?? 0]);
    $top = array_slice($pool, 0, 6);
    return $top[array_rand($top)];
}

function quote_mark_sent(string $qid, string $kind, int $tier, ?int $now = null): void
{
    db()->prepare('INSERT INTO quote_log(qid,ts,kind,tier) VALUES(?,?,?,?)')->execute([$qid, $now ?? app_now(), $kind, $tier]);
}

/** Text shown in a notification body: the words, then a short source line. */
function quote_body(array $q, int $max = 230): string
{
    $en = trim(preg_replace('/\s+/', ' ', $q['en']));
    $en = trim($en, " \t\"'“”");
    if (mb_strlen($en) > $max) {
        $cut = mb_substr($en, 0, $max);
        $sp = mb_strrpos($cut, ' ');
        $en = mb_substr($cut, 0, $sp ?: $max) . '…';
    }
    if ($q['kind'] === 'nudge') return $en;
    $label = grade_label($q['grade']);
    $src = trim($q['src'] . ($label ? ' · ' . $label : ''));
    return '“' . $en . '”' . ($src ? "\n" . $src : '');
}
