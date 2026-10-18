#!/usr/bin/env php
<?php
declare(strict_types=1);

// One-time migration: merge a BowelMove .txt export and a Poopify .csv export
// into a single gistats native-format CSV. BowelMove wins on every local date
// it has any entry for; Poopify rows are kept only on other dates.
//
// Usage: php bin/legacy_merge.php BM.txt poopify.csv out.csv [timezone]

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/entries.php';

/** @return array{0: array<int, array>, 1: array<int, string>} [rows, errors] */
function parse_bm_export(string $txt, string $timezone): array
{
    $rows   = [];
    $errors = [];
    $blocks = preg_split('/^(?=Time: )/m', $txt, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($blocks as $i => $block) {
        $label = 'Entry ' . ($i + 1);
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        if (!preg_match('/^Time: (\d{4}-\d{2}-\d{2} \d{1,2}:\d{2})([ap]) /m', $block, $t)) {
            $errors[] = "$label: invalid Time line.";
            continue;
        }
        if (!preg_match('/^Type: ([1-7])\./m', $block, $ty)) {
            $errors[] = "$label ({$t[1]}): invalid Type line.";
            continue;
        }
        if (!preg_match('/^Duration: (\d+):(\d{2})\s*$/m', $block, $d)) {
            $errors[] = "$label ({$t[1]}): invalid Duration line.";
            continue;
        }
        $local = DateTime::createFromFormat('Y-m-d g:ia', $t[1] . $t[2] . 'm', new DateTimeZone($timezone));
        if ($local === false) {
            $errors[] = "$label ({$t[1]}): unparseable time.";
            continue;
        }
        $note = preg_match('/^Note: (.+)$/m', $block, $n) ? trim($n[1]) : null;

        $rows[] = [
            'occurred_at'      => to_utc($local->format('Y-m-d H:i:s'), $timezone),
            'stool_type'       => (int) $ty[1],
            'duration_seconds' => (int) $d[1] * 60 + (int) $d[2],
            'note'             => $note,
            'urgency'          => 0,
        ];
    }

    return [$rows, $errors];
}

/** @return array{0: array<int, array>, 1: array<int, string>} [rows, errors] */
function parse_poopify_export(string $csv, string $timezone): array
{
    $buf = fopen('php://temp', 'r+');
    fwrite($buf, $csv);
    rewind($buf);
    $header = fgetcsv($buf, escape: '\\');
    $colIdx = array_flip($header);
    $rows   = [];
    $errors = [];
    $rowNum = 1;
    while (($row = fgetcsv($buf, escape: '\\')) !== false) {
        $rowNum++;
        $n = normalize_poopify_row($row, $colIdx, $rowNum, $timezone);
        if (is_string($n)) {
            $errors[] = $n;
        } elseif ($n !== null) {
            $rows[] = $n;
        }
    }
    fclose($buf);
    return [$rows, $errors];
}

/**
 * @return array{rows: array, dropped: array, kept_poopify: array}
 */
function merge_legacy(array $bmRows, array $poopifyRows, string $timezone): array
{
    $localDate = fn(array $r) => from_utc($r['occurred_at'], $timezone)->format('Y-m-d');
    $bmDates   = array_flip(array_map($localDate, $bmRows));

    $kept = $dropped = [];
    foreach ($poopifyRows as $r) {
        if (isset($bmDates[$localDate($r)])) {
            $dropped[] = $r;
        } else {
            $kept[] = $r;
        }
    }

    $rows = array_merge($bmRows, $kept);
    usort($rows, fn($a, $b) => strcmp($a['occurred_at'], $b['occurred_at']));

    return ['rows' => $rows, 'dropped' => $dropped, 'kept_poopify' => $kept];
}

function native_csv(array $rows): string
{
    $buf = fopen('php://temp', 'r+');
    fputcsv($buf, ['occurred_at_utc', 'duration_seconds', 'stool_type', 'note', 'urgency'], escape: '\\');
    foreach ($rows as $r) {
        fputcsv($buf, [$r['occurred_at'], $r['duration_seconds'], $r['stool_type'], $r['note'], $r['urgency']], escape: '\\');
    }
    rewind($buf);
    $out = stream_get_contents($buf);
    fclose($buf);
    return $out;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if ($argc < 4) {
        fwrite(STDERR, "Usage: php bin/legacy_merge.php BM.txt poopify.csv out.csv [timezone]\n");
        exit(2);
    }
    $tz = $argv[4] ?? (require __DIR__ . '/../config.php')['timezone'];

    [$bm, $bmErrors] = parse_bm_export(file_get_contents($argv[1]), $tz);
    [$pf, $pfErrors] = parse_poopify_export(file_get_contents($argv[2]), $tz);
    $merged = merge_legacy($bm, $pf, $tz);
    file_put_contents($argv[3], native_csv($merged['rows']));

    echo "Timezone:                 $tz\n";
    echo "BowelMove rows:           " . count($bm) . "\n";
    echo "Poopify rows:             " . count($pf) . "\n";
    echo "  dropped (BM has date):  " . count($merged['dropped']) . "\n";
    echo "  kept (no BM that day):  " . count($merged['kept_poopify']) . "\n";
    echo "Written to $argv[3]:      " . count($merged['rows']) . " rows\n";
    foreach ($merged['kept_poopify'] as $r) {
        echo "  kept Poopify: {$r['occurred_at']} type {$r['stool_type']}\n";
    }
    foreach (array_merge($bmErrors, $pfErrors) as $e) {
        echo "ERROR: $e\n";
    }
    exit($bmErrors || $pfErrors ? 1 : 0);
}
