<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Safety guard: this project's development database is a real MariaDB
        // server that also holds unrelated databases. The test suite must always
        // run against the isolated in-memory SQLite database configured in
        // phpunit.xml, never against config/database.php's default connection.
        $this->assertSame(
            'sqlite',
            config('database.default'),
            'Refusing to run tests against a non-SQLite connection: the test database must stay isolated.'
        );

        $this->assertSame(
            ':memory:',
            config('database.connections.sqlite.database'),
            'Refusing to run tests unless SQLite is configured as an in-memory database.'
        );
    }
}
