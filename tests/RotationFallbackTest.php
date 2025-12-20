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

final class RotationFallbackTest extends TestCase
{
    private string $tmpKeysDir;

    private string $vaultContext = 'db.vault.t.secret';
    private string $hmacContext = 'db.hmac.t.email_hash';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpKeysDir = sys_get_temp_dir() . '/bcat-dbcrypto-keys-' . bin2hex(random_bytes(4));
        if (!is_dir($this->tmpKeysDir)) {
            mkdir($this->tmpKeysDir, 0770, true);
        }

        // v1 keys
        $this->writeHexKeyFile($this->vaultContext, 1, str_repeat('11', 32));
        $this->writeHexKeyFile($this->hmacContext, 1, str_repeat('22', 32));
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpKeysDir) && is_dir($this->tmpKeysDir)) {
            foreach (glob($this->tmpKeysDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpKeysDir);
        }

        parent::tearDown();
    }

    public function testEncryptDecryptSupportsRotationAndFallback(): void
    {
        $crypto = CryptoManager::boot(CryptoConfig::fromEnv([
            'BLACKCAT_KEYS_DIR' => $this->tmpKeysDir,
        ]));

        $map = EncryptionMap::fromArray([
            'tables' => [
                't' => [
                    'columns' => [
                        'secret' => [
                            'strategy' => 'encrypt',
                            'context' => $this->vaultContext,
                            'write_key_version' => true,
                            'key_version_column' => 'secret_key_version',
                        ],
                        'secret_key_version' => [
                            'strategy' => 'passthrough',
                        ],
                        'email_hash' => [
                            'strategy' => 'hmac',
                            'context' => $this->hmacContext,
                            'encoding' => 'hex',
                            'write_key_version' => true,
                            'key_version_column' => 'email_hash_key_version',
                        ],
                        'email_hash_key_version' => [
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

        $payload = [
            'secret' => ['email' => 'alice@example.com', 'note' => 'hello'],
            'email_hash' => 'alice@example.com',
        ];

        // Encrypt with v1 only.
        $encryptedV1 = $ingress->encrypt('t', $payload);
        self::assertSame($this->keyIdForContext($this->vaultContext, 1), $encryptedV1['secret_key_version'] ?? null);
        self::assertSame($this->keyIdForContext($this->hmacContext, 1), $encryptedV1['email_hash_key_version'] ?? null);
        self::assertIsString($encryptedV1['secret'] ?? null);
        self::assertStringStartsWith('{', (string)$encryptedV1['secret']);
        self::assertFalse(str_contains((string)$encryptedV1['secret'], 'alice@example.com'), 'Ciphertext must not contain plaintext.');

        // Rotate: add v2 keys.
        $this->writeHexKeyFile($this->vaultContext, 2, str_repeat('33', 32));
        $this->writeHexKeyFile($this->hmacContext, 2, str_repeat('44', 32));

        $encryptedV2 = $ingress->encrypt('t', $payload);
        self::assertSame($this->keyIdForContext($this->vaultContext, 2), $encryptedV2['secret_key_version'] ?? null);
        self::assertSame($this->keyIdForContext($this->hmacContext, 2), $encryptedV2['email_hash_key_version'] ?? null);
        self::assertNotSame($encryptedV1['email_hash'] ?? null, $encryptedV2['email_hash'] ?? null);

        // Decrypt should work with correct key id (v1 still present).
        $decrypted = $ingress->decrypt('t', $encryptedV1);
        self::assertSame(json_encode($payload['secret'], JSON_UNESCAPED_SLASHES), $decrypted['secret'] ?? null);

        // Fallback decrypt: tamper the envelope keyId to v2 (wrong).
        $tampered = $this->tamperEnvelopeKeyId((string)$encryptedV1['secret'], $this->keyIdForContext($this->vaultContext, 2));
        $tamperedPayload = $encryptedV1;
        $tamperedPayload['secret'] = $tampered;
        $decryptedFallback = $ingress->decrypt('t', $tamperedPayload);
        self::assertSame(json_encode($payload['secret'], JSON_UNESCAPED_SLASHES), $decryptedFallback['secret'] ?? null);

        // HMAC verify fallback: signature created with v1 should verify even if keyId hint is v2.
        $sigBytes = hex2bin((string)($encryptedV1['email_hash'] ?? ''));
        self::assertIsString($sigBytes);
        self::assertTrue($crypto->verifyHmacWithKeyId($this->hmacContext, 'alice@example.com', $sigBytes, $this->keyIdForContext($this->hmacContext, 2)));

        // If old key is removed, decrypt must fail.
        @unlink($this->keyFilePath($this->vaultContext, 1));
        $this->expectException(\Throwable::class);
        $ingress->decrypt('t', $encryptedV1);
    }

    private function keyFilePath(string $context, int $version): string
    {
        return $this->tmpKeysDir . '/' . $this->keyFileBase($context) . '_v' . $version . '.hex';
    }

    private function keyIdForContext(string $context, int $version): string
    {
        return $this->keyFileBase($context) . '_v' . $version . '.key';
    }

    private function keyFileBase(string $context): string
    {
        $base = strtolower(str_replace('.', '_', $context));
        $base = preg_replace('~[^a-z0-9_.-]+~', '_', $base) ?: 'key';
        return $base;
    }

    private function writeHexKeyFile(string $context, int $version, string $hex): void
    {
        $hex = strtolower(trim($hex));
        if ($hex === '' || (strlen($hex) % 2) !== 0 || !ctype_xdigit($hex)) {
            throw new \InvalidArgumentException('Invalid hex key material.');
        }
        file_put_contents($this->keyFilePath($context, $version), $hex . PHP_EOL);
    }

    private function tamperEnvelopeKeyId(string $serializedEnvelope, string $newKeyId): string
    {
        $decoded = json_decode($serializedEnvelope, true);
        if (!is_array($decoded) || !isset($decoded['local']) || !is_array($decoded['local'])) {
            throw new \RuntimeException('Invalid envelope JSON');
        }
        $decoded['local']['keyId'] = $newKeyId;
        $json = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode envelope JSON');
        }
        return $json;
    }
}

