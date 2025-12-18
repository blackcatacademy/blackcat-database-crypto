<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Diagnostics;

use BlackCat\DatabaseCrypto\Config\EncryptionMap;

final class EncryptionPlanValidator
{
    /**
     * @param list<string> $manifestContexts Slot/context names from manifest (array_keys(slots)).
     * @return array{
     *   hasWarnings: bool,
     *   tables: array<string,array{
     *     status: list<string>,
     *     columns: array<string,array{strategy:string, context:?string, status:list<string>}>
     *   }>
     * }
     */
    public static function validate(
        EncryptionMap $map,
        array $manifestContexts = [],
        ?SchemaSnapshot $schema = null
    ): array {
        $schemaTables = $schema?->toArray()['tables'] ?? null;

        $allowedStrategies = ['encrypt', 'hmac', 'passthrough'];
        $hasWarnings = false;

        $out = [];
        foreach ($map->all() as $table => $columns) {
            $table = strtolower((string)$table);
            if (!is_array($columns)) {
                continue;
            }

            $tableStatus = ['ok'];
            $tableInSchema = $schemaTables !== null && array_key_exists($table, $schemaTables);
            $schemaColsForTable = $tableInSchema ? ($schemaTables[$table] ?? null) : null;
            $tableSchemaOk = $schemaTables === null
                ? true
                : ($tableInSchema && is_array($schemaColsForTable) && $schemaColsForTable !== []);

            if ($schemaTables !== null && !$tableSchemaOk) {
                $tableStatus = ['missing-table-in-schema'];
            }

            $colOut = [];
            foreach ($columns as $column => $spec) {
                $column = strtolower((string)$column);
                $spec = is_array($spec) ? $spec : [];

                $strategyRaw = $spec['strategy'] ?? null;
                $strategy = is_string($strategyRaw) && $strategyRaw !== '' ? strtolower($strategyRaw) : 'encrypt';
                $contextRaw = $spec['context'] ?? null;
                $context = is_string($contextRaw) && $contextRaw !== '' ? $contextRaw : null;

                $status = ['ok'];

                if (!is_string($strategyRaw) || $strategyRaw === '') {
                    $status = ['missing-strategy'];
                } elseif (!in_array($strategy, $allowedStrategies, true)) {
                    $status = ['unknown-strategy'];
                }

                // Context rules apply only to known non-passthrough strategies.
                if (in_array($strategy, ['encrypt', 'hmac'], true)) {
                    if ($context === null) {
                        $status[] = 'missing-context';
                    } elseif ($manifestContexts !== [] && !in_array($context, $manifestContexts, true)) {
                        $status[] = 'unknown-context';
                    }
                }

                // Optional HMAC encoding (raw|hex|base64).
                $encRaw = $spec['encoding'] ?? null;
                if ($encRaw !== null) {
                    if (!is_string($encRaw) || trim($encRaw) === '') {
                        $status[] = 'invalid-encoding';
                    } elseif ($strategy !== 'hmac') {
                        $status[] = 'encoding-without-hmac-strategy';
                    } else {
                        $enc = strtolower(trim($encRaw));
                        $enc = match ($enc) {
                            'bin', 'binary' => 'raw',
                            'b64' => 'base64',
                            default => $enc,
                        };
                        if (!in_array($enc, ['raw', 'hex', 'base64'], true)) {
                            $status[] = 'unknown-encoding';
                        }
                    }
                }

                if ($schemaTables !== null) {
                    if (!$tableSchemaOk) {
                        $status[] = 'missing-table-in-schema';
                    } else {
                        $schemaCols = $schemaColsForTable;
                        if (!is_array($schemaCols) || !in_array($column, $schemaCols, true)) {
                            $status[] = 'missing-column-in-schema';
                        }
                    }
                }

                // Optional write-path metadata columns.
                $writeKeyVersion = (bool)($spec['write_key_version'] ?? false);
                if ($writeKeyVersion) {
                    if (!in_array($strategy, ['encrypt', 'hmac'], true)) {
                        $status[] = 'key-version-without-crypto-strategy';
                    }

                    $kvcRaw = $spec['key_version_column'] ?? ($column . '_key_version');
                    $kvc = is_string($kvcRaw) && $kvcRaw !== '' ? strtolower($kvcRaw) : null;
                    if ($kvc === null) {
                        $status[] = 'invalid-key-version-column';
                    } elseif ($schemaTables !== null && $tableSchemaOk) {
                        $schemaCols = $schemaColsForTable;
                        if (!is_array($schemaCols) || !in_array($kvc, $schemaCols, true)) {
                            $status[] = 'missing-key-version-column-in-schema';
                        }
                    }
                }

                $writeEncMeta = (bool)($spec['write_encryption_meta'] ?? false);
                if ($writeEncMeta) {
                    if (!in_array($strategy, ['encrypt', 'hmac'], true)) {
                        $status[] = 'encryption-meta-without-crypto-strategy';
                    }

                    $emcRaw = $spec['encryption_meta_column'] ?? 'encryption_meta';
                    $emc = is_string($emcRaw) && $emcRaw !== '' ? strtolower($emcRaw) : null;
                    if ($emc === null) {
                        $status[] = 'invalid-encryption-meta-column';
                    } elseif ($schemaTables !== null && $tableSchemaOk) {
                        $schemaCols = $schemaColsForTable;
                        if (!is_array($schemaCols) || !in_array($emc, $schemaCols, true)) {
                            $status[] = 'missing-encryption-meta-column-in-schema';
                        }
                    }
                }

                $status = self::normalizeStatus($status);
                if ($status !== ['ok']) {
                    $hasWarnings = true;
                }

                $colOut[$column] = [
                    'strategy' => $strategy,
                    'context' => $context,
                    'status' => $status,
                ];
            }

            if ($tableStatus !== ['ok']) {
                $hasWarnings = true;
            }

            $out[$table] = [
                'status' => $tableStatus,
                'columns' => $colOut,
            ];
        }

        ksort($out);

        return [
            'hasWarnings' => $hasWarnings,
            'tables' => $out,
        ];
    }

    /**
     * @param list<string> $status
     * @return list<string>
     */
    private static function normalizeStatus(array $status): array
    {
        $status = array_values(array_unique(array_filter($status, static fn($s) => is_string($s) && $s !== '')));
        if ($status === []) {
            return ['ok'];
        }
        if ($status !== ['ok']) {
            $status = array_values(array_filter($status, static fn($s) => $s !== 'ok'));
        }
        sort($status);
        return $status ?: ['ok'];
    }
}
