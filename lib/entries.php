<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function create_entry(int $userId, array $data, string $timezone): ?int
{
    $occurredAt  = to_utc($data['occurred_at'], $timezone);
    $durationSec = isset($data['duration']) && $data['duration'] !== ''
        ? duration_to_seconds($data['duration'])
        : null;
    $stmt = DB::execute(
        'INSERT OR IGNORE INTO entries (user_id, occurred_at, duration_seconds, stool_type, note)
         VALUES (?, ?, ?, ?, ?)',
        [$userId, $occurredAt, $durationSec, (int) $data['stool_type'], $data['note'] ?? null]
    );
    return $stmt->rowCount() > 0 ? (int) DB::lastInsertId() : null;
}

function update_entry(int $id, int $userId, array $data, string $timezone): ?bool
{
    $occurredAt  = to_utc($data['occurred_at'], $timezone);
    $durationSec = isset($data['duration']) && $data['duration'] !== ''
        ? duration_to_seconds($data['duration'])
        : null;
    try {
        $stmt = DB::execute(
            'UPDATE entries SET occurred_at=?, duration_seconds=?, stool_type=?, note=?
             WHERE id=? AND user_id=?',
            [$occurredAt, $durationSec, (int) $data['stool_type'], $data['note'] ?? null, $id, $userId]
        );
    } catch (\PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
            return null;
        }
        throw $e;
    }
    return $stmt->rowCount() > 0;
}

function delete_entry(int $id, int $userId): bool
{
    $stmt = DB::execute('DELETE FROM entries WHERE id=? AND user_id=?', [$id, $userId]);
    return $stmt->rowCount() > 0;
}

function get_entry(int $id, int $userId): ?array
{
    return DB::fetch('SELECT * FROM entries WHERE id=? AND user_id=?', [$id, $userId]);
}

function get_entries(int $userId, int $offset = 0, int $limit = 30, ?string $date = null, ?string $timezone = null): array
{
    if ($date && $timezone) {
        $start = to_utc($date . 'T00:00:00', $timezone);
        $end   = to_utc($date . 'T23:59:59', $timezone);
        return DB::fetchAll(
            'SELECT * FROM entries WHERE user_id=? AND occurred_at>=? AND occurred_at<=?
             ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
            [$userId, $start, $end, $limit, $offset]
        );
    }
    return DB::fetchAll(
        'SELECT * FROM entries WHERE user_id=? ORDER BY occurred_at DESC LIMIT ? OFFSET ?',
        [$userId, $limit, $offset]
    );
}

function get_entries_for_month(int $userId, int $year, int $month, string $timezone): array
{
    $lastDay = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $start   = to_utc(sprintf('%04d-%02d-01T00:00:00', $year, $month), $timezone);
    $end     = to_utc(sprintf('%04d-%02d-%02dT23:59:59', $year, $month, $lastDay), $timezone);
    return DB::fetchAll(
        'SELECT * FROM entries WHERE user_id=? AND occurred_at>=? AND occurred_at<=?
         ORDER BY occurred_at ASC',
        [$userId, $start, $end]
    );
}

function get_all_entries(int $userId): array
{
    return DB::fetchAll(
        'SELECT * FROM entries WHERE user_id=? ORDER BY occurred_at ASC',
        [$userId]
    );
}

function build_csv_export(array $entries, string $tz): string
{
    $buf = fopen('php://temp', 'w');
    fputcsv($buf, ['occurred_at_utc', 'occurred_at_local', 'duration_seconds', 'stool_type', 'note'], escape: '\\');
    foreach ($entries as $row) {
        $local = from_utc($row['occurred_at'], $tz)->format('Y-m-d H:i:s');
        fputcsv($buf, [
            $row['occurred_at'],
            $local,
            $row['duration_seconds'] ?? '',
            $row['stool_type'],
            $row['note'] ?? '',
        ], escape: '\\');
    }
    rewind($buf);
    $csv = stream_get_contents($buf);
    fclose($buf);
    return $csv;
}

function count_entries(int $userId, ?string $date = null, ?string $timezone = null): int
{
    if ($date && $timezone) {
        $start = to_utc($date . 'T00:00:00', $timezone);
        $end   = to_utc($date . 'T23:59:59', $timezone);
        $row   = DB::fetch(
            'SELECT COUNT(*) as n FROM entries WHERE user_id=? AND occurred_at>=? AND occurred_at<=?',
            [$userId, $start, $end]
        );
    } else {
        $row = DB::fetch('SELECT COUNT(*) as n FROM entries WHERE user_id=?', [$userId]);
    }
    return (int) ($row['n'] ?? 0);
}

