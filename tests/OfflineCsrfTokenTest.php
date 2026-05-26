<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/csrf.php';

class OfflineCsrfTokenTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testCsrfTokenIsA64CharHexString(): void
    {
        $token = csrf_token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testCsrfTokenIsStableWithinSession(): void
    {
        $first  = csrf_token();
        $second = csrf_token();
        $this->assertSame($first, $second);
    }

    public function testEndpointJsonPayloadContainsToken(): void
    {
        $token   = csrf_token();
        $payload = json_encode(['token' => $token]);
        $decoded = json_decode($payload, true);
        $this->assertSame($token, $decoded['token']);
    }

    public function testUnauthenticatedSessionIsNotLoggedIn(): void
    {
        $this->assertFalse(is_logged_in());
    }

    public function testAuthenticatedSessionIsLoggedIn(): void
    {
        $_SESSION['user_id'] = 1;
        $this->assertTrue(is_logged_in());
    }
}
