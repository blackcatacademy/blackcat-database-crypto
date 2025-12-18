<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\Core\Database;
use BlackCat\DatabaseCrypto\Gateway\CoreDatabaseGateway;
use PHPUnit\Framework\TestCase;

final class CoreDatabaseGatewayTest extends TestCase
{
    private static bool $dbReady = false;

    private function db(): Database
    {
        if (!Database::isInitialized()) {
            Database::init(['dsn' => 'sqlite::memory:']);
        }
        $db = Database::getInstance();

        if (!self::$dbReady) {
            $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, "select" TEXT, age INTEGER)');
            self::$dbReady = true;
        }

        return $db;
    }

    public function testInsertAndUpdateWorkAndIdentifiersAreQuoted(): void
    {
        $db = $this->db();
        $gw = new CoreDatabaseGateway($db);

        $gw->insert('users', ['id' => 1, 'select' => 'alpha', 'age' => 20]);
        $gw->update('users', ['select' => 'beta'], ['id' => 1]);

        $row = $db->fetch('SELECT "select", age FROM users WHERE id = :id', ['id' => 1]);
        self::assertIsArray($row);
        self::assertSame('beta', $row['select']);
        self::assertSame(20, (int)$row['age']);
    }

    public function testUpdateRejectsEmptyCriteria(): void
    {
        $gw = new CoreDatabaseGateway($this->db());
        $this->expectException(\InvalidArgumentException::class);
        $gw->update('users', ['select' => 'x'], []);
    }

    public function testUpdateSupportsNullCriteriaValues(): void
    {
        $db = $this->db();
        $gw = new CoreDatabaseGateway($db);

        $gw->insert('users', ['id' => 2, 'select' => null, 'age' => 30]);
        $gw->update('users', ['age' => 31], ['select' => null]);

        $row = $db->fetch('SELECT age FROM users WHERE id = :id', ['id' => 2]);
        self::assertIsArray($row);
        self::assertSame(31, (int)$row['age']);
    }
}
