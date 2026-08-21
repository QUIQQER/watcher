<?php

declare(strict_types=1);

namespace QUI\Watcher\Tests;

use PHPUnit\Framework\TestCase;

class DatabaseEnvironmentTest extends TestCase
{
    public function testLocalExecutionAlwaysUsesSqlite(): void
    {
        self::assertSame(DatabaseEnvironment::MODE_SQLITE, DatabaseEnvironment::determineMode([]));
        self::assertSame(DatabaseEnvironment::MODE_SQLITE, DatabaseEnvironment::determineMode([
            'GITLAB_CI' => 'false'
        ]));
    }

    public function testGitLabExecutionUsesConfiguredDatabase(): void
    {
        self::assertSame(DatabaseEnvironment::MODE_CI_DATABASE, DatabaseEnvironment::determineMode([
            'GITLAB_CI' => 'true'
        ]));
    }
}
