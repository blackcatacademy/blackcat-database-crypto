<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Mapper;

use BlackCat\Crypto\CryptoManager;
use BlackCat\Crypto\Support\Envelope;
use BlackCat\DatabaseCrypto\Config\EncryptionMap;

final class PayloadEncryptor
{
    public function __construct(
        private readonly CryptoManager $crypto,
        private readonly EncryptionMap $map
    ) {}

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function transform(string $table, array $payload, array $options = []): array
    {
        $columns = $this->map->columnsFor($table);
        if ($columns === null) {
            return $payload;
        }

        $purpose = strtolower((string)($options['purpose'] ?? 'write'));
        $writeMeta = $purpose === 'write';
        $operation = (string)($options['operation'] ?? $purpose);

        $result = $payload;
        foreach ($columns as $column => $strategy) {
            if (!array_key_exists($column, $payload)) {
                continue;
            }

            $spec = (array)$strategy;
            $mode = strtolower((string)($spec['strategy'] ?? 'encrypt'));
            $context = (string)($spec['context'] ?? '');
            $value = $payload[$column];

            if ($value === null) {
                $result[$column] = null;
                if ($writeMeta && $context !== '' && in_array($mode, ['encrypt', 'hmac'], true)) {
                    $this->maybeWriteKeyVersion($result, $column, $spec, null);
                    $this->maybeWriteEncryptionMeta($result, $column, $spec, [
                        'strategy' => $mode,
                        'context' => $context,
                        'keyId' => null,
                        'op' => $operation,
                        'cleared' => true,
                    ]);
                }
                continue;
            }

            if ($context === '') {
                if ($mode === 'passthrough') {
                    $result[$column] = $value;
                    continue;
                }
                throw new \InvalidArgumentException(
                    sprintf('PayloadEncryptor: missing context for %s.%s (strategy=%s)', $table, $column, $mode)
                );
            }

            if ($mode === 'encrypt') {
                $envelope = $this->encryptEnvelope($context, $value, $spec);
                $result[$column] = $envelope->encode();
                if ($writeMeta) {
                    $keyId = (string)($envelope->local->keyId ?? '');
                    $this->maybeWriteKeyVersion($result, $column, $spec, $keyId !== '' ? $keyId : null);
                    $this->maybeWriteEncryptionMeta($result, $column, $spec, $this->envelopeMeta($envelope, $context, $operation));
                }
                continue;
            }

            if ($mode === 'hmac') {
                [$encoded, $keyId] = $this->hmacEncodedWithKeyId($context, $value, $spec);
                $result[$column] = $encoded;
                if ($writeMeta) {
                    $this->maybeWriteKeyVersion($result, $column, $spec, $keyId);
                    $this->maybeWriteEncryptionMeta($result, $column, $spec, [
                        'strategy' => 'hmac',
                        'context' => $context,
                        'keyId' => $keyId,
                        'op' => $operation,
                        'ts' => time(),
                    ]);
                }
                continue;
            }

            if ($mode === 'passthrough') {
                $result[$column] = $value;
                continue;
            }

            throw new \InvalidArgumentException('Unknown strategy ' . $mode);
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $strategy
     */
    private function encryptEnvelope(string $context, mixed $value, array $strategy): Envelope
    {
        $plaintext = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($plaintext === false) {
            throw new \RuntimeException('Unable to serialize payload for encryption');
        }
        return $this->crypto->encryptContext($context, $plaintext, ['wrapCount' => $strategy['wrap_count'] ?? 0]);
    }

    /**
     * @param array<string,mixed> $strategy
     * @return array{0:string,1:string} [encodedSignature, keyId]
     */
    private function hmacEncodedWithKeyId(string $context, mixed $value, array $strategy): array
    {
        $message = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($message === false) {
            throw new \RuntimeException('Unable to serialize payload for HMAC');
        }

        $signature = null;
        $keyId = null;

        if (method_exists($this->crypto, 'hmacWithKeyId')) {
            try {
                /** @var mixed $out */
                $out = $this->crypto->hmacWithKeyId($context, $message);
                if (is_array($out) && isset($out['signature'], $out['keyId']) && is_string($out['signature']) && is_string($out['keyId'])) {
                    $signature = $out['signature'];
                    $keyId = $out['keyId'];
                }
            } catch (\Throwable) {
                // fall back below
            }
        }

        if (!is_string($signature) || !is_string($keyId) || $keyId === '') {
            $signature = $this->crypto->hmac($context, $message);
            $keyId = $this->crypto->keyMaterial($context)->id;
        }

        $encoding = strtolower((string)($strategy['encoding'] ?? 'raw'));
        $encoding = match ($encoding) {
            'bin', 'binary' => 'raw',
            'b64' => 'base64',
            default => $encoding,
        };

        $encoded = match ($encoding) {
            'raw' => $signature,
            'hex' => bin2hex($signature),
            'base64' => base64_encode($signature),
            default => throw new \InvalidArgumentException('Unknown HMAC encoding ' . $encoding),
        };

        return [$encoded, $keyId];
    }

    /**
     * @return array<string,mixed>
     */
    private function envelopeMeta(Envelope $envelope, string $context, string $operation): array
    {
        $kms = $envelope->kmsMetadata ?? [];
        $wrapCount = $kms['wrapCount'] ?? ($envelope->meta['wrapCount'] ?? 0);

        return [
            'strategy' => 'encrypt',
            'context' => $context,
            'keyId' => (string)($envelope->local->keyId ?? ''),
            'kmsClient' => isset($kms['client']) ? (string)$kms['client'] : null,
            'wrapCount' => is_numeric($wrapCount) ? (int)$wrapCount : null,
            'op' => $operation,
            'ts' => is_numeric($envelope->meta['createdAt'] ?? null) ? (int)$envelope->meta['createdAt'] : time(),
        ];
    }

    /**
     * @param array<string,mixed> $strategy
     * @param array<string,mixed> $metaEntry
     * @param array<string,mixed> $result
     */
    private function maybeWriteEncryptionMeta(array &$result, string $column, array $strategy, array $metaEntry): void
    {
        $write = (bool)($strategy['write_encryption_meta'] ?? false);
        if (!$write) {
            return;
        }

        $metaColumn = $strategy['encryption_meta_column'] ?? 'encryption_meta';
        if (!is_string($metaColumn) || $metaColumn === '') {
            return;
        }

        $existing = $result[$metaColumn] ?? null;
        $decoded = $this->decodeJsonObject($existing);

        if (!isset($decoded['v']) || !is_int($decoded['v'])) {
            $decoded['v'] = 1;
        }
        if (!isset($decoded['fields']) || !is_array($decoded['fields'])) {
            $decoded['fields'] = [];
        }

        $decoded['fields'][(string)$column] = $metaEntry;

        $json = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode encryption_meta JSON');
        }
        $result[$metaColumn] = $json;
    }

    /**
     * @param array<string,mixed> $strategy
     * @param array<string,mixed> $result
     */
    private function maybeWriteKeyVersion(array &$result, string $column, array $strategy, ?string $keyId): void
    {
        $write = (bool)($strategy['write_key_version'] ?? false);
        if (!$write) {
            return;
        }

        $keyVersionColumn = $strategy['key_version_column'] ?? ($column . '_key_version');
        if (!is_string($keyVersionColumn) || $keyVersionColumn === '') {
            return;
        }

        $result[$keyVersionColumn] = $keyId;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            /** @var array<string,mixed> */
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
