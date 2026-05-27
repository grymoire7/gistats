<?php
// Variables available: $result (array|null), $error (string|null)
$result = $result ?? null;
$error  = $error  ?? null;
?>
<h2 style="font-size:18px;font-weight:600;margin:0 0 20px;">Import CSV</h2>

<?php if ($result !== null): ?>
<div style="margin-bottom:20px;padding:12px 16px;background:var(--color-surface);border:1px solid var(--color-border);border-radius:8px;">
    <p style="margin:0 0 4px;">
        Imported <?= $result['imported'] ?> <?= $result['imported'] === 1 ? 'entry' : 'entries' ?>.
        <?php if ($result['skipped_duplicates'] > 0): ?>
        Skipped <?= $result['skipped_duplicates'] ?> duplicate<?= $result['skipped_duplicates'] === 1 ? '' : 's' ?>.
        <?php endif; ?>
    </p>
    <?php if ($result['errors']): ?>
    <ul style="margin:8px 0 0;padding-left:20px;font-size:13px;color:var(--color-muted);">
        <?php foreach ($result['errors'] as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php elseif ($error !== null): ?>
<div style="margin-bottom:20px;padding:12px 16px;background:var(--color-surface);border:1px solid var(--color-border);border-radius:8px;color:var(--color-muted);">
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/import"
      enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div style="margin-bottom:16px;">
        <label style="display:block;font-size:13px;color:var(--color-muted);margin-bottom:6px;">CSV file</label>
        <input type="file" name="csv_file" accept=".csv,text/csv" required
               style="display:block;width:100%;font-size:14px;">
    </div>
    <button type="submit" class="btn-primary">Import</button>
</form>

<p style="margin-top:20px;font-size:13px;color:var(--color-muted);">
    Supported formats: gistats export CSV and Poopify export CSV.
    Duplicate entries (same timestamp) are automatically skipped.
</p>
