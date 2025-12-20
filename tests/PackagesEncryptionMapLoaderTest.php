<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\DatabaseCrypto\Config\PackagesEncryptionMapLoader;
use PHPUnit\Framework\TestCase;

final class PackagesEncryptionMapLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (array_reverse($this->cleanup) as $path) {
            $this->rmrf($path);
        }
        $this->cleanup = [];
    }

    public function testLoadsAndMergesPerPackageEncryptionMaps(): void
    {
        $root = $this->makeTempDir('bcdbcrypto-packages-');
        $packagesDir = $root . '/packages';
        mkdir($packagesDir, 0777, true);

        $this->writePackage(
            $packagesDir,
            'users',
            'TestPkg\\Users' . bin2hex(random_bytes(4)),
            'users',
            ['id', 'email_hash', 'email_hash_key_version'],
            [
                'tables' => [
                    'users' => [
                        'columns' => [
                            'id' => ['strategy' => 'passthrough'],
                            'email_hash' => [
                                'strategy' => 'hmac',
                                'context' => 'core.hmac.email',
                                'encoding' => 'hex',
                                'write_key_version' => true,
                                'key_version_column' => 'email_hash_key_version',
                            ],
                            'email_hash_key_version' => ['strategy' => 'passthrough'],
                        ],
                    ],
                ],
            ]
        );

        $this->writePackage(
            $packagesDir,
            'orders',
            'TestPkg\\Orders' . bin2hex(random_bytes(4)),
            'orders',
            ['uuid_bin', 'encrypted_customer_blob', 'encrypted_customer_blob_key_version', 'encryption_meta'],
            [
                'tables' => [
                    'orders' => [
                        'columns' => [
                            'uuid_bin' => ['strategy' => 'passthrough'],
                            'encrypted_customer_blob' => [
                                'strategy' => 'encrypt',
                                'context' => 'core.vault',
                                'write_key_version' => true,
                                'write_encryption_meta' => true,
                            ],
                            'encrypted_customer_blob_key_version' => ['strategy' => 'passthrough'],
                            'encryption_meta' => ['strategy' => 'passthrough'],
                        ],
                    ],
                ],
            ]
        );

        $map = PackagesEncryptionMapLoader::fromPackagesDir($packagesDir);

        $tables = array_keys($map->all());
        sort($tables);
        self::assertSame(['orders', 'users'], $tables);

        $users = $map->columnsFor('users');
        $orders = $map->columnsFor('orders');
        self::assertNotNull($users);
        self::assertNotNull($orders);
        self::assertSame('passthrough', strtolower((string)($users['id']['strategy'] ?? '')));
        self::assertSame('hmac', strtolower((string)($users['email_hash']['strategy'] ?? '')));
    }

    public function testFailsWhenEncryptionMapIsMissing(): void
    {
        $root = $this->makeTempDir('bcdbcrypto-packages-missing-map-');
        $packagesDir = $root . '/packages';
        mkdir($packagesDir, 0777, true);

        $this->writeDefinitionsOnly(
            $packagesDir,
            'users',
            'TestPkg\\Users' . bin2hex(random_bytes(4)),
            'users',
            ['id']
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing encryption map');
        PackagesEncryptionMapLoader::fromPackagesDir($packagesDir);
    }

    public function testFailsWhenMapDoesNotCoverAllColumns(): void
    {
        $root = $this->makeTempDir('bcdbcrypto-packages-missing-col-');
        $packagesDir = $root . '/packages';
        mkdir($packagesDir, 0777, true);

        $this->writePackage(
            $packagesDir,
            'users',
            'TestPkg\\Users' . bin2hex(random_bytes(4)),
            'users',
            ['id', 'ssn'],
            [
                'tables' => [
                    'users' => [
                        'columns' => [
                            'id' => ['strategy' => 'passthrough'],
                        ],
                    ],
                ],
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing columns');
        PackagesEncryptionMapLoader::fromPackagesDir($packagesDir);
    }

    public function testFailsWhenMapHasUnknownColumns(): void
    {
        $root = $this->makeTempDir('bcdbcrypto-packages-extra-col-');
        $packagesDir = $root . '/packages';
        mkdir($packagesDir, 0777, true);

        $this->writePackage(
            $packagesDir,
            'users',
            'TestPkg\\Users' . bin2hex(random_bytes(4)),
            'users',
            ['id'],
            [
                'tables' => [
                    'users' => [
                        'columns' => [
                            'id' => ['strategy' => 'passthrough'],
                            'typo' => ['strategy' => 'passthrough'],
                        ],
                    ],
                ],
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown columns');
        PackagesEncryptionMapLoader::fromPackagesDir($packagesDir);
    }

    public function testFailsWhenMapDefinesMultipleTables(): void
    {
        $root = $this->makeTempDir('bcdbcrypto-packages-multi-table-');
        $packagesDir = $root . '/packages';
        mkdir($packagesDir, 0777, true);

        $this->writePackage(
            $packagesDir,
            'users',
            'TestPkg\\Users' . bin2hex(random_bytes(4)),
            'users',
            ['id'],
            [
                'tables' => [
                    'users' => ['columns' => ['id' => ['strategy' => 'passthrough']]],
                    'orders' => ['columns' => ['id' => ['strategy' => 'passthrough']]],
                ],
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exactly 1 table');
        PackagesEncryptionMapLoader::fromPackagesDir($packagesDir);
    }

    public function testFailsWhenMapTableNameDoesNotMatchPackage(): void
    {
        $root = $this->makeTempDir('bcdbcrypto-packages-table-mismatch-');
        $packagesDir = $root . '/packages';
        mkdir($packagesDir, 0777, true);

        $this->writePackage(
            $packagesDir,
            'users',
            'TestPkg\\Users' . bin2hex(random_bytes(4)),
            'users',
            ['id'],
            [
                'tables' => [
                    'accounts' => [
                        'columns' => [
                            'id' => ['strategy' => 'passthrough'],
                        ],
                    ],
                ],
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('table mismatch');
        PackagesEncryptionMapLoader::fromPackagesDir($packagesDir);
    }

    public function testFailsWhenKeyVersionColumnIsMissingFromSchema(): void
    {
        $root = $this->makeTempDir('bcdbcrypto-packages-kv-missing-');
        $packagesDir = $root . '/packages';
        mkdir($packagesDir, 0777, true);

        $this->writePackage(
            $packagesDir,
            'users',
            'TestPkg\\Users' . bin2hex(random_bytes(4)),
            'users',
            ['id', 'email_hash'],
            [
                'tables' => [
                    'users' => [
                        'columns' => [
                            'id' => ['strategy' => 'passthrough'],
                            'email_hash' => [
                                'strategy' => 'hmac',
                                'context' => 'core.hmac.email',
                                'write_key_version' => true,
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing key version column');
        PackagesEncryptionMapLoader::fromPackagesDir($packagesDir);
    }

    public function testFailsWhenKeyVersionColumnIsNotPassthrough(): void
    {
        $root = $this->makeTempDir('bcdbcrypto-packages-kv-not-passthrough-');
        $packagesDir = $root . '/packages';
        mkdir($packagesDir, 0777, true);

        $this->writePackage(
            $packagesDir,
            'users',
            'TestPkg\\Users' . bin2hex(random_bytes(4)),
            'users',
            ['id', 'email_hash', 'email_hash_key_version'],
            [
                'tables' => [
                    'users' => [
                        'columns' => [
                            'id' => ['strategy' => 'passthrough'],
                            'email_hash' => [
                                'strategy' => 'hmac',
                                'context' => 'core.hmac.email',
                                'write_key_version' => true,
                                'key_version_column' => 'email_hash_key_version',
                            ],
                            'email_hash_key_version' => [
                                'strategy' => 'encrypt',
                                'context' => 'core.vault',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Key version column');
        $this->expectExceptionMessage('strategy=passthrough');
        PackagesEncryptionMapLoader::fromPackagesDir($packagesDir);
    }

    public function testFailsWhenEncryptionMetaColumnIsMissingFromSchema(): void
    {
        $root = $this->makeTempDir('bcdbcrypto-packages-meta-missing-');
        $packagesDir = $root . '/packages';
        mkdir($packagesDir, 0777, true);

        $this->writePackage(
            $packagesDir,
            'orders',
            'TestPkg\\Orders' . bin2hex(random_bytes(4)),
            'orders',
            ['uuid_bin', 'encrypted_customer_blob'],
            [
                'tables' => [
                    'orders' => [
                        'columns' => [
                            'uuid_bin' => ['strategy' => 'passthrough'],
                            'encrypted_customer_blob' => [
                                'strategy' => 'encrypt',
                                'context' => 'core.vault',
                                'write_encryption_meta' => true,
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing encryption meta column');
        PackagesEncryptionMapLoader::fromPackagesDir($packagesDir);
    }

    public function testFailsWhenEncryptionMetaColumnIsNotPassthrough(): void
    {
        $root = $this->makeTempDir('bcdbcrypto-packages-meta-not-passthrough-');
        $packagesDir = $root . '/packages';
        mkdir($packagesDir, 0777, true);

        $this->writePackage(
            $packagesDir,
            'orders',
            'TestPkg\\Orders' . bin2hex(random_bytes(4)),
            'orders',
            ['uuid_bin', 'encrypted_customer_blob', 'encryption_meta'],
            [
                'tables' => [
                    'orders' => [
                        'columns' => [
                            'uuid_bin' => ['strategy' => 'passthrough'],
                            'encrypted_customer_blob' => [
                                'strategy' => 'encrypt',
                                'context' => 'core.vault',
                                'write_encryption_meta' => true,
                                'encryption_meta_column' => 'encryption_meta',
                            ],
                            'encryption_meta' => [
                                'strategy' => 'encrypt',
                                'context' => 'core.vault.meta',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Encryption meta column');
        $this->expectExceptionMessage('strategy=passthrough');
        PackagesEncryptionMapLoader::fromPackagesDir($packagesDir);
    }

    public function testFailsWhenUniqueKeyIncludesEncryptColumn(): void
    {
        $root = $this->makeTempDir('bcdbcrypto-packages-unique-encrypt-');
        $packagesDir = $root . '/packages';
        mkdir($packagesDir, 0777, true);

        $pkgDir = rtrim($packagesDir, '/\\') . '/t';
        mkdir($pkgDir . '/src', 0777, true);
        mkdir($pkgDir . '/schema', 0777, true);

        $namespace = 'TestPkg\\T' . bin2hex(random_bytes(4));
        $php = <<<PHP
<?php
declare(strict_types=1);

namespace {$namespace};

final class Definitions
{
    public static function table(): string { return 't'; }
    /** @return string[] */
    public static function columns(): array { return ['id', 'secret']; }
    /** @return array<int,array<int,string>> */
    public static function uniqueKeys(): array { return [['secret']]; }
}

PHP;
        file_put_contents($pkgDir . '/src/Definitions.php', $php);

        $map = [
            'tables' => [
                't' => [
                    'columns' => [
                        'id' => ['strategy' => 'passthrough'],
                        'secret' => ['strategy' => 'encrypt', 'context' => 'core.vault'],
                    ],
                ],
            ],
        ];
        file_put_contents($pkgDir . '/schema/encryption-map.json', json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unique key');
        $this->expectExceptionMessage('strategy=encrypt');
        PackagesEncryptionMapLoader::fromPackagesDir($packagesDir);
    }

    private function makeTempDir(string $prefix): string
    {
        $base = rtrim(sys_get_temp_dir(), '/\\');
        $dir = $base . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create temp dir: ' . $dir);
        }
        $this->cleanup[] = $dir;
        return $dir;
    }

    /**
     * @param list<string> $columns
     * @param array<string,mixed> $map
     */
    private function writePackage(
        string $packagesDir,
        string $packageName,
        string $namespace,
        string $table,
        array $columns,
        array $map
    ): void {
        $pkgDir = rtrim($packagesDir, '/\\') . '/' . $packageName;
        mkdir($pkgDir . '/src', 0777, true);
        mkdir($pkgDir . '/schema', 0777, true);

        $this->writeDefinitionsFile($pkgDir . '/src/Definitions.php', $namespace, $table, $columns);

        $json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode map JSON');
        }
        file_put_contents($pkgDir . '/schema/encryption-map.json', $json);
    }

    /**
     * @param list<string> $columns
     */
    private function writeDefinitionsOnly(
        string $packagesDir,
        string $packageName,
        string $namespace,
        string $table,
        array $columns
    ): void {
        $pkgDir = rtrim($packagesDir, '/\\') . '/' . $packageName;
        mkdir($pkgDir . '/src', 0777, true);
        mkdir($pkgDir . '/schema', 0777, true);
        $this->writeDefinitionsFile($pkgDir . '/src/Definitions.php', $namespace, $table, $columns);
    }

    /**
     * @param list<string> $columns
     */
    private function writeDefinitionsFile(string $path, string $namespace, string $table, array $columns): void
    {
        $cols = var_export($columns, true);
        $php = <<<PHP
<?php
declare(strict_types=1);

namespace {$namespace};

final class Definitions
{
    public static function table(): string { return '{$table}'; }
    /** @return string[] */
    public static function columns(): array { return {$cols}; }
}

PHP;
        file_put_contents($path, $php);
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path . DIRECTORY_SEPARATOR . $item);
        }
        @rmdir($path);
    }
}
