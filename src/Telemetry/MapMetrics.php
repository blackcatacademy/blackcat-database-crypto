<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Telemetry;

use BlackCat\DatabaseCrypto\Config\EncryptionMap;

final class MapMetrics
{
    /**
     * Build aggregated metrics for an encryption map: strategy/context usage,
     * missing configuration spots, and total coverage.
     *
     * @return array{
     *   tables:int,
     *   columns:int,
     *   strategy_counts:array<string,int>,
     *   context_counts:array<string,int>,
     *   encoding_counts:array<string,int>,
     *   missing_strategy:list<string>,
     *   missing_context:list<string>,
     *   encoding_issues:list<string>
     * }
     */
    public static function summarize(EncryptionMap $map): array
    {
        $tables = $map->all();

        $strategyCounts = [];
        $contextCounts = [];
        $encodingCounts = [];
        $missingStrategy = [];
        $missingContext = [];
        $encodingIssues = [];
        $columnTotal = 0;

        foreach ($tables as $table => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            foreach ($definition as $column => $info) {
                $columnTotal++;
                if (!is_array($info)) {
                    continue;
                }

                $strategy = $info['strategy'] ?? null;
                if (!is_string($strategy) || $strategy === '') {
                    $missingStrategy[] = "{$table}.{$column}";
                    continue;
                }

                $strategy = strtolower($strategy);
                $strategyCounts[$strategy] = ($strategyCounts[$strategy] ?? 0) + 1;

                $context = $info['context'] ?? null;
                $hasContext = is_string($context) && $context !== '';

                if ($strategy !== 'passthrough' && !$hasContext) {
                    $missingContext[] = "{$table}.{$column} (strategy={$strategy})";
                }

                if ($hasContext) {
                    $contextKey = strtolower((string)$context);
                    $contextCounts[$contextKey] = ($contextCounts[$contextKey] ?? 0) + 1;
                }

                if ($strategy === 'hmac') {
                    $encRaw = $info['encoding'] ?? null;
                    if ($encRaw === null || $encRaw === '') {
                        $encRaw = 'raw';
                    }

                    if (!is_string($encRaw) || trim($encRaw) === '') {
                        $encodingIssues[] = "{$table}.{$column} (invalid-encoding)";
                        $encodingCounts['unknown'] = ($encodingCounts['unknown'] ?? 0) + 1;
                    } else {
                        $enc = strtolower(trim($encRaw));
                        $enc = match ($enc) {
                            'bin', 'binary' => 'raw',
                            'b64' => 'base64',
                            default => $enc,
                        };

                        if (!in_array($enc, ['raw', 'hex', 'base64'], true)) {
                            $encodingIssues[] = "{$table}.{$column} (unknown-encoding={$enc})";
                            $enc = 'unknown';
                        }

                        $encodingCounts[$enc] = ($encodingCounts[$enc] ?? 0) + 1;
                    }
                } elseif (array_key_exists('encoding', $info)) {
                    $encodingIssues[] = "{$table}.{$column} (encoding-without-hmac-strategy={$strategy})";
                }
            }
        }

        arsort($strategyCounts);
        arsort($contextCounts);
        arsort($encodingCounts);

        return [
            'tables' => count($tables),
            'columns' => $columnTotal,
            'strategy_counts' => $strategyCounts,
            'context_counts' => $contextCounts,
            'encoding_counts' => $encodingCounts,
            'missing_strategy' => $missingStrategy,
            'missing_context' => $missingContext,
            'encoding_issues' => $encodingIssues,
        ];
    }
}
