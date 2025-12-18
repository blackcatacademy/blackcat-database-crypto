<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Ops;

use BlackCat\Core\Database;
use BlackCat\Database\Support\UpsertBuilder;

final class CryptoKeysInventorySync
{
    public function __construct(
        private readonly Database $db,
    ) {
    }

    /**
     * Sync all `*.key` files from a directory into `crypto_keys`.
     *
     * @param array{strict?:bool,meta?:array<string,mixed>} $options
     * @return array{scanned:int,upserted:int,skipped:int,errors:list<array{file:string,error:string}>}
     */
    public function syncDirectory(string $keysDir, array $options = []): array
    {
        if (!is_dir($keysDir)) {
            throw new \InvalidArgumentException('CryptoKeysInventorySync: not a directory: ' . $keysDir);
        }

        $strict = (bool)($options['strict'] ?? false);
        $extraMeta = is_array($options['meta'] ?? null) ? (array)$options['meta'] : [];

        $files = glob(rtrim($keysDir, '/\\') . '/*.key') ?: [];
        sort($files, SORT_STRING);

        $result = [
            'scanned' => 0,
            'upserted' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($files as $path) {
            $result['scanned']++;
            try {
                $keyFile = CryptoKeyFile::fromPath($path);
                $row = $keyFile->toCryptoKeysRow($extraMeta);
                $this->upsertCryptoKeyRow($row);
                $result['upserted']++;
            } catch (\Throwable $e) {
                $result['skipped']++;
                $result['errors'][] = [
                    'file' => (string)$path,
                    'error' => $e->getMessage(),
                ];
                if ($strict) {
                    throw $e;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function upsertCryptoKeyRow(array $row): void
    {
        $conflictKeys = ['basename', 'version'];
        $updateCols = array_values(array_diff(array_keys($row), $conflictKeys));

        [$sql, $params] = UpsertBuilder::buildRow(
            db: $this->db,
            table: 'crypto_keys',
            row: $row,
            conflictKeys: $conflictKeys,
            updateCols: $updateCols,
            updatedAt: null,
        );

        $this->db->execute($sql, $params);
    }
}

