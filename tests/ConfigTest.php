<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testDbPathDefaultsToProjectDatabase(): void
    {
        putenv('GISTATS_DB_PATH');
        $config = require __DIR__ . '/../config.php';
        $this->assertStringEndsWith('/database.sqlite', $config['db_path']);
    }

    public function testDbPathUsesEnvVarWhenSet(): void
    {
        putenv('GISTATS_DB_PATH=/tmp/integration-test.sqlite');
        $config = require __DIR__ . '/../config.php';
        putenv('GISTATS_DB_PATH');
        $this->assertEquals('/tmp/integration-test.sqlite', $config['db_path']);
    }
}
