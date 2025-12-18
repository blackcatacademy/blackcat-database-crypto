<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\Core\Database;
use BlackCat\Database\Installer;
use BlackCat\Database\Registry;
use BlackCat\DatabaseCrypto\Ops\CryptoKeysInventorySync;
use PHPUnit\Framework\TestCase;

final class CryptoKeysInventorySyncDbTest extends TestCase
{
    public function testSyncDirectoryWorksAgainstInstalledSchema(): void
    {
        $dsn = getenv('BC_TEST_DSN') ?: '';
        if ($dsn === '') {
            self::markTestSkipped('Set BC_TEST_DSN to run DB integration test.');
        }

        $user = getenv('BC_TEST_DB_USER') ?: null;
        $pass = getenv('BC_TEST_DB_PASS') ?: null;

        if (!Database::isInitialized()) {
            Database::init([
                'dsn' => $dsn,
                'user' => $user,
                'pass' => $pass,
                'appName' => 'blackcat-dbcrypto-tests',
            ]);
        }

        $db = Database::getInstance();
        $installer = new Installer($db, $db->dialect());

        // Install only the minimal required modules for crypto_keys inventory.
        $registry = new Registry(
            new \BlackCat\Database\Packages\Users\UsersModule(),
            new \BlackCat\Database\Packages\CryptoKeys\CryptoKeysModule(),
        );
        $registry->installOrUpgradeAll($installer);

        $sync = new CryptoKeysInventorySync($db);
        $result = $sync->syncDirectory(__DIR__ . '/fixtures/keys');

        self::assertGreaterThanOrEqual(1, $result['scanned']);
        self::assertGreaterThanOrEqual(1, $result['upserted']);
    }
}

