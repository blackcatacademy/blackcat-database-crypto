<?php
declare(strict_types=1);

/**
 * PHPStan bootstrap that works both:
 * - in the standalone repo (vendor/autoload.php present), and
 * - in the monorepo (reuse sibling vendors + register local PSR-4).
 */

$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../blackcat-database/vendor/autoload.php',
    __DIR__ . '/../blackcat-crypto/vendor/autoload.php',
    __DIR__ . '/../blackcat-core/vendor/autoload.php',
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

dbcrypto_register_psr4('BlackCat\\DatabaseCrypto\\', __DIR__ . '/src');
dbcrypto_register_psr4('BlackCat\\Crypto\\', __DIR__ . '/../blackcat-crypto/src');
dbcrypto_register_psr4('BlackCat\\Database\\', __DIR__ . '/../blackcat-database/src');
dbcrypto_register_psr4('BlackCat\\Core\\', __DIR__ . '/../blackcat-core/src');

