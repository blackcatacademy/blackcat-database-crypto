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
