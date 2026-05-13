<?php
// Variables: $entries (array), $config, $nextOffset (int), $hasMore (bool), $filterDate (string|null)
$hasMore    = $hasMore ?? false;
$nextOffset = $nextOffset ?? 0;
$filterDate = $filterDate ?? null;
$tz         = $config['timezone'];
$baseUrl    = $config['base_url'];

foreach ($entries as $row):
    $dt       = from_utc($row['occurred_at'], $tz);
    $dateStr  = $dt->format('M j, Y');
    $timeStr  = $dt->format('g:i a');
    $type     = (int) $row['stool_type'];
    $duration = $row['duration_seconds'] ? seconds_to_duration((int)$row['duration_seconds']) : '—';
    $note     = $row['note'] ? mb_strimwidth($row['note'], 0, 40, '…') : '';
    $id       = (int) $row['id'];
?>
<tr id="entry-<?= $id ?>" style="border-bottom:1px solid var(--color-border);">
    <td style="padding:10px 8px;font-size:13px;white-space:nowrap;">
        <div><?= htmlspecialchars($dateStr) ?></div>
        <div style="color:var(--color-muted);font-size:11px;"><?= htmlspecialchars($timeStr) ?></div>
    </td>
    <td style="padding:10px 4px;text-align:center;">
        <img src="<?= htmlspecialchars($baseUrl) ?>/images/bristol/type<?= $type ?>.png"
             alt="Type <?= $type ?>"
             style="width:32px;height:auto;border-radius:3px;">
    </td>
    <td style="padding:10px 4px;font-size:12px;color:var(--color-muted);">
        <?= htmlspecialchars($duration) ?>
        <?php if ($note): ?>
        <div style="color:var(--color-muted);"><?= htmlspecialchars($note) ?></div>
        <?php endif; ?>
    </td>
    <td style="padding:10px 4px;white-space:nowrap;text-align:right;">
        <button class="btn-outline" style="font-size:11px;padding:3px 10px;"
            hx-get="<?= htmlspecialchars($baseUrl) ?>/entries/<?= $id ?>/edit"
            hx-target="#entry-form-wrap"
            hx-swap="outerHTML">Edit</button>
        <button class="btn-outline" style="font-size:11px;padding:3px 10px;margin-left:4px;"
            hx-get="<?= htmlspecialchars($baseUrl) ?>/entries/<?= $id ?>/copy"
            hx-target="#entry-form-wrap"
            hx-swap="outerHTML">Copy</button>
        <button class="btn-danger" style="font-size:11px;padding:3px 10px;margin-left:4px;"
            hx-get="<?= htmlspecialchars($baseUrl) ?>/entries/<?= $id ?>/confirm-delete"
            hx-target="#entry-<?= $id ?>"
            hx-swap="outerHTML">Delete</button>
    </td>
</tr>
<?php endforeach; ?>

<?php if ($hasMore): ?>
<tr id="load-more-row">
    <td colspan="4" style="padding:12px;text-align:center;">
        <button class="btn-outline"
            hx-get="<?= htmlspecialchars($baseUrl) ?>/entries/more?offset=<?= $nextOffset ?><?= $filterDate ? '&date=' . urlencode($filterDate) : '' ?>"
            hx-target="#load-more-row"
            hx-swap="outerHTML">Load more</button>
    </td>
</tr>
<?php endif; ?>
