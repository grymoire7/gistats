<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

class AuthTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/gistats_auth_test_' . uniqid() . '.sqlite';
        DB::reset();
        DB::init(['db_path' => $this->dbPath]);
        DB::createSchema();
        DB::execute(
            'INSERT INTO users (username, password_hash) VALUES (?, ?)',
            ['admin', password_hash('secret', PASSWORD_BCRYPT)]
        );
        // Suppress session warnings in CLI
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        DB::reset();
        if (file_exists($this->dbPath)) unlink($this->dbPath);
    }

    public function testLoginWithCorrectCredentials(): void
    {
        $result = login('admin', 'secret');
        $this->assertTrue($result);
        $this->assertNotEmpty($_SESSION['user_id']);
    }

    public function testLoginWithWrongPassword(): void
    {
        $result = login('admin', 'wrong');
        $this->assertFalse($result);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testLoginWithUnknownUser(): void
    {
        $result = login('nobody', 'secret');
        $this->assertFalse($result);
    }

    public function testIsLoggedInAfterLogin(): void
    {
        login('admin', 'secret');
        $this->assertTrue(is_logged_in());
    }

    public function testIsLoggedInWithoutSession(): void
    {
        $this->assertFalse(is_logged_in());
    }

    public function testCurrentUserIdAfterLogin(): void
    {
        login('admin', 'secret');
        $id = current_user_id();
        $this->assertGreaterThan(0, $id);
    }

    public function testLogoutClearsSession(): void
    {
        login('admin', 'secret');
        logout();
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }
}
