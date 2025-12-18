<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\DatabaseCrypto\Ops\CryptoKeyName;
use PHPUnit\Framework\TestCase;

final class CryptoKeyNameTest extends TestCase
{
    public function testParsesVersionSuffix(): void
    {
        $name = CryptoKeyName::fromFilename('Users.PII_v12.key');

        self::assertSame('users.pii', $name->basename);
        self::assertSame(12, $name->version);
    }

    public function testRejectsMissingVersionSuffix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CryptoKeyName::fromFilename('legacy.key');
    }
}
