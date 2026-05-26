<?php
// Variables: $calendar (from build_calendar), $year, $month, $config
$baseUrl = $config['base_url'];
$today   = (new DateTime('now', new DateTimeZone($config['timezone'])))->format('Y-m-d');

$prevMonth = $month - 1; $prevYear = $year;
if ($prevMonth < 1)  { $prevMonth = 12; $prevYear--; }
$nextMonth = $month + 1; $nextYear = $year;
if ($nextMonth > 12) { $nextMonth = 1;  $nextYear++; }

$monthName = DateTime::createFromFormat('!m', $month)->format('F');
?>
<div class="card" style="padding:12px;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <button class="btn-outline" style="font-size:12px;padding:4px 12px;"
            data-offline-disable
            hx-get="<?= htmlspecialchars($baseUrl) ?>/calendar?year=<?= $prevYear ?>&month=<?= $prevMonth ?>"
            hx-target="#calendar-wrap"
            hx-swap="innerHTML">‹</button>
        <span style="font-weight:600;font-size:15px;"><?= htmlspecialchars($monthName) ?> <?= $year ?></span>
        <button class="btn-outline" style="font-size:12px;padding:4px 12px;"
            data-offline-disable
            hx-get="<?= htmlspecialchars($baseUrl) ?>/calendar?year=<?= $nextYear ?>&month=<?= $nextMonth ?>"
            hx-target="#calendar-wrap"
            hx-swap="innerHTML">›</button>
    </div>

    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:3px;">
        <?php foreach (['Su','Mo','Tu','We','Th','Fr','Sa'] as $d): ?>
        <div style="text-align:center;font-size:10px;color:var(--color-muted);padding:2px 0;"><?= $d ?></div>
        <?php endforeach; ?>

        <?php for ($i = 0; $i < $calendar['start_dow']; $i++): ?>
        <div></div>
        <?php endfor; ?>

        <?php foreach ($calendar['days'] as $day):
            $isToday  = ($day['date'] === $today);
            $border   = $isToday ? '2px solid var(--color-green)' : '1px solid var(--color-border)';
        ?>
        <div style="background:var(--color-canvas);border:<?= $border ?>;border-radius:5px;padding:3px;text-align:center;min-height:52px;<?= $day['count'] ? 'cursor:pointer;' : '' ?>"
            <?php if ($day['count']): ?>
            data-offline-disable
            hx-get="<?= htmlspecialchars($baseUrl) ?>/entries?date=<?= htmlspecialchars($day['date']) ?>"
            hx-target="#entries-wrap"
            hx-swap="innerHTML"
            <?php endif; ?>>
            <div style="font-size:10px;color:<?= $isToday ? 'var(--color-green)' : 'var(--color-muted)' ?>;font-weight:<?= $isToday ? '700' : '400' ?>;"><?= $day['day'] ?></div>
            <?php if ($day['dominant_type']): ?>
            <img src="<?= htmlspecialchars($baseUrl) ?>/images/bristol/type<?= $day['dominant_type'] ?>.png"
                 alt="Type <?= $day['dominant_type'] ?>"
                 style="width:100%;max-width:28px;height:auto;border-radius:2px;margin:2px auto 0;display:block;">
            <div style="font-size:9px;color:var(--color-muted);">×<?= $day['count'] ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
