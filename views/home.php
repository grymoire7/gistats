<?php
// Variables available: $entries, $calendar, $year, $month, $config, $filterDate
$filterDate = $filterDate ?? null;
?>
<?php include __DIR__ . '/partials/entry-form.php'; ?>

<div id="calendar-wrap" style="margin-bottom:20px;">
    <?php include __DIR__ . '/partials/calendar.php'; ?>
</div>

<div id="entries-wrap">
    <?php include __DIR__ . '/partials/event-list.php'; ?>
</div>
