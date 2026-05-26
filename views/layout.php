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
            <li><a href="<?= htmlspecialchars($config['base_url']) ?>/export" style="color:var(--color-text);text-decoration:none;font-size:15px;">Export CSV</a></li>
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
</body>
</html>
