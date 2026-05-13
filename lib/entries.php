<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function create_entry(int $userId, array $data, string $timezone): int
{
    $occurredAt  = to_utc($data['occurred_at'], $timezone);
    $durationSec = isset($data['duration']) && $data['duration'] !== ''
        ? duration_to_seconds($data['duration'])
        : null;
    DB::execute(
        'INSERT INTO entries (user_id, occurred_at, duration_seconds, stool_type, note)
         VALUES (?, ?, ?, ?, ?)',
        [$userId, $occurredAt, $durationSec, (int) $data['stool_type'], $data['note'] ?? null]
    );
    return (int) DB::lastInsertId();
}

function update_entry(int $id, int $userId, array $data, string $timezone): bool
{
    $occurredAt  = to_utc($data['occurred_at'], $timezone);
    $durationSec = isset($data['duration']) && $data['duration'] !== ''
        ? duration_to_seconds($data['duration'])
        : null;
    $stmt = DB::execute(
        'UPDATE entries SET occurred_at=?, duration_seconds=?, stool_type=?, note=?
         WHERE id=? AND user_id=?',
        [$occurredAt, $durationSec, (int) $data['stool_type'], $data['note'] ?? null, $id, $userId]
    );
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
