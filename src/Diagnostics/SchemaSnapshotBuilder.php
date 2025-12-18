<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Diagnostics;

use BlackCat\Core\Database;
use BlackCat\Database\SqlDialect;
use BlackCat\Database\Support\SchemaIntrospector;

final class SchemaSnapshotBuilder
{
    /** @var callable():list<string> */
    private $listTables;
    /** @var callable(string):list<string> */
    private $listColumns;

    /**
     * @param callable():list<string>         $listTables
     * @param callable(string):list<string>   $listColumns
     */
    public function __construct(callable $listTables, callable $listColumns)
    {
        $this->listTables = $listTables;
        $this->listColumns = $listColumns;
    }

    public static function forDatabase(Database $db, SqlDialect $dialect, ?string $schema = null): self
    {
        $listTables = static fn(): array => SchemaIntrospector::listTables($db, $dialect, $schema);
        $listColumns = static fn(string $table): array => SchemaIntrospector::listColumns($db, $dialect, $table);
        return new self($listTables, $listColumns);
    }

    /**
     * @param list<string>|null $tables If null, enumerates all BASE TABLEs.
     */
    public function build(?array $tables = null): SchemaSnapshot
    {
        $tables = $tables ?? (self::callList($this->listTables))();

        $out = [];
        foreach ($tables as $table) {
            $t = \strtolower((string)$table);
            if ($t === '') {
                continue;
            }
            $cols = (self::callListColumns($this->listColumns))($table);
            $out[$t] = \array_values(\array_map(static fn($c) => \strtolower((string)$c), $cols));
        }

        return SchemaSnapshot::fromTables($out);
    }

    /**
     * @return callable():list<string>
     */
    private static function callList(callable $fn): callable
    {
        /** @var callable():list<string> $fn */
        return $fn;
    }

    /**
     * @return callable(string):list<string>
     */
    private static function callListColumns(callable $fn): callable
    {
        /** @var callable(string):list<string> $fn */
        return $fn;
    }
}

