<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\DatabaseCrypto\Config\PackagesEncryptionMapLoader;
use BlackCat\DatabaseCrypto\Telemetry\MapMetrics;
use PHPUnit\Framework\TestCase;

final class AllPackagesEncryptionMapsIntegrationTest extends TestCase
{
    public function testAllBlackcatDatabasePackagesHaveValidEncryptionMaps(): void
    {
        $root = $this->resolveBlackcatDatabaseRoot();
        self::assertNotNull(
            $root,
            'blackcat-database root not available (expected checkout with submodules).'
        );

        $definitions = glob($root . '/packages/*/src/Definitions.php') ?: [];
        self::assertNotSame(
            [],
            $definitions,
            'No packages/*/src/Definitions.php found (submodules likely not initialized).'
        );

        $map = PackagesEncryptionMapLoader::fromBlackcatDatabaseRoot($root);

        self::assertNotEmpty($map->all(), 'Merged packages encryption map must not be empty.');
        self::assertCount(count($definitions), $map->all(), 'Table count must match number of package Definitions.');

        $metrics = MapMetrics::summarize($map);
        self::assertSame([], $metrics['missing_strategy'], 'All columns must have an explicit strategy.');
        self::assertSame([], $metrics['missing_context'], 'All encrypt/hmac columns must have an explicit context.');
        self::assertSame([], $metrics['encoding_issues'], 'No invalid/unknown encoding issues expected.');
        self::assertGreaterThan(0, (int)($metrics['columns'] ?? 0));
    }

    private function resolveBlackcatDatabaseRoot(): ?string
    {
        $env = getenv('BLACKCAT_DB_ROOT');
        if (is_string($env) && trim($env) !== '') {
            $real = realpath($env);
            if ($real !== false && is_dir($real . '/packages')) {
                return $real;
            }
        }

        $relative = realpath(__DIR__ . '/../../blackcat-database');
        if ($relative !== false && is_dir($relative . '/packages')) {
            return $relative;
        }

        $repoLocal = realpath(__DIR__ . '/../blackcat-database');
        if ($repoLocal !== false && is_dir($repoLocal . '/packages')) {
            return $repoLocal;
        }

        return null;
    }
}
