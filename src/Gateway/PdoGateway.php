<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Gateway;

use PDO;

/**
 * Legacy reference gateway for non-ecosystem usage.
 *
 * Prefer `blackcat-database` repositories + `DatabaseIngressAdapter` (zero-boilerplate write-path),
 * or use `CoreDatabaseGateway` over `BlackCat\Core\Database` when you really need a gateway.
 *
 * @deprecated Avoid raw PDO in platform integrations; kept for legacy/manual wiring.
 */
final class PdoGateway implements DatabaseGatewayInterface
{
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private static function assertIdentifier(string $name, string $label): string
    {
        $name = trim($name);
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Invalid SQL identifier for ' . $label . ': ' . $name);
        }
        return $name;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    public function insert(string $table, array $payload, array $options = []): mixed
    {
        $table = self::assertIdentifier($table, 'table');

        $columns = [];
        $placeholders = [];
        $params = [];
        $i = 0;
        foreach ($payload as $col => $value) {
            $col = self::assertIdentifier((string)$col, 'column');
            $columns[] = $col;
            $ph = 'p' . $i++;
            $placeholders[] = ':' . $ph;
            $params[$ph] = $value;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $criteria
     * @param array<string,mixed> $options
     */
    public function update(string $table, array $payload, array $criteria, array $options = []): mixed
    {
        $table = self::assertIdentifier($table, 'table');
        if ($criteria === []) {
            throw new \InvalidArgumentException('Update requires criteria to avoid mass writes');
        }

        $sets = [];
        $params = [];
        $i = 0;
        foreach ($payload as $col => $value) {
            $col = self::assertIdentifier((string)$col, 'column');
            $ph = 'set_' . $i++;
            $sets[] = sprintf('%s = :%s', $col, $ph);
            $params[$ph] = $value;
        }
        $wheres = [];
        $j = 0;
        foreach ($criteria as $col => $value) {
            $col = self::assertIdentifier((string)$col, 'criteria');
            if ($value === null) {
                $wheres[] = sprintf('%s IS NULL', $col);
                continue;
            }
            $ph = 'where_' . $j++;
            $wheres[] = sprintf('%s = :%s', $col, $ph);
            $params[$ph] = $value;
        }
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', $sets),
            implode(' AND ', $wheres)
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
