<?php
// Variables: $entries (array), $config, $total (int), $filterDate (string|null)
$filterDate = $filterDate ?? null;
$total      = $total ?? 0;
$limit      = 30;
$hasMore    = count($entries) >= $limit && $total > $limit;
$nextOffset = $limit;
$baseUrl    = $config['base_url'];
?>
<div class="card" style="overflow:hidden;padding:0;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--color-border);">
        <h3 style="margin:0;font-size:15px;font-weight:600;">
            <?= $filterDate
                ? 'Entries for ' . htmlspecialchars($filterDate)
                : 'All Entries' ?>
        </h3>
        <?php if ($filterDate): ?>
        <a href="#"
           data-offline-disable
           hx-get="<?= htmlspecialchars($baseUrl) ?>/entries"
           hx-target="#entries-wrap"
           hx-swap="innerHTML"
           style="font-size:12px;color:var(--color-green);">Show all</a>
        <?php endif; ?>
    </div>
    <?php if (empty($entries)): ?>
    <p style="padding:16px;color:var(--color-muted);font-size:14px;margin:0;">No entries yet.</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;">
        <tbody id="entries-list">
            <?php include __DIR__ . '/event-rows.php'; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
