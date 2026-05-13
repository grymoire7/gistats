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

// Routes will be added in later tasks.

$result = $router->dispatch($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
if ($result === null) {
    http_response_code(404);
    include __DIR__ . '/views/404.php';
}
