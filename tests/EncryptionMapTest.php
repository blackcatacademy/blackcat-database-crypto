<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\DatabaseCrypto\Config\EncryptionMap;
use PHPUnit\Framework\TestCase;

final class EncryptionMapTest extends TestCase
{
    public function testFromFileNormalizesTableAndColumnNames(): void
    {
        $map = EncryptionMap::fromFile(__DIR__ . '/fixtures/encryption-map.json');

        $users = $map->columnsFor('users');
        self::assertIsArray($users);
        self::assertArrayHasKey('ssn', $users);
        self::assertArrayHasKey('email_hash', $users);
        self::assertArrayHasKey('notes', $users);
    }
}

