<?php
$selected = $selected ?? null;
$baseUrl  = $config['base_url'];
$imgExt   = 'png'; // Images sourced from Wikimedia Commons as PNG
?>
<div style="margin-bottom:16px;">
    <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:8px;">Stool Type *</label>
    <input type="hidden" name="stool_type" id="stool-type-input" value="<?= htmlspecialchars((string)($selected ?? '')) ?>" required>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:6px;">
        <?php foreach ([1, 2, 3, 4] as $t): ?>
        <button type="button"
            class="type-btn"
            data-type="<?= $t ?>"
            onclick="selectType(<?= $t ?>)"
            style="background:var(--color-canvas);border:2px solid <?= ($selected === $t) ? 'var(--color-green)' : 'var(--color-border)' ?>;border-radius:8px;padding:6px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;">
            <img src="<?= htmlspecialchars($baseUrl) ?>/images/bristol/type<?= $t ?>.<?= $imgExt ?>"
                 alt="Type <?= $t ?>"
                 style="width:100%;max-width:60px;height:auto;border-radius:4px;">
            <span style="font-size:11px;color:var(--color-muted);">Type <?= $t ?></span>
        </button>
        <?php endforeach; ?>
    </div>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;">
        <?php foreach ([5, 6, 7] as $t): ?>
        <button type="button"
            class="type-btn"
            data-type="<?= $t ?>"
            onclick="selectType(<?= $t ?>)"
            style="background:var(--color-canvas);border:2px solid <?= ($selected === $t) ? 'var(--color-green)' : 'var(--color-border)' ?>;border-radius:8px;padding:6px;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:4px;">
            <img src="<?= htmlspecialchars($baseUrl) ?>/images/bristol/type<?= $t ?>.<?= $imgExt ?>"
                 alt="Type <?= $t ?>"
                 style="width:100%;max-width:60px;height:auto;border-radius:4px;">
            <span style="font-size:11px;color:var(--color-muted);">Type <?= $t ?></span>
        </button>
        <?php endforeach; ?>
    </div>
</div>
<script>
function selectType(t) {
    document.getElementById('stool-type-input').value = t;
    document.querySelectorAll('.type-btn').forEach(btn => {
        btn.style.borderColor = btn.dataset.type == t
            ? 'var(--color-green)'
            : 'var(--color-border)';
    });
}
</script>
