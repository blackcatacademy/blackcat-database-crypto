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
