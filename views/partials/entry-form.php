<?php
// $entry: existing entry array for edit/copy, or null for new
$entry   = $entry ?? null;
$isEdit  = !empty($entry) && !empty($entry['is_edit']);
$baseUrl = $config['base_url'];
$tz      = $config['timezone'];

// Default datetime = now in local time
$defaultDatetime = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d\TH:i');

if ($entry) {
    // Copy mode passes _local_occurred_at directly; edit mode converts from UTC
    if (isset($entry['_local_occurred_at'])) {
        $datetimeVal = substr($entry['_local_occurred_at'], 0, 16);
    } else {
        $localDt     = from_utc($entry['occurred_at'], $tz);
        $datetimeVal = $localDt->format('Y-m-d\TH:i');
    }
    $durationVal  = $entry['duration_seconds'] ? seconds_to_duration((int)$entry['duration_seconds']) : '05:00';
    $selectedType = (int) $entry['stool_type'];
    $noteVal      = $entry['note'] ?? '';
    $formAction   = $isEdit
        ? $baseUrl . '/entries/' . (int)$entry['id']
        : $baseUrl . '/entries';
} else {
    $datetimeVal  = $defaultDatetime;
    $durationVal  = '05:00';
    $selectedType = 4;
    $noteVal      = '';
    $formAction   = $baseUrl . '/entries';
}
$errors = $errors ?? [];
?>
<div id="entry-form-wrap" class="card" style="margin-bottom:20px;">
    <h2 style="margin:0 0 16px;font-size:16px;font-weight:600;">
        <?= $isEdit ? 'Edit Entry' : 'New Entry' ?>
    </h2>

    <form id="entry-form"
          method="post"
          action="<?= htmlspecialchars($formAction) ?>"
          hx-post="<?= htmlspecialchars($formAction) ?>"
          hx-target="#entry-form-wrap"
          hx-swap="outerHTML"
          style="display:flex;flex-direction:column;gap:14px;">
        <?= csrf_field() ?>

        <?php if (!empty($errors)): ?>
        <ul style="color:var(--color-red);font-size:13px;margin:0;padding-left:18px;">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <div style="display:flex;gap:10px;">
            <div style="flex:1;">
                <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Date &amp; Time</label>
                <input class="form-input" type="datetime-local" name="occurred_at"
                       value="<?= htmlspecialchars($datetimeVal) ?>" required>
            </div>
            <div style="width:90px;">
                <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Duration</label>
                <input class="form-input" type="text" name="duration"
                       value="<?= htmlspecialchars($durationVal) ?>"
                       placeholder="MM:SS" pattern="\d+:\d{2}(:\d{2})?">
            </div>
        </div>

        <?php $selected = $selectedType; include __DIR__ . '/type-selector.php'; ?>

        <div>
            <label style="display:block;font-size:12px;color:var(--color-muted);margin-bottom:4px;">Note</label>
            <textarea class="form-input" name="note" rows="2"
                      style="resize:vertical;"><?= htmlspecialchars($noteVal) ?></textarea>
        </div>

        <div style="display:flex;gap:10px;">
            <button type="submit" class="btn-primary">Save</button>
            <?php if ($isEdit): ?>
            <button type="button" class="btn-outline"
                hx-get="<?= htmlspecialchars($baseUrl) ?>/entries/new"
                hx-target="#entry-form-wrap"
                hx-swap="outerHTML">Cancel</button>
            <?php else: ?>
            <button type="button" class="btn-outline" onclick="resetForm()">Reset</button>
            <button type="button" id="timer-btn" class="btn-outline" onclick="toggleTimer()">|&gt;</button>
            <?php endif; ?>
        </div>
        <div style="min-height:24px;display:flex;align-items:center;">
            <span id="pending-indicator"
                  style="display:none;font-size:12px;color:var(--color-muted);"></span>
            <button id="sync-now-btn" type="button" class="btn-outline"
                    style="display:none;font-size:12px;padding:4px 14px;margin-left:8px;"
                    onclick="syncQueue()">Sync now</button>
        </div>
    </form>
</div>

<script>
(function () {
    var TIMER_KEY = 'gistats_timer_start';
    var durationInput = document.querySelector('[name="duration"]');
    var timerBtn = document.getElementById('timer-btn');

    function formatDuration(seconds) {
        if (seconds < 3600) {
            var m = Math.floor(seconds / 60);
            var s = seconds % 60;
            return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        }
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
    }

    function isTimerRunning() {
        return !!localStorage.getItem(TIMER_KEY);
    }

    function updateTimerDisplay() {
        var start = parseInt(localStorage.getItem(TIMER_KEY), 10);
        if (!start || !durationInput) return;
        var elapsed = Math.floor((Date.now() - start) / 1000);
        durationInput.value = formatDuration(elapsed);
    }

    function stopTimer() {
        clearInterval(window.gistatsTimerInterval);
        window.gistatsTimerInterval = null;
        localStorage.removeItem(TIMER_KEY);
        if (durationInput) {
            durationInput.readOnly = false;
            durationInput.classList.remove('duration-readonly');
        }
        if (timerBtn) timerBtn.textContent = '|>';
    }

    function captureAndStopTimer() {
        updateTimerDisplay();
        stopTimer();
    }

    function startTimer() {
        localStorage.setItem(TIMER_KEY, Date.now().toString());
        if (durationInput) {
            durationInput.readOnly = true;
            durationInput.classList.add('duration-readonly');
        }
        if (timerBtn) timerBtn.textContent = '[]';
        updateTimerDisplay();
        window.gistatsTimerInterval = setInterval(updateTimerDisplay, 1000);
    }

    window.toggleTimer = function () {
        if (isTimerRunning()) {
            captureAndStopTimer();
        } else {
            startTimer();
        }
    };

    // Resume timer if one was running before this render
    if (window.gistatsTimerInterval) {
        clearInterval(window.gistatsTimerInterval);
        window.gistatsTimerInterval = null;
    }
    if (isTimerRunning()) {
        if (durationInput) {
            durationInput.readOnly = true;
            durationInput.classList.add('duration-readonly');
        }
        if (timerBtn) timerBtn.textContent = '[]';
        updateTimerDisplay();
        window.gistatsTimerInterval = setInterval(updateTimerDisplay, 1000);
    }

    // Capture duration before HTMX POSTs the form
    var form = document.getElementById('entry-form');
    if (form) {
        form.addEventListener('submit', function () {
            if (isTimerRunning()) captureAndStopTimer();
        });
    }

    window.resetForm = function () {
        if (isTimerRunning()) captureAndStopTimer();
        var now = new Date();
        var pad = function (n) { return String(n).padStart(2, '0'); };
        var local = now.getFullYear() + '-' +
            pad(now.getMonth() + 1) + '-' +
            pad(now.getDate()) + 'T' +
            pad(now.getHours()) + ':' +
            pad(now.getMinutes());
        document.querySelector('[name="occurred_at"]').value = local;
        document.querySelector('[name="duration"]').value = '05:00';
        document.querySelector('[name="note"]').value = '';
        selectType(4);
    };
}());
</script>
