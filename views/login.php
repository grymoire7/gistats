<?php
$error = $error ?? null;
?>
<div class="card" style="max-width:360px;margin:60px auto;">
    <h2 style="margin:0 0 20px;font-size:20px;font-weight:600;">Sign in</h2>
    <?php if ($error): ?>
    <p style="color:var(--color-red);margin:0 0 16px;font-size:14px;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>
    <form method="post" action="/login" style="display:flex;flex-direction:column;gap:14px;">
        <?= csrf_field() ?>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Username</label>
            <input class="form-input" type="text" name="username" required autocomplete="username">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Password</label>
            <input class="form-input" type="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn-primary" style="align-self:flex-start;">Sign in</button>
    </form>
</div>
