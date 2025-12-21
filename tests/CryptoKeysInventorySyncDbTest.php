<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\Core\Database;
use BlackCat\DatabaseCrypto\Ops\CryptoKeysInventorySync;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CryptoKeysInventorySyncDbTest extends TestCase
{
    public function testSyncDirectoryWorksAgainstInstalledSchema(): void
    {
        $dsn = (string)(getenv('DB_DSN') ?: (getenv('BC_TEST_DSN') ?: ''));
        if ($dsn === '') {
            throw new \RuntimeException('Missing DB DSN for integration test. Set DB_DSN (preferred) or BC_TEST_DSN.');
        }

        $user = getenv('DB_USER') ?: (getenv('BC_TEST_DB_USER') ?: null);
        $pass = getenv('DB_PASSWORD') ?: (getenv('BC_TEST_DB_PASS') ?: null);

        if (!Database::isInitialized()) {
            Database::init([
                'dsn' => $dsn,
                'user' => $user,
                'pass' => $pass,
                'appName' => 'blackcat-dbcrypto-tests',
            ]);
        }

        $db = Database::getInstance();
        $this->recreateCryptoKeysTable($db);

        $sync = new CryptoKeysInventorySync($db);
        $result = $sync->syncDirectory(__DIR__ . '/fixtures/keys');

        self::assertGreaterThanOrEqual(1, $result['scanned']);
        self::assertGreaterThanOrEqual(1, $result['upserted']);
    }

    private function recreateCryptoKeysTable(Database $db): void
    {
        $drop = $db->isPg()
            ? 'DROP TABLE IF EXISTS crypto_keys CASCADE'
            : 'DROP TABLE IF EXISTS crypto_keys';
        $db->exec($drop);

        $schemaPath = $this->cryptoKeysTableSchemaPath($db);
        $sql = file_get_contents($schemaPath);
        if ($sql === false) {
            throw new \RuntimeException('Unable to read schema SQL file: ' . $schemaPath);
        }
        $db->exec($sql);
    }

    private function cryptoKeysTableSchemaPath(Database $db): string
    {
        $root = $this->blackcatDatabaseRootDir();
        $dialect = $db->isPg() ? 'postgres' : 'mysql';
        $path = rtrim($root, '/\\') . '/packages/crypto-keys/schema/001_table.' . $dialect . '.sql';
        if (!is_file($path)) {
            throw new \RuntimeException('Schema SQL file not found: ' . $path);
        }
        return $path;
    }

    private function blackcatDatabaseRootDir(): string
    {
        $fromEnv = getenv('BLACKCAT_DB_ROOT');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $p = rtrim($fromEnv, '/\\');
            if (is_dir($p . '/packages')) {
                return $p;
            }
        }

        $candidates = [
            realpath(__DIR__ . '/../blackcat-database') ?: null,
            realpath(__DIR__ . '/../../blackcat-database') ?: null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '' && is_dir($c . '/packages')) {
                return $c;
            }
        }

        $probe = '\\BlackCat\\Database\\Registry';
        if (!class_exists($probe)) {
            throw new \RuntimeException('blackcat-database is not autoloadable (missing ' . $probe . ')');
        }
        $file = (new \ReflectionClass($probe))->getFileName();
        if ($file === false) {
            throw new \RuntimeException('Cannot locate blackcat-database root directory');
        }

        // <root>/src/Registry.php -> <root>
        return dirname($file, 2);
    }
}
