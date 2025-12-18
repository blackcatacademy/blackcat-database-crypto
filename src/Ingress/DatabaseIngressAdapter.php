<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Ingress;

use BlackCat\Database\Contracts\DatabaseIngressCriteriaAdapterInterface;
use BlackCat\DatabaseCrypto\Adapter\DatabaseCryptoAdapter;
use BlackCat\DatabaseCrypto\Config\EncryptionMap;
use InvalidArgumentException;

/**
 * High-level ingress adapter that glues application repositories to {@see DatabaseCryptoAdapter}.
 *
 * Responsibilities:
 * - Validate that a table is described in the manifest-derived {@see EncryptionMap}.
 * - Delegate inserts/updates to the wrapped {@see DatabaseCryptoAdapter}.
 * - Emit optional coverage callbacks (hooked up to VaultCoverageTracker / telemetry).
 * - Provide helper accessors for encrypting payloads before custom gateways consume them.
 */
final class DatabaseIngressAdapter implements DatabaseIngressCriteriaAdapterInterface
{
    /**
     * @param DatabaseCryptoAdapter $adapter Underlying adapter that knows how to encrypt + call the gateway.
     * @param EncryptionMap $map Manifest-derived map describing tables/columns/strategies.
     * @param bool $requireMappedTable When true, every ingest call must reference a table present in the map.
     * @param callable|null $coverageReporter Optional callback fn(string $table, string $operation, array $columns): void.
     */
    public function __construct(
        private readonly DatabaseCryptoAdapter $adapter,
        private readonly EncryptionMap $map,
        private readonly bool $requireMappedTable = true,
        private readonly mixed $coverageReporter = null,
    ) {
    }

    /**
     * Encrypts + inserts the payload according to the manifest map and forwards it to the gateway.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    public function insert(string $table, array $payload, array $options = []): mixed
    {
        $this->assertTable($table, $payload);
        $result = $this->adapter->insert($table, $payload, $options);
        $this->emitCoverage($table, 'insert', array_keys($payload));
        return $result;
    }

    /**
     * Encrypts + updates rows matching the provided criteria.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $criteria
     * @param array<string,mixed> $options
     */
    public function update(string $table, array $payload, array $criteria, array $options = []): mixed
    {
        $this->assertTable($table, $payload);
        $result = $this->adapter->update($table, $payload, $criteria, $options);
        $this->emitCoverage($table, 'update', array_keys($payload));
        return $result;
    }

    /**
     * Encrypts the payload for custom workflows (bulk loaders, external gateways, etc.).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function encrypt(string $table, array $payload): array
    {
        $this->assertTable($table, $payload);
        $encrypted = $this->adapter->encryptPayload($table, $payload);
        $this->emitCoverage($table, 'encrypt', array_keys($payload));
        return $encrypted;
    }

    /**
     * Deterministic transform for query criteria (HMAC-only).
     *
     * @param array<string,mixed> $criteria
     * @return array<string,mixed>
     */
    public function criteria(string $table, array $criteria): array
    {
        $this->assertTable($table, $criteria);

        $definition = $this->map->columnsFor($table);
        if ($definition === null || $criteria === []) {
            return $criteria;
        }

        $hmacOnly = [];
        $passthrough = [];

        foreach ($criteria as $column => $value) {
            $spec = $definition[strtolower((string)$column)] ?? null;
            if (!is_array($spec)) {
                $passthrough[$column] = $value;
                continue;
            }

            $mode = strtolower((string)($spec['strategy'] ?? 'encrypt'));
            if ($mode === 'hmac') {
                $hmacOnly[$column] = $value;
                continue;
            }
            if ($mode === 'passthrough') {
                $passthrough[$column] = $value;
                continue;
            }
            if ($mode === 'encrypt') {
                throw new InvalidArgumentException(
                    sprintf(
                        'DatabaseIngressAdapter: criteria cannot include "%s" on table "%s" (strategy=encrypt is non-deterministic).',
                        (string)$column,
                        $table
                    )
                );
            }

            throw new InvalidArgumentException('DatabaseIngressAdapter: unknown strategy ' . $mode);
        }

        if ($hmacOnly === []) {
            return $passthrough;
        }

        $hashed = $this->adapter->encryptPayload($table, $hmacOnly, ['purpose' => 'criteria', 'operation' => 'criteria']);
        $this->emitCoverage($table, 'criteria', array_keys($hmacOnly));
        return array_replace($passthrough, $hashed);
    }

    /**
     * Decrypts encrypted columns according to the manifest map.
     *
     * @param array<string,mixed> $payload
     * @param array{strict?:bool} $options
     * @return array<string,mixed>
     */
    public function decrypt(string $table, array $payload, array $options = []): array
    {
        $this->assertTable($table, $payload);
        $decrypted = $this->adapter->decryptPayload($table, $payload, $options);
        $this->emitCoverage($table, 'decrypt', array_keys($payload));
        return $decrypted;
    }

    /**
     * Ensures the table exists in the map (if strict) and warns when payload columns are unmapped.
     *
     * @param array<string,mixed> $payload
     */
    private function assertTable(string $table, array $payload): void
    {
        $definition = $this->map->columnsFor($table);
        if ($definition === null) {
            if ($this->requireMappedTable) {
                throw new InvalidArgumentException("DatabaseIngressAdapter: table '{$table}' not present in encryption map.");
            }
            return;
        }

        $knownColumns = array_map('strtolower', array_keys($definition));
        foreach (array_keys($payload) as $column) {
            if (!in_array(strtolower((string)$column), $knownColumns, true)) {
                // Instead of throwing, surface a gentle reminder so repositories can extend the map.
                trigger_error(
                    sprintf(
                        'DatabaseIngressAdapter: column "%s" (table "%s") missing in encryption map – payload will pass through untouched.',
                        (string)$column,
                        $table
                    ),
                    E_USER_NOTICE
                );
            }
        }
    }

    /**
     * @param array<int,string> $columns
     */
    private function emitCoverage(string $table, string $operation, array $columns): void
    {
        if ($this->coverageReporter === null) {
            return;
        }

        try {
            ($this->coverageReporter)(
                $table,
                $operation,
                $columns
            );
        } catch (\Throwable) {
            // Coverage hooks are best-effort; never let them fail data-path operations.
        }
    }
}
