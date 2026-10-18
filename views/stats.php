<h2 style="font-size:20px;font-weight:600;margin:0 0 20px;">Statistics</h2>

<?php if ($earliest === null): ?>
<p style="color:var(--color-muted);">No entries yet. Log some entries to see statistics.</p>
<?php else:
    $baseUrl     = htmlspecialchars($config['base_url']);
    $selectedN   = str_starts_with((string) $preset, 'last:') ? (int) substr($preset, 5) : 30;
    $lastActive  = str_starts_with((string) $preset, 'last:');
    $allActive   = $preset === 'all';
?>
<form method="get" action="<?= $baseUrl ?>/stats" class="card" style="margin-bottom:20px;">
    <div style="display:flex;gap:12px;margin-bottom:12px;">
        <label style="flex:1;font-size:12px;color:var(--color-muted);">Start
            <input class="form-input" type="date" name="start" value="<?= htmlspecialchars($range['start']) ?>"
                   max="<?= htmlspecialchars($today) ?>" onchange="this.form.submit()">
        </label>
        <label style="flex:1;font-size:12px;color:var(--color-muted);">End
            <input class="form-input" type="date" name="end" value="<?= htmlspecialchars($range['end']) ?>"
                   max="<?= htmlspecialchars($today) ?>" onchange="this.form.submit()">
        </label>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button type="submit" name="preset" value="last" aria-pressed="<?= $lastActive ? 'true' : 'false' ?>"
                class="<?= $lastActive ? 'btn-primary' : 'btn-outline' ?>">Last</button>
        <select name="days" class="form-input" style="width:auto;" aria-label="Number of days"
                onchange="this.form.requestSubmit(this.form.querySelector('[value=last]'))">
            <?php foreach (STATS_RANGE_DAY_OPTIONS as $n): ?>
            <option value="<?= $n ?>"<?= $n === $selectedN ? ' selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
        </select>
        <span style="font-size:14px;">days</span>
        <button type="submit" name="preset" value="all" aria-pressed="<?= $allActive ? 'true' : 'false' ?>"
                class="<?= $allActive ? 'btn-primary' : 'btn-outline' ?>" style="margin-left:auto;">All Time</button>
    </div>
</form>

<?php if (empty($types)): ?>
<p style="color:var(--color-muted);">No entries in this range. Try widening the dates.</p>
<?php else: ?>
<?php
$jsonAvg        = json_encode(array_values($movingAvg));
$jsonLabels     = json_encode(range(1, count($types)));
$jsonFreqLabels = json_encode(array_map(fn($t) => 'Type ' . $t, array_keys($typeFreq)));
$jsonFreqData   = json_encode(array_values($typeFreq));
$jsonDailyLabels = json_encode(array_keys($dailyFreq));
$jsonUrgent      = json_encode(array_map('intval', array_values($urgencies)));
$jsonDailyUrgent = json_encode(array_values($dailyUrgent));
$jsonDailyNormal = json_encode(array_values(array_map(
    fn($date) => $dailyFreq[$date] - $dailyUrgent[$date],
    array_keys($dailyFreq)
)));
?>

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;">Moving Average (7-event window)</h3>
    <p style="font-size:11px;color:var(--color-muted);margin:0 0 8px;">Stool type over time — Type 4 is ideal</p>
    <canvas id="chart-avg" height="200"></canvas>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;">Type Distribution</h3>
    <canvas id="chart-freq" height="200"></canvas>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:14px;font-weight:600;margin:0 0 12px;">Daily Frequency</h3>
    <canvas id="chart-daily" height="200"></canvas>
</div>

<style>
    .heatmap-grid { display:grid; grid-template-rows:14px repeat(7, 14px); gap:3px; width:max-content; }
    .heatmap-month, .heatmap-weekday { font-size:10px; line-height:14px; color:var(--color-muted); white-space:nowrap; }
    .heatmap-weekday { grid-column:1; position:sticky; left:0; z-index:1; background:var(--color-surface); }
    .heatmap-cell { position:relative; width:14px; height:14px; border-radius:3px; cursor:default; }
    .heatmap-cell:focus-visible { outline:2px solid var(--color-text); outline-offset:1px; }
    .heatmap-dot { position:absolute; top:50%; left:50%; width:6px; height:6px; margin:-3px 0 0 -3px; border-radius:50%;
                   background:<?= URGENCY_COLOR ?>; box-shadow:0 0 0 1px rgba(0,0,0,0.6); }
    .heatmap-swatch { display:inline-block; width:14px; height:14px; border-radius:3px; }
    .heatmap-key-dot { display:inline-block; width:6px; height:6px; border-radius:50%; background:<?= URGENCY_COLOR ?>; }
    #heatmap-tooltip { position:fixed; z-index:60; pointer-events:none; padding:4px 8px; border-radius:6px; font-size:12px; white-space:nowrap;
                       background:var(--color-canvas); color:var(--color-text); border:1px solid var(--color-border); box-shadow:0 2px 8px rgba(0,0,0,0.5); }
