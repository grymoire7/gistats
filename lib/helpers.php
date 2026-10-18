<?php

function strip_base_path(string $uri, string $basePath): string
{
    if ($basePath === '' || strpos($uri, $basePath) !== 0) {
        return $uri;
    }
    $stripped = substr($uri, strlen($basePath));
    return $stripped === '' ? '/' : $stripped;
}

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

function duration_to_seconds(string $input): int
{
    $parts = explode(':', $input);
    if (count($parts) === 3) {
        return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
    }
    return (int)($parts[0] ?? 0) * 60 + (int)($parts[1] ?? 0);
}

function seconds_to_duration(int $seconds): string
{
    if ($seconds >= 3600) {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
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

function daily_frequency(array $entries, string $timezone, string $startDate, string $endDate): array
{
    $tz     = new DateTimeZone($timezone);
    $result = [];
    $day    = new DateTimeImmutable($startDate, $tz);
    $last   = new DateTimeImmutable($endDate, $tz);
    for (; $day <= $last; $day = $day->modify('+1 day')) {
        $result[$day->format('Y-m-d')] = 0;
    }
    foreach ($entries as $entry) {
        $date = from_utc($entry['occurred_at'], $timezone)->format('Y-m-d');
        if (isset($result[$date])) $result[$date]++;
    }
    return $result;
}

const URGENCY_COLOR = '#FFD60A';

// One hand-picked color per stool type. Blue = hard, green = ideal (Type 4), red = loose.
// Types 3 and 5 are pale tints; 1 and 7 are the most vivid. No yellow/orange (reserved for urgency).
const HEATMAP_TYPE_COLORS = [
    1 => '#2D9CFF',
    2 => '#62A6DE',
    3 => '#8DB0CC',
    4 => '#00754A',
    5 => '#D99AAE',
    6 => '#E0526F',
    7 => '#E5193F',
];

function daily_urgent_frequency(array $entries, string $timezone, string $startDate, string $endDate): array
{
    $urgent = array_filter($entries, fn($e) => !empty($e['urgency']));
    return daily_frequency($urgent, $timezone, $startDate, $endDate);
}

// Weeks (Sunday first) of 7 slots each; null pads days outside the range.
// A day's type is its largest stool type, so one bad event isn't averaged away.
function build_heatmap(array $entries, string $timezone, string $startDate, string $endDate): array
{
    $byDate = [];
    foreach ($entries as $entry) {
        $date = from_utc($entry['occurred_at'], $timezone)->format('Y-m-d');
        $byDate[$date]['type']   = max($byDate[$date]['type'] ?? 0, (int) $entry['stool_type']);
        $byDate[$date]['urgent'] = ($byDate[$date]['urgent'] ?? false) || !empty($entry['urgency']);
    }

    $tz    = new DateTimeZone($timezone);
    $day   = new DateTimeImmutable($startDate, $tz);
    $last  = new DateTimeImmutable($endDate, $tz);
    $weeks = [];
    $week  = array_fill(0, (int) $day->format('w'), null);
    for (; $day <= $last; $day = $day->modify('+1 day')) {
        $date   = $day->format('Y-m-d');
        $week[] = [
            'date'   => $date,
            'type'   => $byDate[$date]['type'] ?? null,
            'urgent' => $byDate[$date]['urgent'] ?? false,
        ];
        if (count($week) === 7) {
            $weeks[] = $week;
            $week    = [];
        }
    }
    if ($week) {
        $weeks[] = array_pad($week, 7, null);
    }
    return $weeks;
}

// Month labels for the heat map: one over the first column, then one over each column
// holding a 1st. An earlier label closer than 3 columns to the next one is dropped.
function heatmap_month_labels(array $weeks): array
{
    $candidates = [];
    foreach ($weeks as $col => $week) {
        $days = array_values(array_filter($week));
        if (!$days) continue;
        $label = null;
        if ($col === 0) {
            $label = (new DateTimeImmutable($days[0]['date']))->format('M');
        }
        foreach ($days as $day) {
            if (substr($day['date'], 8, 2) === '01') {
                $label = (new DateTimeImmutable($day['date']))->format('M');
            }
        }
        if ($label !== null) $candidates[] = ['col' => $col, 'label' => $label];
    }

    $kept = [];
    $next = null;
    foreach (array_reverse($candidates) as $c) {
        if ($next === null || $next - $c['col'] >= 3) {
            array_unshift($kept, $c);
            $next = $c['col'];
        }
    }
    return $kept;
}

const STATS_RANGE_DAY_OPTIONS = [30, 60, 90, 180, 365];

function parse_ymd(?string $value): ?string
{
    if ($value === null) return null;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value ? $value : null;
}

function last_n_days_range(int $days, string $today): array
{
    $start = (new DateTimeImmutable($today))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    return ['start' => $start, 'end' => $today];
}

function resolve_stats_range(?string $start, ?string $end, string $today, int $defaultDays = 30): array
{
    $start = parse_ymd($start);
    $end   = parse_ymd($end);
    if ($end === null || $end > $today) {
        $end = $today;
    }
    if ($start === null) {
        $start = last_n_days_range($defaultDays, $end)['start'];
    }
    if ($start > $end) {
        $start = $end;
    }
    return ['start' => $start, 'end' => $end];
}

function stats_range_from_request(array $query, string $today, ?string $earliest): array
{
    $preset = $query['preset'] ?? null;
    if ($preset === 'all') {
        return ['start' => $earliest ?? $today, 'end' => $today];
    }
    if ($preset === 'last') {
        $days = (int) ($query['days'] ?? 30);
        return last_n_days_range(in_array($days, STATS_RANGE_DAY_OPTIONS, true) ? $days : 30, $today);
    }
    return resolve_stats_range($query['start'] ?? null, $query['end'] ?? null, $today);
}

function stats_range_preset(array $range, string $today, ?string $earliest, array $dayOptions): ?string
{
    foreach ($dayOptions as $n) {
        if ($range === last_n_days_range($n, $today)) return "last:$n";
    }
    if ($earliest !== null && $range === ['start' => $earliest, 'end' => $today]) return 'all';
    return null;
}
