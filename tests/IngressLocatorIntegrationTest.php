<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\Database\Crypto\IngressLocator;
use PHPUnit\Framework\TestCase;

final class IngressLocatorIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        // Reset global env for other tests.
        putenv('BLACKCAT_DB_ENCRYPTION_MAP');
        putenv('BLACKCAT_KEYS_DIR');

        if (class_exists(IngressLocator::class)) {
            IngressLocator::setAdapter(null);
            IngressLocator::configure(null, null);
        }
    }

    public function testDatabaseIngressLocatorBootsDatabaseCryptoAdapter(): void
    {
        if (!class_exists(IngressLocator::class)) {
            self::markTestSkipped('blackcat-database not available in this workspace.');
        }

        $mapPath = realpath(__DIR__ . '/fixtures/encryption-map.json');
        $keysDir = realpath(__DIR__ . '/fixtures/keys');
        if ($mapPath === false || $keysDir === false) {
            self::markTestSkipped('Test fixtures not available.');
        }

        putenv('BLACKCAT_DB_ENCRYPTION_MAP=' . $mapPath);
        putenv('BLACKCAT_KEYS_DIR=' . $keysDir);

        IngressLocator::configure(null, null);
        $adapter = IngressLocator::adapter();
        self::assertNotNull($adapter);

        $out = $adapter->encrypt('users', [
            'id' => 1,
            'ssn' => '123-45-6789',
            'email_hash' => 'alice@example.com',
        ]);

        self::assertNotSame('123-45-6789', $out['ssn']);
        self::assertStringStartsWith('{', (string)$out['ssn']);
        self::assertNotSame('alice@example.com', $out['email_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', (string)$out['email_hash']);
    }

    public function testConfigureOverridesKeysDirWithoutEnv(): void
    {
        if (!class_exists(IngressLocator::class)) {
            self::markTestSkipped('blackcat-database not available in this workspace.');
        }

        $mapPath = realpath(__DIR__ . '/fixtures/encryption-map.json');
        $keysDir = realpath(__DIR__ . '/fixtures/keys');
        if ($mapPath === false || $keysDir === false) {
            self::markTestSkipped('Test fixtures not available.');
        }

        // Ensure overrides are the only input.
        putenv('BLACKCAT_DB_ENCRYPTION_MAP');
        putenv('BLACKCAT_KEYS_DIR');

        IngressLocator::configure($mapPath, $keysDir);
        $adapter = IngressLocator::adapter();
        self::assertNotNull($adapter);
    }
}