</style>
<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:14px;font-weight:600;margin:0 0 4px;">Daily Heat Map</h3>
    <p style="font-size:11px;color:var(--color-muted);margin:0 0 12px;">Each day is colored by its loosest stool type</p>
    <div style="overflow-x:auto;padding-bottom:8px;">
        <div class="heatmap-grid" style="grid-template-columns:28px repeat(<?= count($heatmap) ?>, 14px);">
            <?php foreach (heatmap_month_labels($heatmap) as $m): ?>
            <span class="heatmap-month" style="grid-column:<?= $m['col'] + 2 ?>;"><?= htmlspecialchars($m['label']) ?></span>
            <?php endforeach; ?>
            <?php foreach ([1 => 'Mon', 3 => 'Wed', 5 => 'Fri'] as $row => $name): ?>
            <span class="heatmap-weekday" style="grid-row:<?= $row + 2 ?>;"><?= $name ?></span>
            <?php endforeach; ?>
            <?php foreach ($heatmap as $col => $week): foreach ($week as $row => $day): if ($day === null) continue;
                $label = (new DateTimeImmutable($day['date']))->format('D, M j, Y') . ': '
                       . ($day['type'] === null ? 'no entries' : 'Type ' . $day['type'] . ($day['urgent'] ? ', urgency' : ''));
                $bg    = $day['type'] === null ? 'var(--color-border)' : HEATMAP_TYPE_COLORS[$day['type']];
            ?>
            <div class="heatmap-cell" data-date="<?= htmlspecialchars($day['date']) ?>"<?= $day['type'] !== null ? ' data-type="' . $day['type'] . '"' : '' ?> role="img" tabindex="0"
                 aria-label="<?= htmlspecialchars($label) ?>" data-tip="<?= htmlspecialchars($label) ?>"
                 style="grid-column:<?= $col + 2 ?>;grid-row:<?= $row + 2 ?>;background:<?= $bg ?>;"><?php if ($day['urgent']): ?><span class="heatmap-dot"></span><?php endif; ?></div>
            <?php endforeach; endforeach; ?>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:8px;font-size:11px;color:var(--color-muted);">
        <span>Hard</span>
        <?php foreach (HEATMAP_TYPE_COLORS as $type => $color): ?>
        <span class="heatmap-swatch" style="background:<?= $color ?>;" title="Type <?= $type ?>"></span>
        <?php endforeach; ?>
        <span>Loose</span>
        <span style="margin-left:auto;display:inline-flex;align-items:center;gap:4px;"><span class="heatmap-key-dot"></span> Urgent</span>
    </div>
</div>

<div id="heatmap-tooltip" role="tooltip" hidden></div>
<script>
(function () {
    const tip = document.getElementById('heatmap-tooltip');
    function show(cell) {
        tip.textContent = cell.dataset.tip;
        tip.hidden = false;
        const r = cell.getBoundingClientRect();
        const left = r.left + r.width / 2 - tip.offsetWidth / 2;
        tip.style.left = Math.max(8, Math.min(left, document.documentElement.clientWidth - tip.offsetWidth - 8)) + 'px';
        const above = r.top - tip.offsetHeight - 6;
        tip.style.top = (above >= 0 ? above : r.bottom + 6) + 'px';
    }
    function hide() { tip.hidden = true; }
    document.querySelectorAll('.heatmap-cell').forEach(function (cell) {
        cell.addEventListener('mouseenter', function () { show(cell); });
        cell.addEventListener('mouseleave', hide);
        cell.addEventListener('focus', function () { show(cell); });
        cell.addEventListener('blur', hide);
        cell.addEventListener('click', function (e) { show(cell); e.stopPropagation(); });
    });
    document.addEventListener('click', hide);
    window.addEventListener('scroll', hide, true);
})();
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const green  = '#00754A';
const muted  = 'rgba(255,255,255,0.3)';
const text   = 'rgba(255,255,255,0.87)';
const grid   = 'rgba(255,255,255,0.08)';

Chart.defaults.color = text;
Chart.defaults.borderColor = grid;

const urgentColor = '<?= URGENCY_COLOR ?>';
const urgentFlags = <?= $jsonUrgent ?>;
const dailyNotUrgent = <?= $jsonDailyNormal ?>;
const dailyUrgent = <?= $jsonDailyUrgent ?>;

// Moving average
new Chart(document.getElementById('chart-avg'), {
    type: 'line',
    data: {
        labels: <?= $jsonLabels ?>,
        datasets: [{
            label: '7-event avg',
            data: <?= $jsonAvg ?>,
            borderColor: green,
            backgroundColor: 'rgba(0,117,74,0.1)',
            borderWidth: 2,
            pointRadius: 2,
            pointBackgroundColor: urgentFlags.map(u => u ? urgentColor : green),
            pointBorderColor: urgentFlags.map(u => u ? urgentColor : green),
            tension: 0.4,
            fill: true,
        }]
    },
    options: {
        scales: {
            y: { min: 1, max: 7, ticks: { stepSize: 1 } },
            x: { title: { display: true, text: 'Event #' } }
        },
        plugins: { legend: { display: false } }
    }
});

// Type frequency
new Chart(document.getElementById('chart-freq'), {
    type: 'bar',
    data: {
        labels: <?= $jsonFreqLabels ?>,
        datasets: [{
            label: 'Count',
            data: <?= $jsonFreqData ?>,
            backgroundColor: green,
        }]
    },
    options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { ticks: { stepSize: 1 } } }
    }
});

// Daily frequency (stacked; urgent first so it sits on the x-axis)
new Chart(document.getElementById('chart-daily'), {
    type: 'bar',
    data: {
        labels: <?= $jsonDailyLabels ?>,
        datasets: [
            { label: 'Urgent', data: dailyUrgent, backgroundColor: urgentColor },
            { label: 'Not urgent', data: dailyNotUrgent, backgroundColor: green },
        ]
    },
    options: {
        plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 12 } } },
        scales: {
            y: { stacked: true, ticks: { stepSize: 1 } },
            x: { stacked: true, ticks: { maxTicksLimit: 10 } }
        }
    }
});
</script>
<?php endif; ?>
<?php endif; ?>
