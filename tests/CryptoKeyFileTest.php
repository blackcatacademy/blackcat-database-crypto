<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\DatabaseCrypto\Ops\CryptoKeyFile;
use PHPUnit\Framework\TestCase;

final class CryptoKeyFileTest extends TestCase
{
    public function testBuildsCryptoKeysRow(): void
    {
        $file = CryptoKeyFile::fromPath(__DIR__ . '/fixtures/keys/crypto_key_v1.key');
        $row = $file->toCryptoKeysRow(['slot' => 'core.vault']);

        self::assertSame('crypto_key', $row['basename']);
        self::assertSame(1, $row['version']);
        self::assertSame('local', $row['origin']);
        self::assertSame('active', $row['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string)$row['fingerprint']);
        self::assertSame($file->lengthBits, $row['length_bits']);

        $meta = json_decode((string)$row['key_meta'], true);
        self::assertIsArray($meta);
        self::assertSame('filesystem', $meta['source'] ?? null);
        self::assertSame('core.vault', $meta['slot'] ?? null);
    }
}

