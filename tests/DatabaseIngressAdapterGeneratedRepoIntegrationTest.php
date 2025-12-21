<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\Core\Database;
use BlackCat\Crypto\Config\CryptoConfig;
use BlackCat\Crypto\CryptoManager;
use BlackCat\Database\Packages\Orders\Repository\OrderRepository;
use BlackCat\DatabaseCrypto\Adapter\DatabaseCryptoAdapter;
use BlackCat\DatabaseCrypto\Config\EncryptionMap;
use BlackCat\DatabaseCrypto\Gateway\DatabaseGatewayInterface;
use BlackCat\DatabaseCrypto\Ingress\DatabaseIngressAdapter;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end write-path test:
 * - Generated repository (blackcat-database)
 * - RepositoryHelpers ingress hook
 * - DatabaseIngressAdapter + DatabaseCryptoAdapter
 *
 * Requires a real DB (MySQL/Postgres); skipped unless DB_DSN is provided.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DatabaseIngressAdapterGeneratedRepoIntegrationTest extends TestCase
{
    public function testUpsertByKeysAndBulkReviveEncryptEndToEnd(): void
    {
        if (!class_exists(OrderRepository::class)) {
            throw new \RuntimeException('blackcat-database generated packages not available (Orders repository missing). Ensure blackcat-database packages are checked out and autoloadable.');
        }

        $dsn = (string)(getenv('DB_DSN') ?: (getenv('BC_TEST_DSN') ?: ''));
        if ($dsn === '') {
            throw new \RuntimeException('Missing DB DSN for integration test. Set DB_DSN (preferred) or BC_TEST_DSN.');
        }

        Database::init([
            'dsn' => $dsn,
            'user' => getenv('DB_USER') ?: (getenv('BC_TEST_DB_USER') ?: null),
            'pass' => getenv('DB_PASSWORD') ?: (getenv('BC_TEST_DB_PASS') ?: null),
        ]);
        $db = Database::getInstance();

        // Minimal schema for Orders repository write paths.
        $this->recreateOrdersTable($db);

        $crypto = CryptoManager::boot(CryptoConfig::fromEnv([
            'BLACKCAT_KEYS_DIR' => __DIR__ . '/fixtures/keys',
        ]));

        $map = EncryptionMap::fromArray([
            'tables' => [
                'orders' => [
                    'columns' => [
                        'uuid_bin' => [
                            'strategy' => 'passthrough',
                        ],
                        'encrypted_customer_blob' => [
                            'strategy' => 'encrypt',
                            'context' => 'core.vault',
                            'write_key_version' => true,
                            'write_encryption_meta' => true,
                        ],
                        'encrypted_customer_blob_key_version' => [
                            'strategy' => 'passthrough',
                        ],
                        'encryption_meta' => [
                            'strategy' => 'passthrough',
                        ],
                        'updated_at' => [
                            'strategy' => 'passthrough',
                        ],
                    ],
                ],
            ],
        ]);

        $nullGateway = new class implements DatabaseGatewayInterface {
            public function insert(string $table, array $payload, array $options = []): mixed { return true; }
            public function update(string $table, array $payload, array $criteria, array $options = []): mixed { return true; }
        };

        $adapter = new DatabaseCryptoAdapter($crypto, $map, $nullGateway);
        $ingress = new DatabaseIngressAdapter($adapter, $map, true);

        $repo = new OrderRepository($db);
        $repo->setIngressAdapter($ingress, 'orders');

        $uuid = random_bytes(16);

        // 1) Upsert-by-keys: keys passed separately must be merged into $row BEFORE ingress encrypt().
        $repo->upsertByKeys(
            [
                'encrypted_customer_blob' => ['email' => 'alice@example.com', 'note' => 'hello'],
            ],
            ['uuid_bin' => $uuid],
            ['encrypted_customer_blob', 'encrypted_customer_blob_key_version', 'encryption_meta']
        );

        $row1 = $this->fetchOrderRow($db, $uuid);
        self::assertNotNull($row1);
        self::assertNotSame('', (string)($row1['encrypted_customer_blob_key_version'] ?? ''));
        self::assertNotSame('', (string)($row1['encryption_meta'] ?? ''));

        $env = json_decode((string)$row1['encrypted_customer_blob'], true);
        self::assertIsArray($env);
        self::assertSame('core.vault', $env['context'] ?? null);
        self::assertIsArray($env['local'] ?? null);
        self::assertIsArray($env['kms'] ?? null);
        self::assertNotSame('', (string)($env['local']['ciphertext'] ?? ''));

        // 2) Bulk revive path must apply ingress encryption BEFORE BulkUpsertHelper (no plaintext in DB).
        $uuid2 = random_bytes(16);
        $repo->upsertManyRevive([
            [
                'uuid_bin' => $uuid2,
                'encrypted_customer_blob' => ['email' => 'bob@example.com'],
            ],
        ]);

        $row2 = $this->fetchOrderRow($db, $uuid2);
        self::assertNotNull($row2);
        self::assertNotSame('', (string)($row2['encrypted_customer_blob_key_version'] ?? ''));
        self::assertNotSame('', (string)($row2['encryption_meta'] ?? ''));

        $env2 = json_decode((string)$row2['encrypted_customer_blob'], true);
        self::assertIsArray($env2);
        self::assertSame('core.vault', $env2['context'] ?? null);
    }

    private function recreateOrdersTable(Database $db): void
    {
        if ($db->isPg()) {
            $db->exec(
                'CREATE TEMP TABLE orders ('
                . 'uuid_bin BYTEA PRIMARY KEY,'
                . 'encrypted_customer_blob TEXT NULL,'
                . 'encrypted_customer_blob_key_version VARCHAR(64) NULL,'
                . 'encryption_meta JSONB NULL,'
                . 'updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP'
                . ')'
            );
            return;
        }

        // Default MySQL/MariaDB
        $db->exec(
            'CREATE TEMPORARY TABLE orders ('
            . 'uuid_bin VARBINARY(16) NOT NULL,'
            . 'encrypted_customer_blob LONGBLOB NULL,'
            . 'encrypted_customer_blob_key_version VARCHAR(64) NULL,'
            . 'encryption_meta JSON NULL,'
            . 'updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'PRIMARY KEY (uuid_bin)'
            . ')'
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchOrderRow(Database $db, string $uuidBin): ?array
    {
        return $db->fetch(
            'SELECT encrypted_customer_blob, encrypted_customer_blob_key_version, encryption_meta FROM orders WHERE uuid_bin = :u',
            ['u' => $uuidBin]
        );
    }
}
