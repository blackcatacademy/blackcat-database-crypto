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

final class DatabaseIngressAdapterCriteriaTest extends TestCase
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

    public function testCriteriaTransformsHmacColumns(): void
    {
        $map = EncryptionMap::fromArray([
            'tables' => [
                'users' => [
                    'columns' => [
                        'email_hash' => ['strategy' => 'hmac', 'context' => 'core.hmac.email', 'encoding' => 'hex'],
                        'ssn' => ['strategy' => 'encrypt', 'context' => 'core.vault'],
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

        self::assertNotSame('alice@example.com', $out['email_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', (string)$out['email_hash']);
    }

    public function testCriteriaRejectsEncryptColumns(): void
    {
        $map = EncryptionMap::fromArray([
            'tables' => [
                'users' => [
                    'columns' => [
                        'ssn' => ['strategy' => 'encrypt', 'context' => 'core.vault'],
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

        $this->expectException(\InvalidArgumentException::class);
        $ingress->criteria('users', [
            'ssn' => '123-45-6789',
        ]);
    }
}

