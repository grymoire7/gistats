<?php
// Session bootstrap. index.php calls start_app_session() before anything else;
// lib/auth.php guards call ensure_session_started() so no path can fall back to
// PHP's defaults (24-minute gc, browser-session cookie).
//
// The app-owned save_path is the load-bearing part: shared hosts sweep the global
// session directory with the host-wide gc_maxlifetime, ignoring per-request
// ini_set, so long sessions require storing them next to the app's database.

const GISTATS_SESSION_LIFETIME = 2592000; // 30 days
const GISTATS_SESSION_NAME = 'gistats_sess';

function session_config_from(array $config, array $server): array
{
    $basePath = (string) ($config['base_url'] ?? '');
    $cookiePath = parse_url($basePath, PHP_URL_PATH) ?: $basePath;
    $cookiePath = ($cookiePath === '' || $cookiePath === '/')
        ? '/'
        : rtrim($cookiePath, '/') . '/';
    $secure = isset($server['HTTPS']) && $server['HTTPS'] !== 'off';

    return [
        'name'           => GISTATS_SESSION_NAME,
        'save_path'      => dirname($config['db_path']) . '/sessions',
        'gc_maxlifetime' => GISTATS_SESSION_LIFETIME,
        'cookie'         => [
            'lifetime' => GISTATS_SESSION_LIFETIME,
            'path'     => $cookiePath,
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ],
    ];
}

function ensure_session_store(string $savePath): void
{
    if (!is_dir($savePath)) {
        @mkdir($savePath, 0770, true);
    }
    // The store can end up inside the webroot (it sits next to the database);
    // never let Apache serve session files.
    $htaccess = $savePath . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
    }
}

function apply_session_config(array $cfg): void
{
    session_name($cfg['name']);
    session_save_path($cfg['save_path']);
    ini_set('session.gc_maxlifetime', (string) $cfg['gc_maxlifetime']);
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params($cfg['cookie']);
}

// PHP's lazy_write skips the session write when nothing changed, so an idle
// browsing streak never refreshes the file mtime the sweeper checks. Touching a
// per-request timestamp forces the write, and re-issuing the cookie moves its
// client-side expiry forward — together they make the 30 days slide with use.
function slide_session(): void
{
    if (empty($_SESSION['user_id'])) return;
    $_SESSION['last_seen'] = time();
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires'  => time() + GISTATS_SESSION_LIFETIME,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'],
    ]);
}

function start_app_session(array $config): void
{
    $cfg = session_config_from($config, $_SERVER);
    ensure_session_store($cfg['save_path']);
    if (session_status() === PHP_SESSION_ACTIVE) return;
    apply_session_config($cfg);
    session_start();
    slide_session();
}

function gistats_config(): array
{
    return $GLOBALS['gistats_config'] ?? require __DIR__ . '/../config.php';
}

function ensure_session_started(): void
{
    if (session_status() !== PHP_SESSION_NONE) return;
    start_app_session(gistats_config());
}
