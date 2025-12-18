<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Ops;

final class CryptoKeyFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $filename,
        public readonly CryptoKeyName $name,
        public readonly string $fingerprint,
        public readonly int $lengthBits,
        public readonly int $mtime,
        public readonly int $sizeBytes,
    ) {
        if ($this->path === '') {
            throw new \InvalidArgumentException('CryptoKeyFile: path must not be empty');
        }
        if ($this->filename === '') {
            throw new \InvalidArgumentException('CryptoKeyFile: filename must not be empty');
        }
        if ($this->fingerprint === '' || !preg_match('~^[0-9a-f]{64}$~', $this->fingerprint)) {
            throw new \InvalidArgumentException('CryptoKeyFile: fingerprint must be 64-char hex sha256');
        }
        if ($this->lengthBits < 1) {
            throw new \InvalidArgumentException('CryptoKeyFile: lengthBits must be positive');
        }
        if ($this->mtime < 0) {
            throw new \InvalidArgumentException('CryptoKeyFile: mtime must be non-negative');
        }
        if ($this->sizeBytes < 0) {
            throw new \InvalidArgumentException('CryptoKeyFile: sizeBytes must be non-negative');
        }
    }

    public static function fromPath(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('CryptoKeyFile: file not found: ' . $path);
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException('CryptoKeyFile: unable to read: ' . $path);
        }

        $real = realpath($path);
        $resolvedPath = $real !== false ? $real : $path;
        $filename = basename($resolvedPath);

        $mtime = filemtime($resolvedPath);
        $mtime = $mtime !== false ? (int)$mtime : 0;

        $size = filesize($resolvedPath);
        $size = $size !== false ? (int)$size : strlen($bytes);

        return new self(
            path: $resolvedPath,
            filename: $filename,
            name: CryptoKeyName::fromFilename($filename),
            fingerprint: hash('sha256', $bytes),
            lengthBits: strlen($bytes) * 8,
            mtime: $mtime,
            sizeBytes: $size,
        );
    }

    /**
     * @param array<string,mixed> $extraMeta
     * @return array<string,mixed>
     */
    public function toCryptoKeysRow(array $extraMeta = []): array
    {
        $meta = [
            'source' => 'filesystem',
            'filename' => $this->filename,
            'path' => $this->path,
            'mtime' => $this->mtime,
            'size_bytes' => $this->sizeBytes,
        ] + $extraMeta;

        $keyMeta = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($keyMeta === false) {
            throw new \RuntimeException('CryptoKeyFile: failed to encode key_meta');
        }

        return [
            'basename' => $this->name->basename,
            'version' => $this->name->version,
            'filename' => $this->filename,
            'file_path' => $this->path,
            'fingerprint' => $this->fingerprint,
            'key_meta' => $keyMeta,
            'length_bits' => $this->lengthBits,
            'origin' => 'local',
            'status' => 'active',
        ];
    }
}
