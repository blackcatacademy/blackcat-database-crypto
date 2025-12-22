<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use BlackCat\Config\Runtime\Config as RuntimeConfig;
use BlackCat\Config\Runtime\ConfigRepository;
use BlackCat\Crypto\Config\CryptoConfig;
use BlackCat\Crypto\CryptoManager;
use BlackCat\DatabaseCrypto\Adapter\DatabaseCryptoAdapter;
use BlackCat\DatabaseCrypto\Config\PackagesEncryptionMapLoader;
use BlackCat\DatabaseCrypto\Gateway\DatabaseGatewayInterface;
use BlackCat\DatabaseCrypto\Ingress\DatabaseIngressAdapter;

// Convenience defaults for local workspace runs (real apps should use runtime config files).
$defaultRuntimeConfig = __DIR__ . '/telemetry/runtime.json';
$defaultKeysDir = realpath(__DIR__ . '/tests/fixtures/keys') ?: null;
$defaultManifest = realpath(__DIR__ . '/../blackcat-crypto-manifests/contexts/core.json') ?: null;

if (!RuntimeConfig::isInitialized()) {
    if (is_file($defaultRuntimeConfig)) {
        try {
            RuntimeConfig::initFromJsonFileIfNeeded($defaultRuntimeConfig);
        } catch (\Throwable) {
            // Fall back to in-memory defaults below.
        }
    }
}

if (!RuntimeConfig::isInitialized()) {
    if (!is_string($defaultKeysDir) || $defaultKeysDir === '') {
        throw new RuntimeException('Missing default keys dir (expected tests/fixtures/keys).');
    }

    RuntimeConfig::initIfNeeded(ConfigRepository::fromArray([
        'crypto' => [
            'keys_dir' => $defaultKeysDir,
            'manifest' => $defaultManifest,
        ],
    ]));
}

$crypto = CryptoManager::boot(CryptoConfig::fromEnv());

// Single source of truth: per-package `blackcat-database/packages/*/schema/encryption-map.json`.
$defaultDbRoot = realpath(__DIR__ . '/../blackcat-database') ?: null;
$map = $defaultDbRoot
    ? PackagesEncryptionMapLoader::fromBlackcatDatabaseRoot($defaultDbRoot)
    : PackagesEncryptionMapLoader::fromAutodetectedBlackcatDatabaseRoot();

$nullGateway = new class implements DatabaseGatewayInterface {
    public function insert(string $table, array $payload, array $options = []): mixed { return $payload; }
    public function update(string $table, array $payload, array $criteria, array $options = []): mixed { return $payload; }
};

$adapter = new DatabaseCryptoAdapter($crypto, $map, $nullGateway);
$ingress = new DatabaseIngressAdapter($adapter, $map, true);

echo "== Write-path encrypt(idempotency_keys) ==\n";
echo json_encode($ingress->encrypt('idempotency_keys', ['key_hash' => 'alice@example.com']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "== Deterministic criteria(idempotency_keys) ==\n";
echo json_encode($ingress->criteria('idempotency_keys', ['key_hash' => 'alice@example.com']), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

echo "== Write-path encrypt(orders) ==\n";
echo json_encode(
    $ingress->encrypt('orders', ['encrypted_customer_blob' => ['email' => 'alice@example.com', 'note' => 'hello']]),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
