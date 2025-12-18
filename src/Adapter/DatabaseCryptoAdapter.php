<?php
declare(strict_types=1);

namespace BlackCat\DatabaseCrypto\Adapter;

use BlackCat\Crypto\CryptoManager;
use BlackCat\DatabaseCrypto\Config\EncryptionMap;
use BlackCat\DatabaseCrypto\Gateway\DatabaseGatewayInterface;
use BlackCat\DatabaseCrypto\Mapper\PayloadDecryptor;
use BlackCat\DatabaseCrypto\Mapper\PayloadEncryptor;

final class DatabaseCryptoAdapter
{
    private PayloadEncryptor $encryptor;
    private PayloadDecryptor $decryptor;

    public function __construct(
        private readonly CryptoManager $crypto,
        private readonly EncryptionMap $map,
        private readonly DatabaseGatewayInterface $gateway,
    ) {
        $this->encryptor = new PayloadEncryptor($crypto, $this->map);
        $this->decryptor = new PayloadDecryptor($crypto, $this->map);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     */
    public function insert(string $table, array $payload, array $options = []): mixed
    {
        $cryptoOptions = $options;
        $cryptoOptions['purpose'] = 'write';
        $cryptoOptions['operation'] = 'insert';
        $transformed = $this->encryptor->transform($table, $payload, $cryptoOptions);
        return $this->gateway->insert($table, $transformed, $options);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $criteria
     * @param array<string,mixed> $options
     */
    public function update(string $table, array $payload, array $criteria, array $options = []): mixed
    {
        $cryptoOptions = $options;
        $cryptoOptions['purpose'] = 'write';
        $cryptoOptions['operation'] = 'update';
        $transformed = $this->encryptor->transform($table, $payload, $cryptoOptions);
        return $this->gateway->update($table, $transformed, $criteria, $options);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function encryptPayload(string $table, array $payload, array $options = []): array
    {
        if (!isset($options['purpose'])) {
            $options['purpose'] = 'write';
        }
        if (!isset($options['operation'])) {
            $options['operation'] = 'encrypt';
        }
        return $this->encryptor->transform($table, $payload, $options);
    }

    /**
     * @param array<string,mixed> $payload
     * @param array{strict?:bool} $options
     * @return array<string,mixed>
     */
    public function decryptPayload(string $table, array $payload, array $options = []): array
    {
        $strict = (bool)($options['strict'] ?? true);
        if ($strict === false) {
            return (new PayloadDecryptor($this->crypto, $this->map, false))->transform($table, $payload);
        }
        return $this->decryptor->transform($table, $payload);
    }
}
