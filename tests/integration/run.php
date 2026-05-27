#!/usr/bin/env php
<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
$port        = (int) ($argv[1] ?? 8765);

// ── Setup ─────────────────────────────────────────────────────────────────
$dbPath = sys_get_temp_dir() . '/gistats_integration_' . uniqid() . '.sqlite';

require_once $projectRoot . '/lib/db.php';

DB::init(['db_path' => $dbPath]);
DB::createSchema();
DB::execute(
    'INSERT INTO users (username, password_hash) VALUES (?, ?)',
    ['admin', password_hash('secret', PASSWORD_BCRYPT)]
);
DB::reset();

// ── Start server ──────────────────────────────────────────────────────────
putenv("GISTATS_DB_PATH={$dbPath}");

$routerScript = __DIR__ . '/server-router.php';
$serverProc   = proc_open(
    ['php', '-S', "localhost:{$port}", $routerScript],
    [STDIN, ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $pipes,
    $projectRoot
);

if (!is_resource($serverProc)) {
    fwrite(STDERR, "ERROR: Failed to start PHP built-in server\n");
    exit(1);
}

// ── Teardown on exit ──────────────────────────────────────────────────────
register_shutdown_function(function () use ($serverProc, $dbPath) {
    proc_terminate($serverProc);
    proc_close($serverProc);
    if (file_exists($dbPath)) {
        unlink($dbPath);
    }
});

// ── Wait for server ready ─────────────────────────────────────────────────
$ready = false;
for ($i = 0; $i < 30; $i++) {
    usleep(100_000);
    $sock = @fsockopen('localhost', $port, $errno, $errstr, 0.5);
    if ($sock !== false) {
        fclose($sock);
        $ready = true;
        break;
    }
}
if (!$ready) {
    fwrite(STDERR, "ERROR: Server did not become ready on localhost:{$port}\n");
    exit(1);
}

// ── Run tests ─────────────────────────────────────────────────────────────
putenv("GISTATS_URL=http://localhost:{$port}");
putenv('GISTATS_USER=admin');
putenv('GISTATS_PASS=secret');

$tests  = glob(__DIR__ . '/test-*.sh');
sort($tests);

$passed = 0;
$failed = 0;

foreach ($tests as $test) {
    echo str_repeat('─', 60) . "\n";
    passthru('bash ' . escapeshellarg($test), $exitCode);
    if ($exitCode === 0) {
        $passed++;
    } else {
        $failed++;
    }
}

echo str_repeat('─', 60) . "\n";
if ($failed === 0) {
    echo "All {$passed} integration test(s) passed.\n";
} else {
    echo "{$failed} failed, {$passed} passed.\n";
}

exit($failed > 0 ? 1 : 0);
