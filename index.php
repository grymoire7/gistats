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
    DB::createSchema();
} catch (PDOException $e) {
    http_response_code(503);
    echo '<h1>Database unavailable</h1><p>Check that the configured database path is writable.</p>';
    exit;
}

$requestPath = strtok($_SERVER['REQUEST_URI'], '?');
if ($requestPath !== '/') {
    $requestPath = rtrim($requestPath, '/');
}
if ($requestPath !== '/setup' && no_users_exist()) {
    header('Location: /setup');
    exit;
}

if (!empty($_COOKIE['tz'])) {
    try {
        new DateTimeZone($_COOKIE['tz']);
        $config['timezone'] = $_COOKIE['tz'];
    } catch (\Exception $e) {
        // invalid timezone — keep config default
    }
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

$router->get('/setup', function () use ($config) {
    if (!no_users_exist()) { header('Location: /'); exit; }
    render('setup');
});

$router->post('/setup', function () use ($config) {
    require_csrf();
    if (!no_users_exist()) { header('Location: /'); exit; }

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    $errors = [];
    if ($username === '') { $errors[] = 'Username is required.'; }
    if (strlen($password) < 8) { $errors[] = 'Password must be at least 8 characters.'; }
    if ($password !== $confirm) { $errors[] = 'Passwords do not match.'; }

    if ($errors) {
        render('setup', ['errors' => $errors]);
        return;
    }

    create_account($username, $password);
    login($username, $password);
    header('Location: /');
    exit;
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
    if (!empty($_POST['duration']) && !preg_match('/^\d+:\d{2}(:\d{2})?$/', $_POST['duration'])) {
        $errors[] = 'Duration must be in MM:SS or H:MM:SS format.';
    }

    if ($errors) {
        $entry = null;
        include __DIR__ . '/views/partials/entry-form.php';
        return;
    }

    $newId = create_entry($userId, [
        'occurred_at' => $_POST['occurred_at'],
        'duration'    => $_POST['duration'] ?? '',
        'stool_type'  => (int) $_POST['stool_type'],
        'note'        => trim($_POST['note'] ?? ''),
        'urgency'     => $_POST['urgency'] ?? '0',
    ], $tz);

    if ($newId === null && !empty($_SERVER['HTTP_HX_REQUEST'])) {
        $entry = null;
        ob_start(); include __DIR__ . '/views/partials/entry-form.php'; $formHtml = ob_get_clean();
        ob_start(); $flash_type = 'error'; $flash_message = 'An entry already exists at this time.'; include __DIR__ . '/views/partials/flash.php'; $flashHtml = ob_get_clean();
        echo $formHtml;
        echo '<div id="flash-area" hx-swap-oob="true">' . $flashHtml . '</div>';
        return;
    }

    // Return blank form + flash message for HTMX; redirect for non-HTMX
    if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
        $entry = null;
        ob_start(); include __DIR__ . '/views/partials/entry-form.php'; $formHtml = ob_get_clean();
        ob_start(); $flash_type = 'success'; $flash_message = 'Saved!'; include __DIR__ . '/views/partials/flash.php'; $flashHtml = ob_get_clean();
        $filterDate = null;
        $entries    = get_entries($userId);
        $total      = count_entries($userId);
        $hasMore    = count($entries) >= 30 && $total > 30;
        $nextOffset = 30;
        ob_start(); include __DIR__ . '/views/partials/event-list.php'; $listHtml = ob_get_clean();
        $now   = new DateTime('now', new DateTimeZone($tz));
        $year  = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $monthEntries = get_entries_for_month($userId, $year, $month, $tz);
        $calendar     = build_calendar($year, $month, $monthEntries, $tz);
        ob_start(); include __DIR__ . '/views/partials/calendar.php'; $calendarHtml = ob_get_clean();
        echo $formHtml;
        echo '<div id="flash-area" hx-swap-oob="true">' . $flashHtml . '</div>';
        echo '<div id="entries-wrap" hx-swap-oob="true">' . $listHtml . '</div>';
        echo '<div id="calendar-wrap" hx-swap-oob="true">' . $calendarHtml . '</div>';
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

$router->get('/calendar', function () use ($config) {
    require_auth();
    $userId = current_user_id();
    $tz     = $config['timezone'];
    $now    = new DateTime('now', new DateTimeZone($tz));
    $year   = (int) ($_GET['year']  ?? $now->format('Y'));
    $month  = (int) ($_GET['month'] ?? $now->format('n'));
    $month  = max(1, min(12, $month));
    $monthEntries = get_entries_for_month($userId, $year, $month, $tz);
    $calendar     = build_calendar($year, $month, $monthEntries, $tz);
    include __DIR__ . '/views/partials/calendar.php';
});

$router->get('/stats', function () use ($config) {
    require_auth();
    $userId  = current_user_id();
    $tz      = $config['timezone'];
    $all     = get_all_entries($userId);
    $types   = array_column($all, 'stool_type');
    $movingAvg   = moving_average(array_map('intval', $types), 7);
    $typeFreq    = type_frequency($all);
    $dailyFreq   = daily_frequency($all, $tz, 30);
    render('stats', compact('movingAvg', 'types', 'typeFreq', 'dailyFreq'));
});

// Blank new-entry form (used by Cancel in edit mode)
$router->get('/entries/new', function () use ($config) {
    require_auth();
    $entry = null;
    include __DIR__ . '/views/partials/entry-form.php';
});

// Edit: return pre-filled form
$router->get('/entries/:id/edit', function (string $id) use ($config) {
    require_auth();
    $row = get_entry((int) $id, current_user_id());
    if (!$row) { http_response_code(404); echo 'Not found'; return; }
    $entry = array_merge($row, ['is_edit' => true]);
    include __DIR__ . '/views/partials/entry-form.php';
});

// Copy: return pre-filled form with now as occurred_at
$router->get('/entries/:id/copy', function (string $id) use ($config) {
    require_auth();
    $row = get_entry((int) $id, current_user_id());
    if (!$row) { http_response_code(404); echo 'Not found'; return; }
    $now   = (new DateTime('now', new DateTimeZone($config['timezone'])))->format('Y-m-d\TH:i:s');
    $entry = array_merge($row, ['occurred_at' => to_utc($now, $config['timezone']), 'is_edit' => false]);
    // Override occurred_at back to local time string format for the form
    $entry['_local_occurred_at'] = $now;
    include __DIR__ . '/views/partials/entry-form.php';
});

// Update
$router->post('/entries/:id', function (string $id) use ($config) {
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

    $row = get_entry((int) $id, $userId);
    if (!$row) { http_response_code(404); echo 'Not found'; return; }

    if ($errors) {
        $entry = array_merge($row, ['is_edit' => true]);
        include __DIR__ . '/views/partials/entry-form.php';
        return;
    }

    $updateResult = update_entry((int) $id, $userId, [
        'occurred_at' => $_POST['occurred_at'],
        'duration'    => $_POST['duration'] ?? '',
        'stool_type'  => (int) $_POST['stool_type'],
        'note'        => trim($_POST['note'] ?? ''),
        'urgency'     => $_POST['urgency'] ?? '0',
    ], $tz);

    if ($updateResult === null && !empty($_SERVER['HTTP_HX_REQUEST'])) {
        $entry = array_merge($row, ['is_edit' => true]);
        ob_start(); include __DIR__ . '/views/partials/entry-form.php'; $formHtml = ob_get_clean();
        ob_start(); $flash_type = 'error'; $flash_message = 'An entry already exists at this time.'; include __DIR__ . '/views/partials/flash.php'; $flashHtml = ob_get_clean();
        echo $formHtml;
        echo '<div id="flash-area" hx-swap-oob="true">' . $flashHtml . '</div>';
        return;
    }

    if (!empty($_SERVER['HTTP_HX_REQUEST'])) {
        $entry = null;
        ob_start(); include __DIR__ . '/views/partials/entry-form.php'; $formHtml = ob_get_clean();
        ob_start(); $flash_type = 'success'; $flash_message = 'Updated!'; include __DIR__ . '/views/partials/flash.php'; $flashHtml = ob_get_clean();
        $filterDate = null;
        $entries    = get_entries($userId);
        $total      = count_entries($userId);
        $hasMore    = count($entries) >= 30 && $total > 30;
        $nextOffset = 30;
        ob_start(); include __DIR__ . '/views/partials/event-list.php'; $listHtml = ob_get_clean();
        $now   = new DateTime('now', new DateTimeZone($tz));
        $year  = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $monthEntries = get_entries_for_month($userId, $year, $month, $tz);
        $calendar     = build_calendar($year, $month, $monthEntries, $tz);
        ob_start(); include __DIR__ . '/views/partials/calendar.php'; $calendarHtml = ob_get_clean();
        echo $formHtml;
        echo '<div id="flash-area" hx-swap-oob="true">' . $flashHtml . '</div>';
        echo '<div id="entries-wrap" hx-swap-oob="true">' . $listHtml . '</div>';
        echo '<div id="calendar-wrap" hx-swap-oob="true">' . $calendarHtml . '</div>';
    } else {
        header('Location: /');
        exit;
    }
});

// Delete confirmation fragment
$router->get('/entries/:id/confirm-delete', function (string $id) use ($config) {
    require_auth();
    $row = get_entry((int) $id, current_user_id());
    if (!$row) { http_response_code(404); echo 'Not found'; return; }
    $baseUrl = $config['base_url'];
    echo '<tr id="entry-' . (int)$id . '" style="border-bottom:1px solid var(--color-border);">'
       . '<td colspan="4" style="padding:12px 16px;font-size:13px;">'
       . 'Delete this entry? '
       . '<form method="post" action="' . htmlspecialchars($baseUrl) . '/entries/' . (int)$id . '/delete" style="display:inline;" '
       . 'hx-post="' . htmlspecialchars($baseUrl) . '/entries/' . (int)$id . '/delete" '
       . 'hx-target="#entry-' . (int)$id . '" hx-swap="outerHTML swap:300ms">'
       . csrf_field()
       . '<button type="submit" class="btn-danger" style="margin-left:8px;">Yes, delete</button>'
       . '</form>'
       . ' <button class="btn-outline" style="font-size:11px;padding:3px 10px;margin-left:4px;"'
       . ' hx-get="' . htmlspecialchars($baseUrl) . '/entries/' . (int)$id . '/row"'
       . ' hx-target="#entry-' . (int)$id . '" hx-swap="outerHTML">Cancel</button>'
       . '</td></tr>';
});

// Cancel delete — return original row
$router->get('/entries/:id/row', function (string $id) use ($config) {
    require_auth();
    $row = get_entry((int) $id, current_user_id());
    if (!$row) { http_response_code(404); return; }
    $entries    = [$row];
    $hasMore    = false;
    $filterDate = null;
    include __DIR__ . '/views/partials/event-rows.php';
});

// Execute delete
$router->post('/entries/:id/delete', function (string $id) use ($config) {
    require_auth();
    require_csrf();
    delete_entry((int) $id, current_user_id());
    // Empty response — HTMX removes the row via outerHTML swap with empty string
    http_response_code(200);
    echo '';
});

$router->get('/export', function () use ($config) {
    require_auth();
    $userId  = current_user_id();
    $tz      = $config['timezone'];
    $entries = get_all_entries($userId);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="gistats-export-' . date('Y-m-d') . '.csv"');
    echo build_csv_export($entries, $tz);
    exit;
});

$router->get('/import', function () use ($config) {
    require_auth();
    render('import');
});

$router->post('/import', function () use ($config) {
    require_auth();
    require_csrf();
    $tz = $config['timezone'];

    $uploadError = $_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        render('import', ['error' => 'The uploaded file exceeds the maximum allowed size.']);
        return;
    }
    if (empty($_FILES['csv_file']['tmp_name']) || $uploadError !== UPLOAD_ERR_OK) {
        render('import', ['error' => 'No file uploaded or upload error.']);
        return;
    }

    $csvContent = file_get_contents($_FILES['csv_file']['tmp_name']);
    if ($csvContent === false || trim($csvContent) === '') {
        render('import', ['error' => 'The uploaded file is empty.']);
        return;
    }

    $result = import_csv(current_user_id(), $csvContent, $tz);
    render('import', ['result' => $result]);
});

$router->get('/about', function () use ($config) {
    render('about');
});

$router->get('/csrf-token', function () {
    header('Cache-Control: no-store');
    if (!is_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized']);
        return;
    }
    header('Content-Type: application/json');
    echo json_encode(['token' => csrf_token()]);
});

$result = $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
if ($result === null) {
    http_response_code(404);
    include __DIR__ . '/views/404.php';
}
