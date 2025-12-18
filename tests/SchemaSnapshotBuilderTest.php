<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\DatabaseCrypto\Diagnostics\SchemaSnapshotBuilder;
use PHPUnit\Framework\TestCase;

final class SchemaSnapshotBuilderTest extends TestCase
{
    public function testBuildNormalizesAndSortsTablesAndColumns(): void
    {
        $listTables = static fn(): array => ['Users', 'orders'];
        $listColumns = static fn(string $table): array => match (strtolower($table)) {
            'users' => ['ID', 'Email', 'SSN'],
            'orders' => ['id', 'Card_PAN', 'card_pan_last4'],
            default => [],
        };

        $snapshot = (new SchemaSnapshotBuilder($listTables, $listColumns))->build();

        self::assertSame([
            'tables' => [
                'orders' => ['id', 'card_pan', 'card_pan_last4'],
                'users' => ['id', 'email', 'ssn'],
            ],
        ], $snapshot->toArray());
    }

    public function testBuildCanRestrictToSpecificTables(): void
    {
        $listTables = static fn(): array => ['users', 'orders'];
        $listColumns = static fn(string $table): array => [$table . '_id'];

        $snapshot = (new SchemaSnapshotBuilder($listTables, $listColumns))->build(['Orders']);

        self::assertSame([
            'tables' => [
                'orders' => ['orders_id'],
            ],
        ], $snapshot->toArray());
    }
}

