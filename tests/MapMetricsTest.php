<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Tests;

use BlackCat\DatabaseCrypto\Config\EncryptionMap;
use BlackCat\DatabaseCrypto\Telemetry\MapMetrics;
use PHPUnit\Framework\TestCase;

final class MapMetricsTest extends TestCase
{
    public function testSummarizeCountsStrategiesAndMissingFields(): void
    {
        $map = EncryptionMap::fromArray([
            'tables' => [
                'users' => [
                    'columns' => [
                        'ssn' => ['strategy' => 'encrypt', 'context' => 'core.vault'],
                        'email_hash' => ['strategy' => 'hmac', 'context' => ''],
                        'notes' => ['strategy' => 'passthrough'],
                        'bad' => [],
                    ],
                ],
            ],
        ]);

        $metrics = MapMetrics::summarize($map);

        self::assertSame(1, $metrics['tables']);
        self::assertSame(4, $metrics['columns']);
        self::assertSame(1, $metrics['strategy_counts']['encrypt']);
        self::assertSame(1, $metrics['strategy_counts']['hmac']);
        self::assertSame(1, $metrics['strategy_counts']['passthrough']);
        self::assertSame(['users.bad'], $metrics['missing_strategy']);
        self::assertSame(['users.email_hash (strategy=hmac)'], $metrics['missing_context']);
        self::assertSame(1, $metrics['encoding_counts']['raw']);
        self::assertSame([], $metrics['encoding_issues']);
    }
}
