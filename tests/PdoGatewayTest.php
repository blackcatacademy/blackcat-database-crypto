<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\Crypto\Config\CryptoConfig;
use BlackCat\Crypto\CryptoManager;
use BlackCat\DatabaseCrypto\Adapter\DatabaseCryptoAdapter;
use BlackCat\DatabaseCrypto\Config\EncryptionMap;
use BlackCat\DatabaseCrypto\Gateway\PdoGateway;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoGatewayTest extends TestCase
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

    public function testInsertAndDecryptRoundTripWithSQLite(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, ssn TEXT, email_hash TEXT)');

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

        $adapter = new DatabaseCryptoAdapter($this->crypto, $map, new PdoGateway($pdo));
        $adapter->insert('users', [
            'id' => 1,
            'ssn' => '123-45-6789',
            'email_hash' => 'alice@example.com',
        ]);

        $row = $pdo->query('SELECT ssn, email_hash FROM users WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertNotSame('123-45-6789', $row['ssn']);
        self::assertStringStartsWith('{', (string)$row['ssn']);
        self::assertNotSame('alice@example.com', $row['email_hash']);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', (string)$row['email_hash']);

        $decrypted = $adapter->decryptPayload('users', $row);
        self::assertSame('123-45-6789', $decrypted['ssn']);
        self::assertSame($row['email_hash'], $decrypted['email_hash']);
    }
}
