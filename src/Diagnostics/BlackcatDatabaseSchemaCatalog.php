<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Diagnostics;

/**
 * Builds a stable schema view from blackcat-database generated package Definitions.
 *
 * This intentionally avoids querying information_schema by default so we keep
 * a single source of truth (the schema map -> generated packages).
 */
final class BlackcatDatabaseSchemaCatalog
{
    /** @var array<string,list<string>> table => columns */
    private array $tables;

    /**
     * @param array<string,list<string>> $tables
     */
    private function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public static function fromAutodetectedRoot(): self
    {
        $root = self::detectBlackcatDatabaseRootDir();
        return self::fromRoot($root);
    }

    public static function fromRoot(string $blackcatDatabaseRootDir): self
    {
        $packagesDir = rtrim($blackcatDatabaseRootDir, '/\\') . '/packages';
        if (!is_dir($packagesDir)) {
            throw new \RuntimeException('blackcat-database packages dir not found: ' . $packagesDir);
        }

        $tables = [];
        $paths = glob($packagesDir . '/*/src/Definitions.php') ?: [];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $fqn = self::parseClassFqnFromFile($path);
            if ($fqn === null) {
                continue;
            }

            if (!class_exists($fqn)) {
                require_once $path;
            }
            if (!class_exists($fqn)) {
                continue;
            }

            if (!is_callable([$fqn, 'table']) || !is_callable([$fqn, 'columns'])) {
                continue;
            }

            try {
                /** @var mixed $table */
                $table = $fqn::table();
                /** @var mixed $cols */
                $cols = $fqn::columns();
            } catch (\Throwable) {
                continue;
            }

            if (!is_string($table) || $table === '' || !is_array($cols)) {
                continue;
            }

            $t = strtolower($table);
            $tables[$t] = array_values(array_map(static fn($c) => strtolower((string)$c), $cols));
        }

        ksort($tables);
        return new self($tables);
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_keys($this->tables);
    }

    /** @return list<string> */
    public function columnsFor(string $table): array
    {
        return $this->tables[strtolower($table)] ?? [];
    }

    /**
     * @param list<string>|null $tables If provided, includes exactly these tables (missing ones become empty lists).
     */
    public function snapshot(?array $tables = null): SchemaSnapshot
    {
        if ($tables === null) {
            return SchemaSnapshot::fromTables($this->tables);
        }

        $out = [];
        foreach ($tables as $t) {
            $out[strtolower((string)$t)] = $this->columnsFor((string)$t);
        }
        return SchemaSnapshot::fromTables($out);
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
