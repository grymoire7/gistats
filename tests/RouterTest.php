<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../router.php';

class RouterTest extends TestCase
{
    public function testDispatchMatchesRouteWithoutBasePath(): void
    {
        $router = new Router();
        $called = false;
        $router->get('/setup', function () use (&$called) { $called = true; });

        $result = $router->dispatch('/setup', 'GET');

        $this->assertTrue($result);
        $this->assertTrue($called);
    }

    public function testDispatchStripsBasePathBeforeMatching(): void
    {
        $router = new Router();
        $called = false;
        $router->get('/setup', function () use (&$called) { $called = true; });

        $result = $router->dispatch('/gistats/setup', 'GET', '/gistats');

        $this->assertTrue($result);
        $this->assertTrue($called);
    }

    public function testDispatchStripsBasePathForRootRoute(): void
    {
        $router = new Router();
        $called = false;
        $router->get('/', function () use (&$called) { $called = true; });

        $result = $router->dispatch('/gistats', 'GET', '/gistats');

        $this->assertTrue($result);
        $this->assertTrue($called);
    }

    public function testDispatchReturnsNullWhenUriOutsideBasePath(): void
    {
        $router = new Router();
        $router->get('/setup', function () {});

        $result = $router->dispatch('/other/setup', 'GET', '/gistats');

        $this->assertNull($result);
    }
}
