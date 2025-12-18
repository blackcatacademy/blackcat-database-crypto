<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Diagnostics;

final class SchemaSnapshot
{
    /** @var array<string,list<string>> */
    private array $tables;

    /**
     * @param array<string,list<string>> $tables
     */
    private function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    /**
     * @param array<string,list<string>> $tables
     */
    public static function fromTables(array $tables): self
    {
        $normalized = [];
        foreach ($tables as $table => $columns) {
            if (!\is_string($table) || $table === '' || !\is_array($columns)) {
                continue;
            }
            $t = \strtolower($table);
            $normalized[$t] = \array_values(\array_map(static fn($c) => \strtolower((string)$c), $columns));
        }
        \ksort($normalized);
        return new self($normalized);
    }

    public static function fromJsonFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Schema snapshot file not found: ' . $path);
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException('Unable to read schema snapshot: ' . $path);
        }
        return self::fromJson($json);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid schema snapshot JSON');
        }
        $tables = $data['tables'] ?? null;
        if (!is_array($tables)) {
            throw new \RuntimeException('Schema snapshot must contain "tables" object');
        }
        /** @var array<string,list<string>> $tables */
        return self::fromTables($tables);
    }

    /**
     * @return array{tables: array<string,list<string>>}
     */
    public function toArray(): array
    {
        return ['tables' => $this->tables];
    }

    public function toJson(int $flags = 0): string
    {
        $json = \json_encode(
            $this->toArray(),
            $flags | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            throw new \RuntimeException('Failed to encode schema snapshot JSON');
        }
        return $json;
    }
}
