<?php
$passwordError   = $passwordError   ?? null;
$passwordSuccess = $passwordSuccess ?? null;
$importResult    = $importResult    ?? null;
$importError     = $importError     ?? null;
$restoreError    = $restoreError    ?? null;
?>
<h2 style="font-size:18px;font-weight:600;margin:0 0 20px;">Admin</h2>

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Change Password</h3>
    <?php if ($passwordError): ?>
    <p style="color:var(--color-red);margin:0 0 16px;font-size:14px;"><?= htmlspecialchars($passwordError) ?></p>
    <?php endif; ?>
    <?php if ($passwordSuccess): ?>
    <p style="color:var(--color-green);margin:0 0 16px;font-size:14px;">Password updated.</p>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/admin/password" style="display:flex;flex-direction:column;gap:14px;">
        <?= csrf_field() ?>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Current password</label>
            <input class="form-input" type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">New password</label>
            <input class="form-input" type="password" name="new_password" required autocomplete="new-password" minlength="8">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Confirm new password</label>
            <input class="form-input" type="password" name="new_password_confirm" required autocomplete="new-password" minlength="8">
        </div>
        <button type="submit" class="btn-primary" style="align-self:flex-start;">Update Password</button>
    </form>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Import / Export</h3>

    <?php if ($importResult !== null): ?>
    <div style="margin-bottom:16px;padding:12px 16px;background:var(--color-canvas);border:1px solid var(--color-border);border-radius:8px;">
        <p style="margin:0 0 4px;">
            Imported <?= $importResult['imported'] ?> <?= $importResult['imported'] === 1 ? 'entry' : 'entries' ?>.
            <?php if ($importResult['skipped_duplicates'] > 0): ?>
            Skipped <?= $importResult['skipped_duplicates'] ?> duplicate<?= $importResult['skipped_duplicates'] === 1 ? '' : 's' ?>.
            <?php endif; ?>
        </p>
        <?php if ($importResult['errors']): ?>
        <ul style="margin:8px 0 0;padding-left:20px;font-size:13px;color:var(--color-muted);">
            <?php foreach ($importResult['errors'] as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php elseif ($importError !== null): ?>
    <div style="margin-bottom:16px;padding:12px 16px;background:var(--color-canvas);border:1px solid var(--color-border);border-radius:8px;color:var(--color-muted);">
        <?= htmlspecialchars($importError) ?>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/import"
          enctype="multipart/form-data" style="margin-bottom:16px;">
        <?= csrf_field() ?>
        <div style="margin-bottom:12px;display:flex;align-items:center;gap:12px;">
            <input type="file" id="csv_file_input" name="csv_file" accept=".csv,text/csv" required
                   style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;">
            <label for="csv_file_input" class="btn-outline" style="display:inline-block;cursor:pointer;white-space:nowrap;">
                Choose File
            </label>
            <span id="csv_file_name" style="font-size:13px;color:var(--color-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">No file selected</span>
        </div>
        <button type="submit" id="import-btn" class="btn-primary" disabled>Import CSV</button>
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
    <p style="margin:0 0 16px;font-size:13px;color:var(--color-muted);">
        Supported formats: gistats export CSV and Poopify export CSV.
        Duplicate entries (same timestamp) are automatically skipped.
    </p>

    <a href="<?= htmlspecialchars($config['base_url']) ?>/export" class="btn-outline" style="display:inline-block;">Export CSV</a>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Backup</h3>
    <p style="margin:0 0 16px;font-size:13px;color:var(--color-muted);">
        Downloads a complete, consistent copy of your database.
    </p>
    <a href="<?= htmlspecialchars($config['base_url']) ?>/admin/backup" class="btn-primary" style="display:inline-block;">Download Backup</a>
</div>

<div class="card" style="margin-bottom:20px;">
    <h3 style="font-size:15px;font-weight:600;margin:0 0 16px;">Restore from Backup</h3>
    <?php if ($restoreError): ?>
    <p style="color:var(--color-red);margin:0 0 16px;font-size:14px;"><?= htmlspecialchars($restoreError) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/admin/restore"
          enctype="multipart/form-data" style="display:flex;flex-direction:column;gap:14px;">
        <?= csrf_field() ?>
        <div style="display:flex;align-items:center;gap:12px;">
            <input type="file" id="backup_file_input" name="backup_file" accept=".sqlite" required
                   style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;">
            <label for="backup_file_input" class="btn-outline" style="display:inline-block;cursor:pointer;white-space:nowrap;">
                Choose File
            </label>
            <span id="backup_file_name" style="font-size:13px;color:var(--color-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">No file selected</span>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--color-muted);">
            <input type="checkbox" id="restore-confirm-input" required>
            I understand this will overwrite my current data.
        </label>
        <button type="submit" id="restore-btn" class="btn-danger" disabled>Restore</button>
    </form>
    <script>
    (function () {
        var fileInput  = document.getElementById('backup_file_input');
        var nameEl     = document.getElementById('backup_file_name');
        var confirmBox = document.getElementById('restore-confirm-input');
        var restoreBtn = document.getElementById('restore-btn');
        function updateButton() {
            restoreBtn.disabled = !(fileInput.files.length > 0 && confirmBox.checked);
        }
        fileInput.addEventListener('change', function () {
            nameEl.textContent = this.files.length > 0 ? this.files[0].name : 'No file selected';
            updateButton();
        });
        confirmBox.addEventListener('change', updateButton);
    }());
    </script>
</div>
