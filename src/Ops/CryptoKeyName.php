<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Ops;

final class CryptoKeyName
{
    public function __construct(
        public readonly string $basename,
        public readonly int $version,
    ) {
        $base = trim($this->basename);
        if ($base === '') {
            throw new \InvalidArgumentException('CryptoKeyName: basename must not be empty');
        }
        if ($this->version < 1) {
            throw new \InvalidArgumentException('CryptoKeyName: version must be >= 1');
        }
    }

    public static function fromFilename(string $filename): self
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $stem = trim((string)$stem);
        if ($stem === '') {
            throw new \InvalidArgumentException('CryptoKeyName: invalid filename');
        }

        if (preg_match('~^(?P<base>.+?)[_-]v(?P<ver>\\d+)$~i', $stem, $m)) {
            return new self(
                basename: strtolower((string)$m['base']),
                version: (int)$m['ver'],
            );
        }

        throw new \InvalidArgumentException('CryptoKeyName: filename must include _vN version suffix (example: crypto_key_v1.key)');
    }
}
