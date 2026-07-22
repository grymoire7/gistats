<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

class SetupTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/gistats_setup_test_' . uniqid() . '.sqlite';
        DB::reset();
        DB::init(['db_path' => $this->dbPath]);
        DB::createSchema();
    }

    protected function tearDown(): void
    {
        DB::reset();
        if (file_exists($this->dbPath)) unlink($this->dbPath);
    }

    public function testNoUsersExistOnFreshDatabase(): void
    {
        $this->assertTrue(no_users_exist());
    }

    public function testNoUsersExistFalseAfterAccountCreated(): void
    {
        create_account('admin', 'secret123');
        $this->assertFalse(no_users_exist());
    }

    public function testCreateAccountHashesPassword(): void
    {
        create_account('admin', 'secret123');
        $user = DB::fetch('SELECT * FROM users WHERE username = ?', ['admin']);
        $this->assertNotNull($user);
        $this->assertTrue(password_verify('secret123', $user['password_hash']));
    }

    public function testCreateAccountAllowsLoginAfterward(): void
    {
        create_account('admin', 'secret123');
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $result = login('admin', 'secret123');
        $this->assertTrue($result);
        $_SESSION = [];
    }
}
