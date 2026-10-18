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
$jsonDailyData   = json_encode(array_values($dailyFreq));
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const green  = '#00754A';
const muted  = 'rgba(255,255,255,0.3)';
const text   = 'rgba(255,255,255,0.87)';
const grid   = 'rgba(255,255,255,0.08)';

Chart.defaults.color = text;
Chart.defaults.borderColor = grid;

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

// Daily frequency
new Chart(document.getElementById('chart-daily'), {
    type: 'bar',
    data: {
        labels: <?= $jsonDailyLabels ?>,
        datasets: [{
            label: 'Entries',
            data: <?= $jsonDailyData ?>,
            backgroundColor: green,
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: {
            y: { ticks: { stepSize: 1 } },
            x: { ticks: { maxTicksLimit: 10 } }
        }
    }
});
</script>
<?php endif; ?>
<?php endif; ?>
