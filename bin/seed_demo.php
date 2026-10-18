#!/usr/bin/env php
<?php
declare(strict_types=1);

// Dev helper: create (or refresh) a demo user with a year of fake entries, including
// urgency, so the statistics charts have something to show.
//
// Every demo entry carries the note "demo data". The script only ever replaces or removes
// entries with that note, and refuses to touch a user who has any other entries.
//
// Usage: php bin/seed_demo.php            create/refresh user "urgency" (password "password")
//        php bin/seed_demo.php --remove   delete that user and its demo entries

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/entries.php';

const DEMO_NOTE     = 'demo data';
const DEMO_USERNAME = 'urgency';
const DEMO_PASSWORD = 'password';

/** @return array<int, array{occurred_at: string, stool_type: int, urgency: int, note: string}> */
function demo_entries(DateTimeImmutable $today, int $days = 365, int $seed = 42): array
{
    $rng      = new Random\Randomizer(new Random\Engine\Mt19937($seed));
    $weights  = [1 => 6, 2 => 8, 3 => 12, 4 => 35, 5 => 20, 6 => 12, 7 => 7];
    $total    = array_sum($weights);
    $pickType = function () use ($rng, $weights, $total): int {
        $roll = $rng->getInt(1, $total);
        foreach ($weights as $type => $w) {
            if (($roll -= $w) <= 0) return $type;
        }
        return 4;
    };

    $rows = [];
    for ($d = $days - 1; $d >= 0; $d--) {
        if ($rng->getInt(1, 100) <= 15) continue;                  // a few empty days
        $day   = $today->modify("-$d days");
        $roll  = $rng->getInt(1, 100);
        $count = $roll <= 45 ? 1 : ($roll <= 85 ? 2 : 3);
        for ($k = 0; $k < $count; $k++) {
            $type    = $pickType();
            $urgent  = $rng->getInt(1, 100) <= ($type >= 5 ? 55 : 6) ? 1 : 0;
            $minutes = 6 * 60 + 30 + $k * 300 + $rng->getInt(0, 59);
            $rows[]  = [
                'occurred_at' => $day->setTime(intdiv($minutes, 60), $minutes % 60)->format('Y-m-d\TH:i:s'),
                'stool_type'  => $type,
                'urgency'     => $urgent,
                'note'        => DEMO_NOTE,
            ];
        }
    }
    return $rows;
}

function user_has_real_entries(int $userId): bool
{
    $row = DB::fetch(
        'SELECT COUNT(*) AS n FROM entries WHERE user_id = ? AND (note IS NULL OR note != ?)',
        [$userId, DEMO_NOTE]
    );
    return (int) $row['n'] > 0;
}

/** @return array{user_id: int, entries: int} */
function seed_demo_user(string $username, string $password, string $timezone, ?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today', new DateTimeZone($timezone));
    $user  = DB::fetch('SELECT id FROM users WHERE username = ?', [$username]);

    if ($user !== null && user_has_real_entries((int) $user['id'])) {
        throw new RuntimeException("User \"$username\" has entries that aren't demo data; refusing to touch it.");
    }

    if ($user === null) {
        create_account($username, $password);
        $userId = (int) DB::lastInsertId();
    } else {
        $userId = (int) $user['id'];
        DB::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT), $userId]);
        DB::execute('DELETE FROM entries WHERE user_id = ?', [$userId]);
    }

    $inserted = 0;
    foreach (demo_entries($today) as $row) {
        if (create_entry($userId, $row, $timezone) !== null) $inserted++;
    }
    return ['user_id' => $userId, 'entries' => $inserted];
}

/** @return int number of demo entries removed */
function remove_demo_user(string $username): int
{
    $user = DB::fetch('SELECT id FROM users WHERE username = ?', [$username]);
    if ($user === null) return 0;
    $userId = (int) $user['id'];

    if (user_has_real_entries($userId)) {
        throw new RuntimeException("User \"$username\" has entries that aren't demo data; refusing to remove it.");
    }

    $removed = DB::execute('DELETE FROM entries WHERE user_id = ?', [$userId])->rowCount();
    DB::execute('DELETE FROM users WHERE id = ?', [$userId]);
    return $removed;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $config = require __DIR__ . '/../config.php';
    DB::init($config);
    try {
        if (($argv[1] ?? null) === '--remove') {
            $n = remove_demo_user(DEMO_USERNAME);
            echo "Removed user \"" . DEMO_USERNAME . "\" and $n demo entries.\n";
        } else {
            $r = seed_demo_user(DEMO_USERNAME, DEMO_PASSWORD, $config['timezone']);
            echo "Seeded user \"" . DEMO_USERNAME . "\" (password \"" . DEMO_PASSWORD . "\") with {$r['entries']} demo entries.\n";
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
