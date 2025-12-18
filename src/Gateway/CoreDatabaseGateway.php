<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Gateway;

use BlackCat\Core\Database;
use BlackCat\Database\Support\Observability;
use BlackCat\Database\Support\SqlIdentifier;
use BlackCat\Database\Crypto\Gateway\DatabaseGatewayInterface as DatabaseIngressGatewayInterface;

/**
 * Gateway implementation that executes SQL via {@see Database} (no raw PDO exposed).
 *
 * - Quotes identifiers using {@see SqlIdentifier} (dialect-aware via Database::quoteIdent()).
 * - Always prefixes SQL with an observability comment (compatible with Database::requireSqlComment()).
 */
final class CoreDatabaseGateway implements DatabaseIngressGatewayInterface
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    public function insert(string $table, array $payload, array $options = []): mixed
    {
        if ($payload === []) {
            throw new \InvalidArgumentException('Insert requires a non-empty payload');
        }

        $cols = array_keys($payload);
        $tblSql = SqlIdentifier::qi($this->db, $table);
        $colSql = implode(', ', array_map(fn ($c) => SqlIdentifier::q($this->db, (string)$c), $cols));

        $params = [];
        $ph = [];
        foreach ($cols as $i => $col) {
            $key = 'p' . $i;
            $ph[] = ':' . $key;
            $params[$key] = $payload[$col];
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $tblSql,
            $colSql,
            implode(', ', $ph),
        );

        return $this->db->prepareAndRun($this->withComment($sql, $options, 'insert', $table), $params);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $criteria
     * @param array<string,mixed> $options
     */
    public function update(string $table, array $payload, array $criteria, array $options = []): mixed
    {
        if ($criteria === []) {
            throw new \InvalidArgumentException('Update requires criteria to avoid mass writes');
        }
        if ($payload === []) {
            throw new \InvalidArgumentException('Update requires a non-empty payload');
        }

        $tblSql = SqlIdentifier::qi($this->db, $table);

        $sets = [];
        $params = [];
        $i = 0;
        foreach ($payload as $col => $value) {
            $p = 'set_' . $i++;
            $sets[] = sprintf('%s = :%s', SqlIdentifier::q($this->db, (string)$col), $p);
            $params[$p] = $value;
        }

        $wheres = [];
        $j = 0;
        foreach ($criteria as $col => $value) {
            $colSql = SqlIdentifier::q($this->db, (string)$col);
            if ($value === null) {
                $wheres[] = sprintf('%s IS NULL', $colSql);
                continue;
            }

            $p = 'where_' . $j++;
            $wheres[] = sprintf('%s = :%s', $colSql, $p);
            $params[$p] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $tblSql,
            implode(', ', $sets),
            implode(' AND ', $wheres),
        );

        return $this->db->prepareAndRun($this->withComment($sql, $options, 'update', $table), $params);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function withComment(string $sql, array $options, string $op, string $table): string
    {
        $meta = $options['meta'] ?? null;
        $meta = is_array($meta) ? $meta : [];
        $meta += [
            'svc' => 'db_crypto',
            'op' => $op,
            'table' => $table,
        ];
        $meta = Observability::withDefaults($meta, $this->db);
        return Observability::sqlComment($meta) . $sql;
    }
}
