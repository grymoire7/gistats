<?php

function to_utc(string $localDatetime, string $timezone): string
{
    $dt = new DateTime($localDatetime, new DateTimeZone($timezone));
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d\TH:i:s\Z');
}

function from_utc(string $utcDatetime, string $timezone): DateTime
{
    $dt = new DateTime($utcDatetime, new DateTimeZone('UTC'));
    $dt->setTimezone(new DateTimeZone($timezone));
    return $dt;
}

function duration_to_seconds(string $mmss): int
{
    $parts  = explode(':', $mmss, 2);
    $minutes = (int) ($parts[0] ?? 0);
    $seconds = (int) ($parts[1] ?? 0);
    return $minutes * 60 + $seconds;
}

function seconds_to_duration(int $seconds): string
{
    return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function dominant_type(array $types): int
{
    if (!$types) return 4;
    $counts = array_count_values(array_map('intval', $types));
    $max    = max($counts);
    $tied   = array_keys(array_filter($counts, fn($c) => $c === $max));
    if (count($tied) === 1) return (int) $tied[0];
    usort($tied, fn($a, $b) => abs($b - 4) <=> abs($a - 4));
    return (int) $tied[0];
}

function moving_average(array $types, int $window = 7): array
{
    $result = [];
    $n      = count($types);
    for ($i = 0; $i < $n; $i++) {
        $start   = max(0, $i - $window + 1);
        $slice   = array_slice($types, $start, $i - $start + 1);
        $result[] = round(array_sum($slice) / count($slice), 2);
    }
    return $result;
}

function build_calendar(int $year, int $month, array $entries, string $timezone): array
{
    $firstDay    = mktime(0, 0, 0, $month, 1, $year);
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $startDow    = (int) date('w', $firstDay);

    $byDate = [];
    foreach ($entries as $entry) {
        $date = from_utc($entry['occurred_at'], $timezone)->format('Y-m-d');
        $byDate[$date][] = $entry;
    }

    $days = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dateStr    = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $dayEntries = $byDate[$dateStr] ?? [];
        $types      = array_column($dayEntries, 'stool_type');
        $days[]     = [
            'date'          => $dateStr,
            'day'           => $d,
            'count'         => count($dayEntries),
            'dominant_type' => $types ? dominant_type($types) : null,
        ];
    }

    return ['start_dow' => $startDow, 'days' => $days];
}

function type_frequency(array $entries): array
{
    $freq = array_fill(1, 7, 0);
    foreach ($entries as $entry) {
        $t = (int) $entry['stool_type'];
        if ($t >= 1 && $t <= 7) $freq[$t]++;
    }
    return $freq;
}

function daily_frequency(array $entries, string $timezone, int $days = 30): array
{
    $tz     = new DateTimeZone($timezone);
    $result = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $date = (new DateTime("$i days ago", $tz))->format('Y-m-d');
        $result[$date] = 0;
    }
    foreach ($entries as $entry) {
        $date = from_utc($entry['occurred_at'], $timezone)->format('Y-m-d');
        if (isset($result[$date])) $result[$date]++;
    }
    return $result;
}
