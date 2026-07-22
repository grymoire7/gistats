<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GI Stats</title>
    <link rel="stylesheet" href="<?= htmlspecialchars($config['base_url']) ?>/css/compiled.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/htmx.org@2.0.4/dist/htmx.min.js" defer></script>
</head>
<body style="background:var(--color-canvas);color:var(--color-text);min-height:100vh;margin:0;">

    <!-- Header -->
    <header style="background:var(--color-surface);border-bottom:1px solid var(--color-border);display:flex;align-items:center;justify-content:space-between;padding:12px 16px;">
        <a href="/" style="color:var(--color-text);text-decoration:none;font-size:18px;font-weight:600;letter-spacing:-0.02em;">GI Stats</a>
        <button id="nav-toggle" style="background:none;border:none;color:var(--color-text);font-size:22px;cursor:pointer;padding:0 4px;">≡</button>
    </header>

    <!-- Slide-out nav drawer -->
    <div id="nav-overlay" style="display:none;position:fixed;inset:0;z-index:40;background:rgba(0,0,0,0.5);" onclick="closeNav()"></div>
    <nav id="nav-drawer" style="display:none;position:fixed;top:0;right:0;bottom:0;width:240px;z-index:50;background:var(--color-surface);border-left:1px solid var(--color-border);padding:24px 20px;">
        <div style="display:flex;justify-content:flex-end;margin-bottom:24px;">
            <button onclick="closeNav()" style="background:none;border:none;color:var(--color-muted);font-size:18px;cursor:pointer;">✕</button>
        </div>
        <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:20px;">
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/" style="color:var(--color-text);text-decoration:none;font-size:15px;">Home</a></li>
            <?php if (is_logged_in()): ?>
            <li>
                <form method="post" action="<?= htmlspecialchars($config['base_url']) ?>/logout" style="margin:0;">
                    <?= csrf_field() ?>
                    <button type="submit" style="background:none;border:none;color:var(--color-text);font-size:15px;cursor:pointer;padding:0;">Logout</button>
                </form>
            </li>
            <?php else: ?>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/login" style="color:var(--color-text);text-decoration:none;font-size:15px;">Login</a></li>
            <?php endif; ?>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/stats" style="color:var(--color-text);text-decoration:none;font-size:15px;">Statistics</a></li>
            <?php if (is_logged_in()): ?>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/admin" style="color:var(--color-text);text-decoration:none;font-size:15px;">Admin</a></li>
            <?php endif; ?>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/export" style="color:var(--color-text);text-decoration:none;font-size:15px;">Export CSV</a></li>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/import" style="color:var(--color-text);text-decoration:none;font-size:15px;">Import CSV</a></li>
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/about" style="color:var(--color-text);text-decoration:none;font-size:15px;">About</a></li>
        </ul>
    </nav>

    <!-- Flash area (HTMX target) -->
    <div id="flash-area"></div>

    <!-- Offline banner -->
    <div id="offline-banner"
         style="display:none;background:var(--color-surface);border-bottom:1px solid var(--color-border);padding:10px 16px;text-align:center;font-size:13px;color:var(--color-muted);">
        You're offline. Entries will be queued and synced when you reconnect.
    </div>

    <!-- Main content -->
    <main style="max-width:480px;margin:0 auto;padding:20px 16px;">
        <?= $content ?>
    </main>

    <script>
        document.cookie = 'tz=' + encodeURIComponent(Intl.DateTimeFormat().resolvedOptions().timeZone) + '; path=/; SameSite=Strict';
    </script>
    <script>
        const toggle  = document.getElementById('nav-toggle');
        const drawer  = document.getElementById('nav-drawer');
        const overlay = document.getElementById('nav-overlay');
        toggle.addEventListener('click', () => {
            drawer.style.display  = 'block';
            overlay.style.display = 'block';
        });
        function closeNav() {
            drawer.style.display  = 'none';
            overlay.style.display = 'none';
        }
    </script>
    <script>
    (function () {
        'use strict';
        var QUEUE_KEY = 'gistats_pending';
        var BASE_URL  = '<?= htmlspecialchars($config['base_url']) ?>';

        function getQueue() {
            try { return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]'); }
            catch (_) { return []; }
        }
        function saveQueue(q) {
            localStorage.setItem(QUEUE_KEY, JSON.stringify(q));
        }
        function showFlash(msg, type) {
            var area = document.getElementById('flash-area');
            if (!area) return;
            var cls = type === 'error' ? 'flash flash-error' : 'flash flash-success';
            area.innerHTML = '<div class="' + cls + '" id="flash-msg">' + msg + '</div>';
            setTimeout(function () {
                var el = document.getElementById('flash-msg');
                if (el) el.style.display = 'none';
            }, 3000);
        }
        function updatePendingUI() {
            var q         = getQueue();
            var indicator = document.getElementById('pending-indicator');
            var syncBtn   = document.getElementById('sync-now-btn');
            if (indicator) {
                indicator.textContent   = q.length > 0 ? q.length + ' queued' : '';
                indicator.style.display = q.length > 0 ? 'inline' : 'none';
            }
            if (syncBtn) {
                syncBtn.style.display = (q.length > 0 && navigator.onLine) ? 'inline-block' : 'none';
            }
        }
        function setOfflineState(isOffline) {
            var banner = document.getElementById('offline-banner');
            if (banner) banner.style.display = isOffline ? 'block' : 'none';
            document.querySelectorAll('[data-offline-disable]').forEach(function (el) {
                el.classList.toggle('offline-disabled', isOffline);
            });
            updatePendingUI();
        }

        setOfflineState(!navigator.onLine);
        window.addEventListener('offline', function () { setOfflineState(true); });
        window.addEventListener('online',  function () { setOfflineState(false); syncQueue(); });

        document.addEventListener('htmx:sendError', function (e) {
            var form = document.getElementById('entry-form');
            if (!form || e.detail.elt !== form) return;
            var action = form.getAttribute('action') || '';
            if (!/\/entries$/.test(action)) return;
            var fd = new FormData(form);
            try {
                var q = getQueue();
                q.push({
                    occurred_at: fd.get('occurred_at'),
                    duration:    fd.get('duration') || '',
                    stool_type:  fd.get('stool_type'),
                    note:        fd.get('note') || '',
                });
                saveQueue(q);
                updatePendingUI();
                if (typeof resetForm === 'function') resetForm();
            } catch (_) {
                showFlash('Unable to queue entry — storage unavailable.', 'error');
            }
        });

        document.addEventListener('htmx:afterSwap', function () { updatePendingUI(); });

        async function syncQueue() {
            var q = getQueue();
            if (q.length === 0) return;
            var token;
            try {
                var r = await fetch(BASE_URL + '/csrf-token');
                if (!r.ok) throw new Error();
                token = (await r.json()).token;
            } catch (_) {
                showFlash('Sync failed — will retry when reconnected.', 'error');
                return;
            }
            var synced = 0;
            for (var i = 0; i < q.length; i++) {
                var item = q[i];
                try {
                    var res = await fetch(BASE_URL + '/entries', {
                        method:  'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'HX-Request':   'true',
                        },
                        body: new URLSearchParams({
                            csrf_token:  token,
                            occurred_at: item.occurred_at,
                            duration:    item.duration,
                            stool_type:  item.stool_type,
                            note:        item.note,
                        }).toString(),
                    });
                    if (!res.ok) throw new Error();
                    var remaining = getQueue();
                    remaining.shift();
                    saveQueue(remaining);
                    synced++;
                    updatePendingUI();
                } catch (_) {
                    showFlash('Sync failed — will retry when reconnected.', 'error');
                    return;
                }
            }
            showFlash(synced + (synced === 1 ? ' entry' : ' entries') + ' synced.', 'success');
        }

        window.syncQueue = syncQueue;
        updatePendingUI();
    }());
    </script>
</body>
</html>
