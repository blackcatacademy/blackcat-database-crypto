<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\Crypto\Config\CryptoConfig;
use BlackCat\Crypto\CryptoManager;
use BlackCat\DatabaseCrypto\Adapter\DatabaseCryptoAdapter;
use BlackCat\DatabaseCrypto\Config\EncryptionMap;
use BlackCat\DatabaseCrypto\Gateway\DatabaseGatewayInterface;
use PHPUnit\Framework\TestCase;

final class DatabaseCryptoAdapterTest extends TestCase
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

    public function testInsertEncryptsBeforeDelegatingToGateway(): void
    {
        $map = EncryptionMap::fromArray([
            'tables' => [
                'users' => [
                    'columns' => [
                        'ssn' => ['strategy' => 'encrypt', 'context' => 'core.vault'],
                        'email_hash' => ['strategy' => 'hmac', 'context' => 'core.hmac.email', 'encoding' => 'hex'],
                    ],
                ],
            ],
        ]);

        $spy = new class implements DatabaseGatewayInterface {
            public ?string $lastTable = null;
            /** @var array<string,mixed>|null */
            public ?array $lastPayload = null;
            public mixed $lastCriteria = null;

            public function insert(string $table, array $payload, array $options = []): mixed
            {
                $this->lastTable = $table;
                $this->lastPayload = $payload;
                return true;
            }

            public function update(string $table, array $payload, array $criteria, array $options = []): mixed
            {
                $this->lastTable = $table;
                $this->lastPayload = $payload;
                $this->lastCriteria = $criteria;
                return true;
            }
        };

        $adapter = new DatabaseCryptoAdapter($this->crypto, $map, $spy);
        $adapter->insert('users', [
            'id' => 1,
            'ssn' => '123-45-6789',
            'email_hash' => 'alice@example.com',
        ]);

        self::assertSame('users', $spy->lastTable);
        self::assertIsArray($spy->lastPayload);
        self::assertSame(1, $spy->lastPayload['id']);
        self::assertNotSame('123-45-6789', $spy->lastPayload['ssn']);
        self::assertStringStartsWith('{', (string)$spy->lastPayload['ssn']);
        self::assertNotSame('alice@example.com', $spy->lastPayload['email_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', (string)$spy->lastPayload['email_hash']);
    }

    public function testDecryptPayloadReversesEncryptStrategy(): void
    {
        $map = EncryptionMap::fromArray([
            'tables' => [
                'users' => [
                    'columns' => [
                        'ssn' => ['strategy' => 'encrypt', 'context' => 'core.vault'],
                        'email_hash' => ['strategy' => 'hmac', 'context' => 'core.hmac.email', 'encoding' => 'hex'],
                    ],
                ],
            ],
        ]);

        $nullGateway = new class implements DatabaseGatewayInterface {
            public function insert(string $table, array $payload, array $options = []): mixed { return true; }
            public function update(string $table, array $payload, array $criteria, array $options = []): mixed { return true; }
        };

        $adapter = new DatabaseCryptoAdapter($this->crypto, $map, $nullGateway);
        $encrypted = $adapter->encryptPayload('users', [
            'id' => 1,
            'ssn' => '123-45-6789',
            'email_hash' => 'alice@example.com',
        ]);

        $decrypted = $adapter->decryptPayload('users', $encrypted);

        self::assertSame('123-45-6789', $decrypted['ssn']);
        self::assertSame($encrypted['email_hash'], $decrypted['email_hash']); // HMAC is not reversible.
    }
}
