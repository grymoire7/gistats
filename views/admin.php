<?php
$passwordError   = $passwordError   ?? null;
$passwordSuccess = $passwordSuccess ?? null;
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
