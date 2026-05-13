<?php
session_start();

$config = require __DIR__ . '/config.php';

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/entries.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/router.php';

try {
    DB::init($config);
} catch (PDOException $e) {
    http_response_code(503);
    echo '<h1>Database unavailable</h1><p>Run <code>php seed.php</code> to set up the database.</p>';
    exit;
}

function render(string $view, array $data = []): void {
    global $config;
    extract($data);
    ob_start();
    include __DIR__ . '/views/' . $view . '.php';
    $content = ob_get_clean();
    include __DIR__ . '/views/layout.php';
}

$router = new Router();

$router->get('/', function () use ($config) {
    require_auth();
    $userId     = current_user_id();
    $tz         = $config['timezone'];
    $now        = new DateTime('now', new DateTimeZone($tz));
    $year       = (int) $now->format('Y');
    $month      = (int) $now->format('n');
    $entries    = get_entries($userId);
    $monthEntries = get_entries_for_month($userId, $year, $month, $tz);
    $calendar   = build_calendar($year, $month, $monthEntries, $tz);
    $total      = count_entries($userId);
    render('home', compact('entries', 'calendar', 'year', 'month', 'total'));
});

$router->get('/login', function () use ($config) {
    if (is_logged_in()) { header('Location: /'); exit; }
    render('login');
});

$router->post('/login', function () use ($config) {
    require_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (login($username, $password)) {
        header('Location: /');
        exit;
    }
    render('login', ['error' => 'Invalid username or password.']);
});

$router->post('/logout', function () use ($config) {
    require_csrf();
    logout();
    header('Location: /login');
    exit;
});

$result = $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
if ($result === null) {
    http_response_code(404);
    include __DIR__ . '/views/404.php';
}
