<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';

$config = require __DIR__ . '/config.php';

if (!file_exists($config['db_path'])) {
    touch($config['db_path']);
    echo "Created database at {$config['db_path']}\n";
}

DB::init($config);
DB::createSchema();
echo "Schema ready.\n";

$username = trim(readline('Username: '));
$password = trim(readline('Password: '));

if ($username === '' || $password === '') {
    echo "Error: username and password cannot be empty.\n";
    exit(1);
}

DB::execute(
    'INSERT OR REPLACE INTO users (username, password_hash) VALUES (?, ?)',
    [$username, password_hash($password, PASSWORD_BCRYPT)]
);

echo "User '$username' created. Run: php -S localhost:8000 server.php\n";
