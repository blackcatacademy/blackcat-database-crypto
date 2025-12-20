<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Config;

/**
 * Loads per-package encryption maps from the blackcat-database packages directory.
 *
 * Contract:
 * - Each package must provide `schema/encryption-map.json`.
 * - Each file must define exactly 1 table.
 * - The table name must match the package Definitions::table().
 * - Every column from Definitions::columns() must be explicitly mapped (including passthrough).
 * - No unknown/extra columns are allowed (fail fast).
 */
final class PackagesEncryptionMapLoader
{
    public static function fromAutodetectedBlackcatDatabaseRoot(): EncryptionMap
    {
        return self::fromBlackcatDatabaseRoot(self::detectBlackcatDatabaseRootDir());
    }

    public static function fromBlackcatDatabaseRoot(string $blackcatDatabaseRootDir): EncryptionMap
    {
        $packagesDir = rtrim($blackcatDatabaseRootDir, '/\\') . '/packages';
        return self::fromPackagesDir($packagesDir);
    }

    public static function fromPackagesDir(string $packagesDir): EncryptionMap
    {
        if (!is_dir($packagesDir)) {
            throw new \RuntimeException('Packages directory not found: ' . $packagesDir);
        }

        $merged = [];

        $definitionPaths = glob(rtrim($packagesDir, '/\\') . '/*/src/Definitions.php') ?: [];
        foreach ($definitionPaths as $definitionPath) {
            if (!is_file($definitionPath)) {
                continue;
            }

            $packageDir = dirname($definitionPath, 2);
            $mapPath = $packageDir . '/schema/encryption-map.json';

            $fqn = self::parseClassFqnFromFile($definitionPath);
            if ($fqn === null) {
                throw new \RuntimeException('Unable to detect package Definitions FQN: ' . $definitionPath);
            }

            if (!class_exists($fqn)) {
                require_once $definitionPath;
            }
            if (!class_exists($fqn) || !is_callable([$fqn, 'table']) || !is_callable([$fqn, 'columns'])) {
                throw new \RuntimeException('Invalid package Definitions class: ' . $fqn . ' (' . $definitionPath . ')');
            }

            /** @var mixed $table */
            $table = $fqn::table();
            /** @var mixed $columns */
            $columns = $fqn::columns();

            if (!is_string($table) || $table === '' || !is_array($columns)) {
                throw new \RuntimeException('Invalid package Definitions::table()/columns(): ' . $fqn);
            }

            $tableKey = strtolower($table);
            $expectedCols = array_values(array_map(static fn($c) => strtolower((string)$c), $columns));

            if (!is_file($mapPath)) {
                throw new \RuntimeException(sprintf('Missing encryption map for package table "%s": %s', $tableKey, $mapPath));
            }

            $config = self::readJsonFile($mapPath);
            [$mapTableKey, $mapCols] = self::extractSingleTableColumns($config, $mapPath);

            if ($mapTableKey !== $tableKey) {
                throw new \RuntimeException(
                    sprintf(
                        'Encryption map table mismatch in %s (expected "%s", found "%s")',
                        $mapPath,
                        $tableKey,
                        $mapTableKey
                    )
                );
            }

            self::validateColumnCoverage($tableKey, $expectedCols, $mapCols, $mapPath);
            self::validateColumnSpecs($tableKey, $mapCols, $expectedCols, $mapPath);
            self::validateUniqueKeysDoNotUseEncrypt($fqn, $tableKey, $mapCols, $mapPath);

            if (isset($merged[$tableKey])) {
                throw new \RuntimeException('Duplicate table in discovered encryption maps: ' . $tableKey);
            }

            $merged[$tableKey] = $mapCols;
        }

        return EncryptionMap::fromArray([
            'tables' => array_map(static fn(array $cols): array => ['columns' => $cols], $merged),
        ]);
    }

