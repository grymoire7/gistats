<?php
$type    = $flash_type ?? 'success';   // 'success' or 'error'
$message = $flash_message ?? '';
$classes = $type === 'error' ? 'flash flash-error' : 'flash flash-success';
?>
<div class="<?= $classes ?>" id="flash-msg">
    <?= htmlspecialchars($message) ?>
</div>
<script>
    setTimeout(() => {
        const el = document.getElementById('flash-msg');
        if (el) el.style.display = 'none';
    }, 3000);
</script>
