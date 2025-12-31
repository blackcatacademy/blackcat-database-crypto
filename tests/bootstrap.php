<?php
declare(strict_types=1);

/**
 * Test bootstrap that works both:
 * - in the standalone repo (vendor/autoload.php present), and
 * - in the monorepo (reuse sibling vendors + register local PSR-4).
 */

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../blackcat-database/vendor/autoload.php',
    __DIR__ . '/../../blackcat-database/vendor/autoload.php',
    __DIR__ . '/../../blackcat-crypto/vendor/autoload.php',
    __DIR__ . '/../../blackcat-core/vendor/autoload.php',
];

$autoloadFound = false;
foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        require $candidate;
        $autoloadFound = true;
        break;
    }
}

if (!$autoloadFound) {
    throw new RuntimeException('Cannot find an autoloader; run composer install or use the monorepo vendor.');
}

/**
 * Minimal PSR-4 loader for local development (composer-less or monorepo runs).
 */
function dbcrypto_register_psr4(string $prefix, string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    spl_autoload_register(static function (string $class) use ($prefix, $dir): void {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = $dir . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }, true, true);
}

dbcrypto_register_psr4('BlackCat\\DatabaseCrypto\\', __DIR__ . '/../src');
dbcrypto_register_psr4('BlackCat\\Crypto\\', __DIR__ . '/../../blackcat-crypto/src');
dbcrypto_register_psr4('BlackCat\\Config\\', __DIR__ . '/../../blackcat-config/src');
dbcrypto_register_psr4('BlackCat\\Database\\', __DIR__ . '/../../blackcat-database/src');
dbcrypto_register_psr4('BlackCat\\Database\\', __DIR__ . '/../blackcat-database/src');
dbcrypto_register_psr4('BlackCat\\Core\\', __DIR__ . '/../../blackcat-core/src');

/**
 * Autoload blackcat-database generated packages from a checked-out repo (submodules included).
 *
 * CI path (this repo root): ./blackcat-database/...
 * Monorepo path: ../blackcat-database/...
 */
function dbcrypto_register_blackcat_database_packages(): void
{
    $candidates = [];
    $fromEnv = getenv('BLACKCAT_DB_ROOT');
    if (is_string($fromEnv) && $fromEnv !== '') {
        $candidates[] = $fromEnv;
    }
    $candidates[] = __DIR__ . '/../blackcat-database';
    $candidates[] = __DIR__ . '/../../blackcat-database';

    $dbRoot = null;
    foreach ($candidates as $c) {
        $real = realpath($c);
        if ($real !== false && is_dir($real . '/packages')) {
            $dbRoot = $real;
            break;
        }
    }
    if ($dbRoot === null) {
        return;
    }

    $packagesDir = $dbRoot . '/packages';
    $map = []; // ['Orders' => '/path/to/packages/orders/src', ...]

    $entries = @scandir($packagesDir);
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $pkgFolder) {
        if ($pkgFolder === '.' || $pkgFolder === '..') {
            continue;
        }
        $src = $packagesDir . '/' . $pkgFolder . '/src';
        if (!is_dir($src)) {
            continue;
        }

        $parts = preg_split('/[_-]+/', $pkgFolder) ?: [];
        $pascal = implode('', array_map(static fn(string $p): string => $p === '' ? '' : ucfirst($p), $parts));
        if ($pascal !== '') {
            $map[$pascal] = $src;
        }
    }

    if ($map === []) {
        return;
    }

    spl_autoload_register(static function (string $class) use ($map): void {
        $prefix = 'BlackCat\\Database\\Packages\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix)); // e.g. Orders\Repository\OrderRepository
        $parts = explode('\\', $relative);
        $pkg = array_shift($parts);
        if (!is_string($pkg) || $pkg === '') {
            return;
        }

        $base = $map[$pkg] ?? null;
        if (!is_string($base) || $base === '') {
            return;
        }

        $path = $base . '/' . str_replace('\\', '/', implode('\\', $parts)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }, true, true);
}

