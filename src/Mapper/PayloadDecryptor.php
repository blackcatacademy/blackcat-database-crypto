<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Mapper;

use BlackCat\Crypto\CryptoManager;
use BlackCat\DatabaseCrypto\Config\EncryptionMap;

final class PayloadDecryptor
{
    public function __construct(
        private readonly CryptoManager $crypto,
        private readonly EncryptionMap $map,
        private readonly bool $strict = true,
    ) {}

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function transform(string $table, array $payload): array
    {
        $columns = $this->map->columnsFor($table);
        if ($columns === null) {
            return $payload;
        }

        $result = $payload;
        foreach ($columns as $column => $strategy) {
            if (!array_key_exists($column, $payload)) {
                continue;
            }
            $result[$column] = $this->applyStrategy((array)$strategy, $payload[$column]);
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $strategy
     */
    private function applyStrategy(array $strategy, mixed $value): mixed
    {
        $mode = strtolower((string)($strategy['strategy'] ?? 'encrypt'));
        $context = (string)($strategy['context'] ?? '');
        if ($value === null || $context === '') {
            return $value;
        }

        return match ($mode) {
            'encrypt' => $this->decryptValue($context, $value),
            'hmac', 'passthrough' => $value,
            default => throw new \InvalidArgumentException('Unknown strategy ' . $mode),
        };
    }

    private function decryptValue(string $context, mixed $value): mixed
    {
        if (!is_string($value)) {
            if (is_scalar($value)) {
                $value = (string)$value;
            } else {
                return $value;
            }
        }

        try {
            return $this->crypto->decryptContext($context, $value);
        } catch (\Throwable $e) {
            if ($this->strict) {
                throw $e;
            }
            return $value;
        }
    }
}
