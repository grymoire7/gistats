#!/usr/bin/env php
<?php
declare(strict_types=1);

// Creates a fresh schema and the admin user for the Playwright integration run.
// Usage: php tests/integration/seed-db.php <db-path>

$dbPath = $argv[1] ?? null;
if ($dbPath === null || $dbPath === '') {
    fwrite(STDERR, "Usage: php seed-db.php <db-path>\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . '/lib/db.php';

DB::init(['db_path' => $dbPath]);
DB::createSchema();
DB::execute(
    'INSERT INTO users (username, password_hash) VALUES (?, ?)',
    ['admin', password_hash('secret', PASSWORD_BCRYPT)]
);
DB::reset();
