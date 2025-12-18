<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\DatabaseCrypto\Diagnostics\BlackcatDatabaseSchemaCatalog;
use PHPUnit\Framework\TestCase;

final class BlackcatDatabaseSchemaCatalogTest extends TestCase
{
    public function testFromRootLoadsDefinitionsFromPackages(): void
    {
        $root = __DIR__ . '/fixtures/blackcat-database';
        $catalog = BlackcatDatabaseSchemaCatalog::fromRoot($root);

        self::assertSame(['baz', 'foo_bar'], $catalog->tables());
        self::assertSame(['id', 'secret'], $catalog->columnsFor('foo_bar'));
        self::assertSame(['id'], $catalog->columnsFor('baz'));
        self::assertSame([], $catalog->columnsFor('missing_table'));
    }

    public function testSnapshotCanIncludeExplicitMissingTables(): void
    {
        $root = __DIR__ . '/fixtures/blackcat-database';
        $catalog = BlackcatDatabaseSchemaCatalog::fromRoot($root);

        $snapshot = $catalog->snapshot(['foo_bar', 'missing_table']);

        self::assertSame([
            'tables' => [
                'foo_bar' => ['id', 'secret'],
                'missing_table' => [],
            ],
        ], $snapshot->toArray());
    }
}