function normalize_native_row(array $row, array $colIdx, int $rowNum): array|string
{
    $occurredAt  = $row[$colIdx['occurred_at_utc']] ?? '';
    $durationSec = ($row[$colIdx['duration_seconds']] ?? '') !== ''
        ? (int) $row[$colIdx['duration_seconds']] : null;
    $stoolRaw    = trim($row[$colIdx['stool_type']] ?? '');
    $note        = ($row[$colIdx['note']] ?? '') !== '' ? $row[$colIdx['note']] : null;

    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $occurredAt)) {
        return "Row $rowNum: invalid occurred_at_utc \"$occurredAt\".";
    }
    if (!ctype_digit($stoolRaw) || (int) $stoolRaw < 1 || (int) $stoolRaw > 7) {
        return "Row $rowNum: stool_type \"$stoolRaw\" is not an integer in range 1–7.";
    }

    return [
        'occurred_at'      => $occurredAt,
        'stool_type'       => (int) $stoolRaw,
        'duration_seconds' => $durationSec,
        'note'             => $note,
    ];
}

function normalize_poopify_row(array $row, array $colIdx, int $rowNum, string $timezone): array|string|null
{
    static $consistencyMap = [
        'Separated hard lumps'                 => 1,
        'Lumpy and sausage like'               => 2,
        'Sausage shaped with cracks'           => 3,
        'Like a smooth, soft sausage or snake' => 4,
        'Soft blobs, with clear-cut edges'     => 5,
        'Mushy consistency with ragged edges'  => 6,
        'Liquid, with no solid pieces'         => 7,
    ];

    if (($row[$colIdx['There was stool']] ?? '') !== 'Yes') {
        return null;
    }

    $consistency = $row[$colIdx['Consistency']] ?? '';
    if (!array_key_exists($consistency, $consistencyMap)) {
        return "Row $rowNum: unrecognized consistency \"$consistency\".";
    }

    $date = $row[$colIdx['Date']] ?? '';
    $time = $row[$colIdx['Time']] ?? '';
    try {
        $occurredAt = to_utc("$date $time", $timezone);
    } catch (\Exception $e) {
        return "Row $rowNum: invalid date/time \"$date $time\".";
    }

    $timeOnToilet = $row[$colIdx['Time on toilet']] ?? '';
    $extraNotes   = $row[$colIdx['Extra notes']] ?? '';

    return [
        'occurred_at'      => $occurredAt,
        'stool_type'       => $consistencyMap[$consistency],
        'duration_seconds' => $timeOnToilet !== '' ? (int) ($timeOnToilet * 60) : null,
        'note'             => $extraNotes !== '' ? $extraNotes : null,
    ];
}

function import_csv(int $userId, string $csvContent, string $timezone): array
{
    $result = ['imported' => 0, 'skipped_duplicates' => 0, 'errors' => []];

    $buf = fopen('php://temp', 'r+');
    fwrite($buf, $csvContent);
    rewind($buf);

    $header = fgetcsv($buf, escape: '\\');
    if ($header === false) {
        $result['errors'][] = 'Empty file.';
        fclose($buf);
        return $result;
    }

    $firstCol = $header[0];
    if ($firstCol === 'occurred_at_utc') {
        $format       = 'native';
        $requiredCols = ['occurred_at_utc', 'duration_seconds', 'stool_type', 'note'];
    } elseif ($firstCol === 'Date') {
        $format       = 'poopify';
        $requiredCols = ['Date', 'Time', 'There was stool', 'Consistency', 'Time on toilet', 'Extra notes'];
    } else {
        $result['errors'][] = "Unrecognized CSV format (first column: \"$firstCol\").";
        fclose($buf);
        return $result;
    }

    $missing = array_diff($requiredCols, $header);
    if ($missing) {
        $result['errors'][] = 'Missing required columns: ' . implode(', ', array_values($missing)) . '.';
        fclose($buf);
        return $result;
    }

    $colIdx = array_flip($header);
    $rowNum = 1;

    while (($row = fgetcsv($buf, escape: '\\')) !== false) {
        $rowNum++;

        $normalized = $format === 'native'
            ? normalize_native_row($row, $colIdx, $rowNum)
            : normalize_poopify_row($row, $colIdx, $rowNum, $timezone);

        if ($normalized === null) {
            continue;
        }
        if (is_string($normalized)) {
            $result['errors'][] = $normalized;
            continue;
        }

        $stmt = DB::execute(
            'INSERT OR IGNORE INTO entries (user_id, occurred_at, duration_seconds, stool_type, note)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $normalized['occurred_at'], $normalized['duration_seconds'],
             $normalized['stool_type'], $normalized['note']]
        );

        if ($stmt->rowCount() === 0) {
            $result['skipped_duplicates']++;
        } else {
            $result['imported']++;
        }
    }

    fclose($buf);
    return $result;
}
