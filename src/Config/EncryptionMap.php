<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Config;

final class EncryptionMap
{
    /** @var array<string,mixed> */
    private array $tables;

    /**
     * @param array<string,mixed> $tables
     */
    private function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    /**
     * @param array<string,mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $tables = $config['tables'] ?? [];
        if (!is_array($tables)) {
            throw new \InvalidArgumentException('encryption map: tables must be array');
        }

        $normalized = [];
        foreach ($tables as $table => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $columns = $definition['columns'] ?? [];
            if (!is_array($columns)) {
                continue;
            }
            $normalized[strtolower((string)$table)] = array_change_key_case($columns, CASE_LOWER);
        }

        return new self($normalized);
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('encryption map file not found: ' . $path);
        }

        $data = self::readJsonFile($path);
        $data = self::resolveIncludes($data, dirname($path), [realpath($path) ?: $path]);
        return self::fromArray($data);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function columnsFor(string $table): ?array
    {
        return $this->tables[strtolower($table)] ?? null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        return $this->tables;
    }

    /**
     * @return array<string,mixed>
     */
    private static function readJsonFile(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('unable to read encryption map: ' . $path);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('invalid encryption map JSON: ' . $path);
        }

        /** @var array<string,mixed> $data */
        return $data;
    }

    /**
     * Supports modular map composition via:
     *
     * {
     *   "includes": ["./maps/core.json", "./maps/auth.json"],
     *   "tables": { ... }
     * }
     *
     * Includes are loaded first (in order), then the current file overrides them.
     *
     * @param array<string,mixed> $config
     * @param list<string> $stack
     * @return array<string,mixed>
     */
    private static function resolveIncludes(array $config, string $baseDir, array $stack): array
    {
        $includes = $config['includes'] ?? null;
        if ($includes === null || $includes === '') {
            $includes = [];
        }
        if (!is_array($includes)) {
            throw new \InvalidArgumentException('encryption map: includes must be an array of paths');
        }

        $mergedTables = [];

        foreach ($includes as $inc) {
            if (!is_string($inc) || trim($inc) === '') {
                continue;
            }
            $resolved = self::resolveIncludePath($inc, $baseDir);
            $real = realpath($resolved) ?: $resolved;
            if (in_array($real, $stack, true)) {
                throw new \RuntimeException('encryption map: include cycle detected: ' . implode(' -> ', array_merge($stack, [$real])));
            }

            $incConfig = self::readJsonFile($resolved);
            $incConfig = self::resolveIncludes($incConfig, dirname($resolved), array_merge($stack, [$real]));
            $mergedTables = self::mergeTables($mergedTables, self::extractTables($incConfig));
        }

        $mergedTables = self::mergeTables($mergedTables, self::extractTables($config));

        return [
            'tables' => self::tablesToConfig($mergedTables),
        ];
    }

    private static function resolveIncludePath(string $path, string $baseDir): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new \InvalidArgumentException('encryption map: include path must not be empty');
        }
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            return $path;
        }
        return rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $path;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<string,array<string,array<string,mixed>>> table => column => spec
     */
    private static function extractTables(array $config): array
    {
        $tables = $config['tables'] ?? [];
        if (!is_array($tables)) {
            throw new \InvalidArgumentException('encryption map: tables must be array');
        }

        $out = [];
        foreach ($tables as $tableName => $def) {
            if (!is_array($def)) {
                continue;
            }
            $cols = $def['columns'] ?? [];
            if (!is_array($cols)) {
                continue;
            }

            $tableKey = strtolower((string)$tableName);
            if ($tableKey === '') {
                continue;
            }

            if (!isset($out[$tableKey])) {
                $out[$tableKey] = [];
            }

            foreach ($cols as $colName => $spec) {
                $colKey = strtolower((string)$colName);
                if ($colKey === '' || !is_array($spec)) {
                    continue;
                }

                /** @var array<string,mixed> $spec */
                $out[$tableKey][$colKey] = $spec;
            }
        }

        return $out;
    }

    /**
     * Merge table/column maps. Later specs override earlier ones (shallow merge per column).
     *
     * @param array<string,array<string,array<string,mixed>>> $base
     * @param array<string,array<string,array<string,mixed>>> $overlay
     * @return array<string,array<string,array<string,mixed>>>
     */
    private static function mergeTables(array $base, array $overlay): array
    {
        foreach ($overlay as $table => $cols) {
            if (!isset($base[$table])) {
                $base[$table] = [];
            }
            foreach ($cols as $col => $spec) {
                if (isset($base[$table][$col])) {
                    /** @var array<string,mixed> $prev */
                    $prev = $base[$table][$col];
                    $base[$table][$col] = array_replace($prev, $spec);
                    continue;
                }
                $base[$table][$col] = $spec;
            }
        }
        return $base;
    }

    /**
     * @param array<string,array<string,array<string,mixed>>> $tables
     * @return array<string,array{columns:array<string,array<string,mixed>>}>
     */
    private static function tablesToConfig(array $tables): array
    {
        $out = [];
        foreach ($tables as $table => $cols) {
            $out[$table] = ['columns' => $cols];
        }
        return $out;
    }
}
