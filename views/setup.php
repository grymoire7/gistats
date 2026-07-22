<?php
$errors = $errors ?? [];
?>
<div class="card" style="max-width:360px;margin:60px auto;">
    <h2 style="margin:0 0 20px;font-size:20px;font-weight:600;">Create your account</h2>
    <?php if ($errors): ?>
    <ul style="color:var(--color-red);margin:0 0 16px;padding-left:18px;font-size:14px;">
        <?php foreach ($errors as $error): ?>
        <li><?= htmlspecialchars($error) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/setup" style="display:flex;flex-direction:column;gap:14px;">
        <?= csrf_field() ?>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Username</label>
            <input class="form-input" type="text" name="username" required autocomplete="username">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Password</label>
            <input class="form-input" type="password" name="password" required autocomplete="new-password" minlength="8">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Confirm password</label>
            <input class="form-input" type="password" name="password_confirm" required autocomplete="new-password" minlength="8">
        </div>
        <button type="submit" class="btn-primary" style="align-self:flex-start;">Create account</button>
    </form>
</div>
