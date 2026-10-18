<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../bin/seed_demo.php';

class SeedDemoTest extends TestCase
{
    private const TZ = 'America/Chicago';
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/gistats_seed_demo_test_' . uniqid() . '.sqlite';
        DB::reset();
        DB::init(['db_path' => $this->dbPath]);
        DB::createSchema();
    }

    protected function tearDown(): void
    {
        DB::reset();
        if (file_exists($this->dbPath)) unlink($this->dbPath);
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-18', new DateTimeZone(self::TZ));
    }

    // --- demo_entries ---

    public function testDemoEntriesAreDeterministicForTheSameSeed(): void
    {
        $this->assertSame(demo_entries($this->today(), 120, 7), demo_entries($this->today(), 120, 7));
    }

    public function testDemoEntriesStayInsideTheWindowAndUseValidTypes(): void
    {
        $rows = demo_entries($this->today(), 60);
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertGreaterThanOrEqual('2026-08-20', substr($r['occurred_at'], 0, 10));
            $this->assertLessThanOrEqual('2026-10-18', substr($r['occurred_at'], 0, 10));
            $this->assertContains($r['stool_type'], [1, 2, 3, 4, 5, 6, 7]);
        }
    }

    public function testDemoEntriesHaveBothUrgentAndNonUrgentAndAreTagged(): void
    {
        $rows = demo_entries($this->today(), 365);
        $urgent = array_filter($rows, fn($r) => $r['urgency'] === 1);
        $this->assertNotEmpty($urgent);
        $this->assertLessThan(count($rows), count($urgent));
        foreach ($rows as $r) {
            $this->assertSame(DEMO_NOTE, $r['note']);
        }
    }

    public function testDemoUrgencyIsMoreLikelyOnLooseStools(): void
    {
        $rows  = demo_entries($this->today(), 365);
        $rate  = function (callable $pick) use ($rows): float {
            $set = array_filter($rows, $pick);
            return count(array_filter($set, fn($r) => $r['urgency'] === 1)) / max(1, count($set));
        };
        $this->assertGreaterThan($rate(fn($r) => $r['stool_type'] <= 4), $rate(fn($r) => $r['stool_type'] >= 5));
    }

    public function testDemoEntriesLeaveSomeDaysEmpty(): void
    {
        $rows = demo_entries($this->today(), 365);
        $days = array_unique(array_map(fn($r) => substr($r['occurred_at'], 0, 10), $rows));
        $this->assertLessThan(365, count($days));
    }

    // --- seed_demo_user ---

    public function testSeedCreatesUserThatCanLogInAndOwnsTheEntries(): void
    {
        $result = seed_demo_user('urgency', 'password', self::TZ, $this->today());
        $user = DB::fetch('SELECT * FROM users WHERE username = ?', ['urgency']);
        $this->assertTrue(password_verify('password', $user['password_hash']));
        $count = (int) DB::fetch('SELECT COUNT(*) AS n FROM entries WHERE user_id = ?', [$user['id']])['n'];
        $this->assertSame($count, $result['entries']);
        $this->assertGreaterThan(100, $count);
    }

    public function testSeedIsIdempotent(): void
    {
        $first  = seed_demo_user('urgency', 'password', self::TZ, $this->today());
        $second = seed_demo_user('urgency', 'password', self::TZ, $this->today());
        $this->assertSame($first['user_id'], $second['user_id']);
        $this->assertSame($first['entries'], $second['entries']);
        $this->assertSame(1, (int) DB::fetch('SELECT COUNT(*) AS n FROM users')['n']);
        $this->assertSame($second['entries'], (int) DB::fetch('SELECT COUNT(*) AS n FROM entries')['n']);
    }

    public function testSeedLeavesOtherUsersAlone(): void
    {
        create_account('tracy', 'a-real-password');
        $tracyId = (int) DB::fetch('SELECT id FROM users WHERE username = ?', ['tracy'])['id'];
        DB::execute(
            'INSERT INTO entries (user_id, occurred_at, stool_type, urgency) VALUES (?, ?, ?, ?)',
            [$tracyId, '2026-10-01T12:00:00Z', 4, 0]
        );
        seed_demo_user('urgency', 'password', self::TZ, $this->today());
        $this->assertSame(1, (int) DB::fetch('SELECT COUNT(*) AS n FROM entries WHERE user_id = ?', [$tracyId])['n']);
        $this->assertTrue(password_verify('a-real-password',
            DB::fetch('SELECT password_hash FROM users WHERE id = ?', [$tracyId])['password_hash']));
    }

    public function testSeedRefusesToTouchAUserWhoHasRealEntries(): void
    {
        create_account('tracy', 'a-real-password');
        $tracyId = (int) DB::fetch('SELECT id FROM users WHERE username = ?', ['tracy'])['id'];
        DB::execute(
            'INSERT INTO entries (user_id, occurred_at, stool_type, urgency, note) VALUES (?, ?, ?, ?, ?)',
            [$tracyId, '2026-10-01T12:00:00Z', 4, 0, 'mine']
        );
        $this->expectException(RuntimeException::class);
        try {
            seed_demo_user('tracy', 'password', self::TZ, $this->today());
        } finally {
            $this->assertSame(1, (int) DB::fetch('SELECT COUNT(*) AS n FROM entries')['n']);
            $this->assertTrue(password_verify('a-real-password',
                DB::fetch('SELECT password_hash FROM users WHERE id = ?', [$tracyId])['password_hash']));
        }
    }

    // --- remove_demo_user ---

    public function testRemoveDeletesDemoUserAndItsEntries(): void
    {
        seed_demo_user('urgency', 'password', self::TZ, $this->today());
        $removed = remove_demo_user('urgency');
        $this->assertGreaterThan(0, $removed);
        $this->assertSame(0, (int) DB::fetch('SELECT COUNT(*) AS n FROM users')['n']);
        $this->assertSame(0, (int) DB::fetch('SELECT COUNT(*) AS n FROM entries')['n']);
    }

    public function testRemoveRefusesWhenUserHasRealEntries(): void
    {
        seed_demo_user('urgency', 'password', self::TZ, $this->today());
        $id = (int) DB::fetch('SELECT id FROM users WHERE username = ?', ['urgency'])['id'];
        DB::execute(
            'INSERT INTO entries (user_id, occurred_at, stool_type, urgency) VALUES (?, ?, ?, ?)',
            [$id, '2026-10-18T12:00:00Z', 4, 0]
        );
        $before = (int) DB::fetch('SELECT COUNT(*) AS n FROM entries')['n'];
        try {
            remove_demo_user('urgency');
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            $this->assertSame($before, (int) DB::fetch('SELECT COUNT(*) AS n FROM entries')['n']);
        }
    }

    public function testRemoveOfUnknownUserRemovesNothing(): void
    {
        $this->assertSame(0, remove_demo_user('nobody'));
    }
}
