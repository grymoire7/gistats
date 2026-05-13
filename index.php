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

$router->post('/entries', function () use ($config) {
    require_auth();
    require_csrf();
    $userId = current_user_id();
    $tz     = $config['timezone'];

    $errors = [];
    if (empty($_POST['stool_type']) || !in_array((int)$_POST['stool_type'], range(1,7))) {
        $errors[] = 'Please select a stool type.';
    }
    if (empty($_POST['occurred_at'])) {
        $errors[] = 'Date and time are required.';
    }
    if (!empty($_POST['duration']) && !preg_match('/^\d{1,3}:\d{2}$/', $_POST['duration'])) {
        $errors[] = 'Duration must be in MM:SS format.';
    }

    if ($errors) {
        $entry = null;
        include __DIR__ . '/views/partials/entry-form.php';
        return;
    }

    create_entry($userId, [
        'occurred_at' => $_POST['occurred_at'],
        'duration'    => $_POST['duration'] ?? '',
        'stool_type'  => (int) $_POST['stool_type'],
        'note'        => trim($_POST['note'] ?? ''),
    ], $tz);

    // Return blank form + flash message for HTMX; redirect for non-HTMX
    if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
        $entry = null;
        ob_start(); include __DIR__ . '/views/partials/entry-form.php'; $formHtml = ob_get_clean();
        ob_start(); $flash_type = 'success'; $flash_message = 'Saved!'; include __DIR__ . '/views/partials/flash.php'; $flashHtml = ob_get_clean();
        // Inject flash into flash-area via OOB swap
        echo $formHtml;
        echo '<div id="flash-area" hx-swap-oob="true">' . $flashHtml . '</div>';
    } else {
        header('Location: /');
        exit;
    }
});

// Entries partial (used by calendar date filter and "Show all")
$router->get('/entries', function () use ($config) {
    require_auth();
    $userId     = current_user_id();
    $tz         = $config['timezone'];
    $filterDate = $_GET['date'] ?? null;
    $entries    = get_entries($userId, 0, 30, $filterDate, $filterDate ? $tz : null);
    $total      = count_entries($userId, $filterDate, $filterDate ? $tz : null);
    $hasMore    = count($entries) >= 30 && $total > 30;
    $nextOffset = 30;
    include __DIR__ . '/views/partials/event-list.php';
});

// Load more (appends rows)
$router->get('/entries/more', function () use ($config) {
    require_auth();
    $userId     = current_user_id();
    $tz         = $config['timezone'];
    $offset     = max(0, (int) ($_GET['offset'] ?? 0));
    $filterDate = $_GET['date'] ?? null;
    $entries    = get_entries($userId, $offset, 30, $filterDate, $filterDate ? $tz : null);
    $total      = count_entries($userId, $filterDate, $filterDate ? $tz : null);
    $hasMore    = ($offset + count($entries)) < $total;
    $nextOffset = $offset + 30;
    include __DIR__ . '/views/partials/event-rows.php';
});

$result = $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
if ($result === null) {
    http_response_code(404);
    include __DIR__ . '/views/404.php';
}
