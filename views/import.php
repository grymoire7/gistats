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
    <div style="margin-bottom:20px;display:flex;align-items:center;gap:12px;">
        <input type="file" id="csv_file_input" name="csv_file" accept=".csv,text/csv" required
               style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;">
        <label for="csv_file_input" class="btn-outline" style="display:inline-block;cursor:pointer;white-space:nowrap;">
            Choose File
        </label>
        <span id="csv_file_name" style="font-size:13px;color:var(--color-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">No file selected</span>
    </div>
    <button type="submit" id="import-btn" class="btn-primary" disabled>Import</button>
</form>
<script>
document.getElementById('csv_file_input').addEventListener('change', function () {
    var nameEl    = document.getElementById('csv_file_name');
    var importBtn = document.getElementById('import-btn');
    if (this.files.length > 0) {
        nameEl.textContent    = this.files[0].name;
        importBtn.disabled    = false;
    } else {
        nameEl.textContent = 'No file selected';
        importBtn.disabled = true;
    }
});
</script>

<p style="margin-top:20px;font-size:13px;color:var(--color-muted);">
    Supported formats: gistats export CSV and Poopify export CSV.
    Duplicate entries (same timestamp) are automatically skipped.
</p>