    private static function detectBlackcatDatabaseRootDir(): string
    {
        $probe = '\\BlackCat\\Database\\Registry';
        if (!class_exists($probe)) {
            throw new \RuntimeException('blackcat-database is not autoloadable (missing ' . $probe . ')');
        }

        $file = (new \ReflectionClass($probe))->getFileName();
        if ($file === false) {
            throw new \RuntimeException('Cannot locate blackcat-database root directory');
        }

        // <root>/src/Registry.php -> <root>
        return dirname($file, 2);
    }

    /**
     * @return array<string,mixed>
     */
    private static function readJsonFile(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Unable to read encryption map: ' . $path);
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid encryption map JSON: ' . $path);
        }

        /** @var array<string,mixed> $data */
        return $data;
    }

    /**
     * @param array<string,mixed> $config
     * @return array{0:string,1:array<string,array<string,mixed>>} [table, columns]
     */
    private static function extractSingleTableColumns(array $config, string $sourcePath): array
    {
        $tables = $config['tables'] ?? null;
        if (!is_array($tables) || $tables === []) {
            throw new \RuntimeException('Encryption map must contain non-empty "tables": ' . $sourcePath);
        }

        $tableNames = array_keys($tables);
        if (count($tableNames) !== 1) {
            throw new \RuntimeException('Per-package encryption map must define exactly 1 table: ' . $sourcePath);
        }

        $tableName = (string)$tableNames[0];
        $tableKey = strtolower($tableName);
        if ($tableKey === '') {
            throw new \RuntimeException('Invalid table name in encryption map: ' . $sourcePath);
        }

        $tableDef = $tables[$tableName] ?? null;
        if (!is_array($tableDef)) {
            throw new \RuntimeException('Invalid table definition in encryption map: ' . $sourcePath);
        }

        $columns = $tableDef['columns'] ?? null;
        if (!is_array($columns)) {
            throw new \RuntimeException('Encryption map table must contain "columns" object: ' . $sourcePath);
        }

        $out = [];
        foreach ($columns as $colName => $spec) {
            $colKey = strtolower((string)$colName);
            if ($colKey === '' || !is_array($spec)) {
                throw new \RuntimeException('Invalid column definition for ' . $tableKey . '.' . (string)$colName . ' in ' . $sourcePath);
            }

            /** @var array<string,mixed> $spec */
            $out[$colKey] = array_change_key_case($spec, CASE_LOWER);
        }

        ksort($out);
        return [$tableKey, $out];
    }

    /**
     * @param list<string> $expectedCols
     * @param array<string,array<string,mixed>> $mapCols
     */
    private static function validateColumnCoverage(string $table, array $expectedCols, array $mapCols, string $sourcePath): void
    {
        $expectedSet = array_fill_keys($expectedCols, true);
        $mapSet = array_fill_keys(array_keys($mapCols), true);

        $missing = array_values(array_diff(array_keys($expectedSet), array_keys($mapSet)));
        $extra = array_values(array_diff(array_keys($mapSet), array_keys($expectedSet)));

        sort($missing);
        sort($extra);

        if ($missing !== []) {
            throw new \RuntimeException(
                sprintf(
                    'Encryption map missing columns for %s in %s: %s',
                    $table,
                    $sourcePath,
                    implode(', ', $missing)
                )
            );
        }
        if ($extra !== []) {
            throw new \RuntimeException(
                sprintf(
                    'Encryption map contains unknown columns for %s in %s: %s',
                    $table,
                    $sourcePath,
                    implode(', ', $extra)
                )
            );
        }
    }

    /**
     * @param array<string,array<string,mixed>> $mapCols
     * @param list<string> $allColumns
     */
    private static function validateColumnSpecs(string $table, array $mapCols, array $allColumns, string $sourcePath): void
    {
        $allowedStrategies = ['encrypt', 'hmac', 'passthrough'];
        $allowedEncodings = ['raw', 'hex', 'base64'];
        $columnSet = array_fill_keys($allColumns, true);

        foreach ($mapCols as $column => $spec) {
            $strategyRaw = $spec['strategy'] ?? null;
            if (!is_string($strategyRaw) || $strategyRaw === '') {
                throw new \RuntimeException(sprintf('Missing "strategy" for %s.%s in %s', $table, $column, $sourcePath));
            }

            $strategy = strtolower($strategyRaw);
            if (!in_array($strategy, $allowedStrategies, true)) {
                throw new \RuntimeException(sprintf('Unknown strategy "%s" for %s.%s in %s', $strategy, $table, $column, $sourcePath));
            }

            $contextRaw = $spec['context'] ?? null;
            $context = is_string($contextRaw) ? trim($contextRaw) : '';

            if (in_array($strategy, ['encrypt', 'hmac'], true) && $context === '') {
                throw new \RuntimeException(sprintf('Missing "context" for %s.%s (strategy=%s) in %s', $table, $column, $strategy, $sourcePath));
            }

            $encodingRaw = $spec['encoding'] ?? null;
            if ($encodingRaw !== null) {
                if ($strategy !== 'hmac') {
                    throw new \RuntimeException(sprintf('Field "encoding" is only allowed for strategy=hmac (%s.%s in %s)', $table, $column, $sourcePath));
                }
                if (!is_string($encodingRaw) || trim($encodingRaw) === '') {
                    throw new \RuntimeException(sprintf('Invalid "encoding" for %s.%s in %s', $table, $column, $sourcePath));
                }
                $encoding = strtolower(trim($encodingRaw));
                $encoding = match ($encoding) {
                    'bin', 'binary' => 'raw',
                    'b64' => 'base64',
                    default => $encoding,
                };
                if (!in_array($encoding, $allowedEncodings, true)) {
                    throw new \RuntimeException(sprintf('Unknown "encoding" value "%s" for %s.%s in %s', $encoding, $table, $column, $sourcePath));
                }
            }

            $wrapCountRaw = $spec['wrap_count'] ?? null;
            if ($wrapCountRaw !== null) {
                if ($strategy !== 'encrypt') {
                    throw new \RuntimeException(sprintf('Field "wrap_count" is only allowed for strategy=encrypt (%s.%s in %s)', $table, $column, $sourcePath));
                }
                if (!is_int($wrapCountRaw) && !(is_string($wrapCountRaw) && ctype_digit($wrapCountRaw))) {
                    throw new \RuntimeException(sprintf('Invalid "wrap_count" for %s.%s in %s', $table, $column, $sourcePath));
                }
            }

            $writeKeyVersion = $spec['write_key_version'] ?? null;
            if ($writeKeyVersion !== null && !is_bool($writeKeyVersion)) {
                throw new \RuntimeException(sprintf('Invalid "write_key_version" for %s.%s in %s', $table, $column, $sourcePath));
            }
            if ($writeKeyVersion === true) {
                $kvc = $spec['key_version_column'] ?? ($column . '_key_version');
                if (!is_string($kvc) || trim($kvc) === '') {
                    throw new \RuntimeException(sprintf('Invalid "key_version_column" for %s.%s in %s', $table, $column, $sourcePath));
                }
                $kvcKey = strtolower(trim($kvc));
                if (!isset($columnSet[$kvcKey])) {
                    throw new \RuntimeException(
                        sprintf('Missing key version column "%s" in schema for %s.%s (map: %s)', $kvcKey, $table, $column, $sourcePath)
                    );
                }
                $kvcSpec = $mapCols[$kvcKey] ?? null;
                $kvcStrategy = is_array($kvcSpec) ? strtolower((string)($kvcSpec['strategy'] ?? '')) : '';
                if ($kvcStrategy !== 'passthrough') {
                    throw new \RuntimeException(
                        sprintf(
                            'Key version column "%s" must use strategy=passthrough for %s.%s (map: %s)',
                            $kvcKey,
                            $table,
                            $column,
                            $sourcePath
                        )
                    );
                }
            }

            $writeMeta = $spec['write_encryption_meta'] ?? null;
            if ($writeMeta !== null && !is_bool($writeMeta)) {
                throw new \RuntimeException(sprintf('Invalid "write_encryption_meta" for %s.%s in %s', $table, $column, $sourcePath));
            }
            if ($writeMeta === true) {
                $metaCol = $spec['encryption_meta_column'] ?? 'encryption_meta';
                if (!is_string($metaCol) || trim($metaCol) === '') {
                    throw new \RuntimeException(sprintf('Invalid "encryption_meta_column" for %s.%s in %s', $table, $column, $sourcePath));
                }
                $metaColKey = strtolower(trim($metaCol));
                if (!isset($columnSet[$metaColKey])) {
                    throw new \RuntimeException(
                        sprintf('Missing encryption meta column "%s" in schema for %s.%s (map: %s)', $metaColKey, $table, $column, $sourcePath)
                    );
                }
                $metaSpec = $mapCols[$metaColKey] ?? null;
                $metaStrategy = is_array($metaSpec) ? strtolower((string)($metaSpec['strategy'] ?? '')) : '';
                if ($metaStrategy !== 'passthrough') {
                    throw new \RuntimeException(
                        sprintf(
                            'Encryption meta column "%s" must use strategy=passthrough for %s.%s (map: %s)',
                            $metaColKey,
                            $table,
                            $column,
                            $sourcePath
                        )
                    );
                }
            }
        }
    }

    /**
     * Extra safety: DB UNIQUE constraints must not include strategy=encrypt columns (non-deterministic).
     *
     * @param class-string $definitionsFqn
     * @param array<string,array<string,mixed>> $mapCols
     */
    private static function validateUniqueKeysDoNotUseEncrypt(string $definitionsFqn, string $table, array $mapCols, string $sourcePath): void
    {
        if (!is_callable([$definitionsFqn, 'uniqueKeys'])) {
            return;
        }

        /** @var mixed $unique */
        $unique = $definitionsFqn::uniqueKeys();
        if (!is_array($unique)) {
            throw new \RuntimeException('Invalid Definitions::uniqueKeys() for table ' . $table . ' (' . $sourcePath . ')');
        }

        foreach ($unique as $keyCols) {
            if (!is_array($keyCols) || $keyCols === []) {
                continue;
            }

            $cols = [];
            foreach ($keyCols as $c) {
                $colKey = strtolower(trim((string)$c));
                if ($colKey !== '') {
                    $cols[] = $colKey;
                }
            }
            if ($cols === []) {
                continue;
            }

            foreach ($cols as $colKey) {
                $spec = $mapCols[$colKey] ?? null;
                if (!is_array($spec)) {
                    continue;
                }
                $strategy = strtolower((string)($spec['strategy'] ?? ''));
                if ($strategy === 'encrypt') {
                    throw new \RuntimeException(
                        sprintf(
                            'Unique key (%s) for table "%s" includes "%s" with strategy=encrypt (non-deterministic). Use hmac/passthrough instead. Map: %s',
                            implode(',', $cols),
                            $table,
                            $colKey,
                            $sourcePath
                        )
                    );
                }
            }
        }
    }

    private static function parseClassFqnFromFile(string $path): ?string
    {
        $src = file_get_contents($path);
        if ($src === false || $src === '') {
            return null;
        }

        $tokens = token_get_all($src);
        $namespace = '';
        $class = '';

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                continue;
            }

            if ($tok[0] === T_NAMESPACE) {
                $nsParts = [];
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if (is_array($t) && ($t[0] === T_STRING || $t[0] === T_NAME_QUALIFIED || $t[0] === T_NS_SEPARATOR)) {
                        $nsParts[] = $t[1];
                        continue;
                    }
                    if ($t === ';') {
                        break;
                    }
                }
                $namespace = trim(implode('', $nsParts), '\\');
            }

            if ($tok[0] === T_CLASS) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $t = $tokens[$j];
                    if (is_array($t) && $t[0] === T_STRING) {
                        $class = $t[1];
                        break;
                    }
                }
            }
        }

        if ($class === '') {
            return null;
        }
        if ($namespace === '') {
            return $class;
        }
        return $namespace . '\\' . $class;
    }
}
