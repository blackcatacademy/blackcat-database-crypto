<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\Crypto\Config\CryptoConfig;
use BlackCat\Crypto\CryptoManager;
use BlackCat\DatabaseCrypto\Adapter\DatabaseCryptoAdapter;
use BlackCat\DatabaseCrypto\Config\EncryptionMap;
use BlackCat\DatabaseCrypto\Gateway\DatabaseGatewayInterface;
use BlackCat\DatabaseCrypto\Ingress\DatabaseIngressAdapter;
use PHPUnit\Framework\TestCase;

final class PayloadEncryptorMetaFillTest extends TestCase
{
    private CryptoManager $crypto;

    protected function setUp(): void
    {
        parent::setUp();
        $config = CryptoConfig::fromEnv([
            'BLACKCAT_KEYS_DIR' => __DIR__ . '/fixtures/keys',
        ]);
        $this->crypto = CryptoManager::boot($config);
    }

    public function testEncryptorFillsKeyVersionAndEncryptionMetaWhenEnabled(): void
    {
        $map = EncryptionMap::fromArray([
            'tables' => [
                'users' => [
                    'columns' => [
                        'ssn' => [
                            'strategy' => 'encrypt',
                            'context' => 'core.vault',
                            'write_key_version' => true,
                            'key_version_column' => 'ssn_key_version',
                            'write_encryption_meta' => true,
                        ],
                        'email_hash' => [
                            'strategy' => 'hmac',
                            'context' => 'core.hmac.email',
                            'encoding' => 'hex',
                            'write_key_version' => true,
                            'key_version_column' => 'email_hash_key_version',
                            'write_encryption_meta' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $nullGateway = new class implements DatabaseGatewayInterface {
            public function insert(string $table, array $payload, array $options = []): mixed { return true; }
            public function update(string $table, array $payload, array $criteria, array $options = []): mixed { return true; }
        };

        $adapter = new DatabaseCryptoAdapter($this->crypto, $map, $nullGateway);

        $out = $adapter->encryptPayload('users', [
            'id' => 1,
            'ssn' => '123-45-6789',
            'email_hash' => 'alice@example.com',
        ]);

        self::assertNotSame('123-45-6789', $out['ssn']);
        self::assertStringStartsWith('{', (string)$out['ssn']);
        self::assertArrayHasKey('ssn_key_version', $out);
        self::assertIsString($out['ssn_key_version']);
        self::assertNotSame('', $out['ssn_key_version']);

        self::assertNotSame('alice@example.com', $out['email_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', (string)$out['email_hash']);
        self::assertArrayHasKey('email_hash_key_version', $out);
        self::assertIsString($out['email_hash_key_version']);
        self::assertNotSame('', $out['email_hash_key_version']);

        self::assertArrayHasKey('encryption_meta', $out);
        self::assertIsString($out['encryption_meta']);

        $meta = json_decode($out['encryption_meta'], true);
        self::assertIsArray($meta);
        self::assertSame(1, $meta['v'] ?? null);
        self::assertIsArray($meta['fields'] ?? null);
        self::assertIsArray($meta['fields']['ssn'] ?? null);
        self::assertIsArray($meta['fields']['email_hash'] ?? null);

        self::assertSame('core.vault', $meta['fields']['ssn']['context'] ?? null);
        self::assertSame('local', $meta['fields']['ssn']['kmsClient'] ?? null);
        self::assertSame('core.hmac.email', $meta['fields']['email_hash']['context'] ?? null);
    }

    public function testCriteriaDoesNotEmitMetaColumns(): void
    {
        $map = EncryptionMap::fromArray([
            'tables' => [
                'users' => [
                    'columns' => [
                        'email_hash' => [
                            'strategy' => 'hmac',
                            'context' => 'core.hmac.email',
                            'encoding' => 'hex',
                            'write_key_version' => true,
                            'key_version_column' => 'email_hash_key_version',
                            'write_encryption_meta' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $nullGateway = new class implements DatabaseGatewayInterface {
            public function insert(string $table, array $payload, array $options = []): mixed { return true; }
            public function update(string $table, array $payload, array $criteria, array $options = []): mixed { return true; }
        };

        $adapter = new DatabaseCryptoAdapter($this->crypto, $map, $nullGateway);
        $ingress = new DatabaseIngressAdapter($adapter, $map, true);

        $out = $ingress->criteria('users', [
            'email_hash' => 'alice@example.com',
        ]);

        self::assertArrayHasKey('email_hash', $out);
        self::assertArrayNotHasKey('email_hash_key_version', $out);
        self::assertArrayNotHasKey('encryption_meta', $out);
    }

    public function testNullValueClearsKeyVersionAndMarksMeta(): void
    {
        $map = EncryptionMap::fromArray([
            'tables' => [
                'orders' => [
                    'columns' => [
                        'encrypted_customer_blob' => [
                            'strategy' => 'encrypt',
                            'context' => 'core.vault',
                            'write_key_version' => true,
                            'write_encryption_meta' => true,
                        ],
                    ],
                ],
            ],
        ]);

        $nullGateway = new class implements DatabaseGatewayInterface {
            public function insert(string $table, array $payload, array $options = []): mixed { return true; }
            public function update(string $table, array $payload, array $criteria, array $options = []): mixed { return true; }
        };

        $adapter = new DatabaseCryptoAdapter($this->crypto, $map, $nullGateway);
        $out = $adapter->encryptPayload('orders', [
            'encrypted_customer_blob' => null,
        ]);

        self::assertArrayHasKey('encrypted_customer_blob', $out);
        self::assertNull($out['encrypted_customer_blob']);
        self::assertArrayHasKey('encrypted_customer_blob_key_version', $out);
        self::assertNull($out['encrypted_customer_blob_key_version']);

        self::assertArrayHasKey('encryption_meta', $out);
        $meta = json_decode((string)$out['encryption_meta'], true);
        self::assertIsArray($meta);
        self::assertSame(true, $meta['fields']['encrypted_customer_blob']['cleared'] ?? null);
    }
}