dbcrypto_register_blackcat_database_packages();

/**
 * Helper to set env vars in a PHPUnit-friendly way.
 */
function dbcrypto_tests_set_env(string $key, string $value): void
{
    if ($value === '') {
        return;
    }

    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

/**
 * Auto-configure DB_DSN for integration tests when running in Docker.
 *
 * This repo contains a few DB integration tests. We don't want them skipped when
 * DB_DSN is not set, so we try to discover a known local test DB (service names).
 */
function dbcrypto_tests_autoconfigure_db_env(): void
{
    $dsn = getenv('DB_DSN');
    if (is_string($dsn) && $dsn !== '') {
        return;
    }

    $legacy = getenv('BC_TEST_DSN');
    if (is_string($legacy) && $legacy !== '') {
        dbcrypto_tests_set_env('DB_DSN', $legacy);
        return;
    }

    $user = getenv('DB_USER');
    if (!is_string($user) || $user === '') {
        $user = (string)(getenv('BC_TEST_DB_USER') ?: '');
    }

    $pass = getenv('DB_PASSWORD');
    if (!is_string($pass) || $pass === '') {
        $pass = (string)(getenv('BC_TEST_DB_PASS') ?: '');
    }

    $dbName = getenv('DB_NAME');
    if (!is_string($dbName) || $dbName === '') {
        $dbName = (string)(getenv('BC_TEST_DB_NAME') ?: 'test');
    }

    if (extension_loaded('pdo_mysql')) {
        if ($user === '') {
            $user = 'root';
        }
        if ($pass === '') {
            $pass = 'root';
        }

        $hosts = ['bc-mysql-test', 'mysql', 'mariadb', 'bc-mysql'];
        foreach ($hosts as $host) {
            $candidate = sprintf('mysql:host=%s;port=3306;dbname=%s;charset=utf8mb4', $host, $dbName);
            try {
                $options = [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 1,
                ];
                if (defined('\PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
                    $options[\PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 1;
                }

                $pdo = new \PDO($candidate, $user, $pass, $options);
                $pdo->query('SELECT 1');

                dbcrypto_tests_set_env('DB_DSN', $candidate);
                dbcrypto_tests_set_env('DB_USER', $user);
                dbcrypto_tests_set_env('DB_PASSWORD', $pass);
                return;
            } catch (\Throwable) {
                // try next host
            }
        }
    }

    if (extension_loaded('pdo_pgsql')) {
        if ($user === '') {
            $user = 'postgres';
        }
        if ($pass === '') {
            $pass = 'postgres';
        }

        $hosts = ['bc-postgres-test', 'postgres', 'bc-postgres'];
        foreach ($hosts as $host) {
            $candidate = sprintf('pgsql:host=%s;port=5432;dbname=%s', $host, $dbName);
            try {
                $pdo = new \PDO($candidate, $user, $pass, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 1,
                ]);
                $pdo->query('SELECT 1');

                dbcrypto_tests_set_env('DB_DSN', $candidate);
                dbcrypto_tests_set_env('DB_USER', $user);
                dbcrypto_tests_set_env('DB_PASSWORD', $pass);
                return;
            } catch (\Throwable) {
                // try next host
            }
        }
    }
}

dbcrypto_tests_autoconfigure_db_env();

/**
 * Ensure `blackcat-config` runtime config is available for tests that exercise the DB ingress locator.
 *
 * IngressLocator is fail-closed and reads:
 * - crypto.keys_dir
 * - crypto.manifest
 */
if (class_exists('\\BlackCat\\Config\\Runtime\\Config') && !\BlackCat\Config\Runtime\Config::isInitialized()) {
    $dbRoot = null;
    $candidates = [];
    $fromEnv = getenv('BLACKCAT_DB_ROOT');
    if (is_string($fromEnv) && trim($fromEnv) !== '') {
        $candidates[] = $fromEnv;
    }
    $candidates[] = __DIR__ . '/../blackcat-database';
    $candidates[] = __DIR__ . '/../../blackcat-database';

    foreach ($candidates as $c) {
        $real = realpath($c);
        if ($real !== false && is_dir($real . '/packages')) {
            $dbRoot = $real;
            break;
        }
    }

    if ($dbRoot !== null) {
        $tmpRoot = rtrim(sys_get_temp_dir(), '/\\') . '/blackcat-dbcrypto-tests-' . bin2hex(random_bytes(8));
        $keysDir = $tmpRoot . '/keys';
        $manifestPath = $tmpRoot . '/manifest.json';
        $runtimeConfigPath = $tmpRoot . '/config.runtime.json';

        if (!mkdir($keysDir, 0700, true) && !is_dir($keysDir)) {
            throw new RuntimeException('tests/bootstrap: unable to create keys dir: ' . $keysDir);
        }

        $map = \BlackCat\DatabaseCrypto\Config\PackagesEncryptionMapLoader::fromBlackcatDatabaseRoot($dbRoot);
        $slots = [];

        foreach ($map->all() as $table => $cols) {
            foreach ($cols as $col => $spec) {
                if (!is_array($spec)) {
                    continue;
                }

                $strategy = strtolower((string)($spec['strategy'] ?? 'passthrough'));
                if ($strategy === 'passthrough') {
                    continue;
                }

                $context = $spec['context'] ?? null;
                if (!is_string($context) || trim($context) === '') {
                    throw new RuntimeException(sprintf('tests/bootstrap: missing context for %s.%s (strategy=%s)', (string)$table, (string)$col, $strategy));
                }

                $type = match ($strategy) {
                    'encrypt' => 'aead',
                    'hmac' => 'hmac',
                    default => throw new RuntimeException(sprintf('tests/bootstrap: unsupported strategy for %s.%s: %s', (string)$table, (string)$col, $strategy)),
                };
                $length = $type === 'hmac' ? 64 : 32;

                $keyBase = strtolower(preg_replace('~[^a-zA-Z0-9_.-]+~', '_', $context) ?: $context);
                $keyBase = strtolower(str_replace(['.', '-'], '_', $keyBase));
                $keyBase = trim($keyBase, '_');
                if ($keyBase === '') {
                    throw new RuntimeException('tests/bootstrap: unable to derive key basename for context: ' . $context);
                }
                if (strlen($keyBase) > 120) {
                    $keyBase = substr($keyBase, 0, 96) . '_' . substr(hash('sha256', $keyBase), 0, 16);
                }

                $slots[$context] = [
                    'type' => $type,
                    'key' => $keyBase,
                    'length' => $length,
                ];
            }
        }

        ksort($slots);

        foreach ($slots as $context => $def) {
            $keyName = (string)($def['key'] ?? '');
            $length = (int)($def['length'] ?? 0);
            if ($keyName === '' || $length <= 0) {
                throw new RuntimeException('tests/bootstrap: invalid manifest slot for context ' . (string)$context);
            }

            $file = $keysDir . '/' . $keyName . '_v1.key';
            if (file_put_contents($file, random_bytes($length)) === false) {
                throw new RuntimeException('tests/bootstrap: unable to write key file: ' . $file);
            }
            @chmod($file, 0600);
        }

        $manifest = [
            'slots' => $slots,
            'rotation' => new stdClass(),
        ];
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($manifestPath, $json) === false) {
            throw new RuntimeException('tests/bootstrap: unable to write manifest file: ' . $manifestPath);
        }
        @chmod($manifestPath, 0644);

        $runtime = [
            'crypto' => [
                'keys_dir' => $keysDir,
                'manifest' => $manifestPath,
            ],
        ];
        $json = json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($runtimeConfigPath, $json) === false) {
            throw new RuntimeException('tests/bootstrap: unable to write runtime config file: ' . $runtimeConfigPath);
        }
        @chmod($runtimeConfigPath, 0600);

        \BlackCat\Config\Runtime\Config::initFromJsonFile($runtimeConfigPath);
    }
}
