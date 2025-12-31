<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\Database\Crypto\IngressLocator;
use PHPUnit\Framework\TestCase;

final class IngressLocatorIntegrationTest extends TestCase
{
    public function testDatabaseIngressLocatorBootsDatabaseCryptoAdapter(): void
    {
        self::assertTrue(
            class_exists(IngressLocator::class),
            'blackcat-database not available in this workspace (IngressLocator missing).'
        );

        $blackcatDbRoot = $this->resolveBlackcatDatabaseRoot();
        self::assertNotNull(
            $blackcatDbRoot,
            'blackcat-database packages not available (expected checkout with submodules).'
        );

        $adapter = IngressLocator::adapter();
        self::assertNotNull($adapter);

        $outOrders = $adapter->encrypt('orders', [
            'encrypted_customer_blob' => ['email' => 'alice@example.com', 'note' => 'hello'],
        ]);
        self::assertStringStartsWith('{', (string)($outOrders['encrypted_customer_blob'] ?? ''));
        self::assertNotSame('', (string)($outOrders['encrypted_customer_blob_key_version'] ?? ''));
        self::assertNotSame('', (string)($outOrders['encryption_meta'] ?? ''));

        $outIdem = $adapter->encrypt('idempotency_keys', [
            'key_hash' => 'alice@example.com',
        ]);
        self::assertNotSame('alice@example.com', (string)($outIdem['key_hash'] ?? ''));
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', (string)($outIdem['key_hash'] ?? ''));
        self::assertNotSame('', (string)($outIdem['key_hash_key_version'] ?? ''));
    }

    private function resolveBlackcatDatabaseRoot(): ?string
    {
        $env = getenv('BLACKCAT_DB_ROOT');
        if (is_string($env) && trim($env) !== '') {
            $real = realpath($env);
            if ($real !== false && is_dir($real . '/packages')) {
                return $real;
            }
        }

        $repoLocal = realpath(__DIR__ . '/../blackcat-database');
        if ($repoLocal !== false && is_dir($repoLocal . '/packages')) {
            return $repoLocal;
        }

        $monorepo = realpath(__DIR__ . '/../../blackcat-database');
        if ($monorepo !== false && is_dir($monorepo . '/packages')) {
            return $monorepo;
        }

        return null;
    }
}
