<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\DatabaseCrypto\Config\EncryptionMap;
use PHPUnit\Framework\TestCase;

final class EncryptionMapIncludesTest extends TestCase
{
    public function testFromFileResolvesIncludesAndAllowsOverrides(): void
    {
        $dir = sys_get_temp_dir() . '/bc-dbcrypto-map-' . bin2hex(random_bytes(6));
        if (!mkdir($dir) && !is_dir($dir)) {
            self::fail('Unable to create temp dir for test: ' . $dir);
        }

        $core = $dir . '/core.json';
        $auth = $dir . '/auth.json';
        $app = $dir . '/app.json';

        try {
            file_put_contents($core, json_encode([
                'tables' => [
                    'users' => [
                        'columns' => [
                            'email_hash' => [
                                'strategy' => 'hmac',
                                'context' => 'core.hmac.email',
                            ],
                        ],
                    ],
                ],
            ], JSON_PRETTY_PRINT));

            file_put_contents($auth, json_encode([
                'tables' => [
                    'users' => [
                        'columns' => [
                            'password_hash' => [
                                'strategy' => 'passthrough',
                                'context' => 'core.password.hash',
                            ],
                        ],
                    ],
                ],
            ], JSON_PRETTY_PRINT));

            file_put_contents($app, json_encode([
                'includes' => ['./core.json', './auth.json'],
                'tables' => [
                    'users' => [
                        'columns' => [
                            'email_hash' => [
                                'write_key_version' => true,
                            ],
                        ],
                    ],
                ],
            ], JSON_PRETTY_PRINT));

            $map = EncryptionMap::fromFile($app);
            $users = $map->columnsFor('users');
            self::assertIsArray($users);

            self::assertSame('core.hmac.email', $users['email_hash']['context'] ?? null);
            self::assertSame('hmac', $users['email_hash']['strategy'] ?? null);
            self::assertSame(true, $users['email_hash']['write_key_version'] ?? null);

            self::assertSame('passthrough', $users['password_hash']['strategy'] ?? null);
            self::assertSame('core.password.hash', $users['password_hash']['context'] ?? null);
        } finally {
            @unlink($app);
            @unlink($auth);
            @unlink($core);
            @rmdir($dir);
        }
    }

    public function testFromFileDetectsIncludeCycles(): void
    {
        $dir = sys_get_temp_dir() . '/bc-dbcrypto-map-cycle-' . bin2hex(random_bytes(6));
        if (!mkdir($dir) && !is_dir($dir)) {
            self::fail('Unable to create temp dir for test: ' . $dir);
        }

        $a = $dir . '/a.json';
        $b = $dir . '/b.json';

        try {
            file_put_contents($a, json_encode(['includes' => ['./b.json'], 'tables' => []], JSON_PRETTY_PRINT));
            file_put_contents($b, json_encode(['includes' => ['./a.json'], 'tables' => []], JSON_PRETTY_PRINT));

            $this->expectException(\RuntimeException::class);
            EncryptionMap::fromFile($a);
        } finally {
            @unlink($b);
            @unlink($a);
            @rmdir($dir);
        }
    }
}

