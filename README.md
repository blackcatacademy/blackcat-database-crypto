# BlackCat Database Crypto Adapter

Automatic encryption/obfuscation adapter for `blackcat-database`.

Application code provides plaintext payloads and the adapter:

1. Reads per-package encryption maps (`blackcat-database/packages/*/schema/encryption-map.json`) and finds columns that require `encrypt` / `hmac`.
2. Uses `blackcat-crypto` (`CryptoManager` + manifest slots) to select the correct key and produce an envelope or HMAC.
3. Delegates the transformed payload back to the normal `blackcat-database` write path (repository / service / CLI).

That way, “input → encryption → database” becomes a single step.

## Quick start

```bash
composer install

# Create a runtime config file (preferred over env for security-critical paths).
cat > ./telemetry/runtime.json <<'JSON'
{
  "crypto": {
    "manifest": "../blackcat-crypto-manifests/contexts/core.json",
    "keys_dir": "./tests/fixtures/keys"
  }
}
JSON

php example.php   # local demo (encrypt + criteria)

# Tooling lives in `blackcat-cli` (optional):
# - blackcat db-crypto plan|telemetry|stress|health|schema|keys-sync

# validate packages map against generated schema (Definitions)
blackcat db-crypto plan --schema-source=packages --config=./telemetry/runtime.json

# validate against a live DB (recommended: limit to installed modules via --tables=...)
DB_USER=root DB_PASSWORD=secret blackcat db-crypto plan --dsn=\"mysql:host=127.0.0.1;dbname=blackcat\" --tables=orders,idempotency_keys --config=./telemetry/runtime.json

# map telemetry + smoke stress (transform-only; no DB)
blackcat db-crypto telemetry --out=telemetry/db-crypto-metrics.json
blackcat db-crypto stress --iterations=20000 --out=telemetry/db-crypto-stress.json --config=./telemetry/runtime.json
blackcat db-crypto health --generate-keys=1 --max-contexts=25 --out=telemetry/db-crypto-health.json

# key inventory into DB (audit/rotations)
DB_DSN=\"mysql:host=127.0.0.1;dbname=blackcat\" DB_USER=root DB_PASSWORD=secret blackcat db-crypto keys-sync --config=./telemetry/runtime.json
```

## Run tests (Docker)

Two tests are DB integration tests (require MySQL). A local shortcut is included:

```bash
./tools/phpunit-docker.sh
```

### Encrypted field configuration

**Single source of truth:** per-package maps in `blackcat-database/packages/*/schema/encryption-map.json` (1 file = 1 table).

Rules:
- the table in the map must match `Definitions::table()`
- the map must explicitly cover all columns from `Definitions::columns()` (`encrypt`/`hmac`/`passthrough`)
- `Definitions::uniqueKeys()` must not contain `strategy=encrypt` columns (non-deterministic; for UNIQUE use `hmac`/`passthrough`)
- `IngressLocator` loads the map **hard from packages** (cannot be redirected via env) so the source of truth is unambiguous

Note: if you use `blackcat-database` as a git repo with submodules, `packages/*` must be checked out (e.g. `git submodule update --init --recursive`).

`EncryptionMap::fromFile()` (including `includes`) is still available for tooling/tests/experiments, but the runtime ingress in `blackcat-database` runs in packages-only mode.

## API (recommended via `blackcat-database`)

```php
use BlackCat\Core\Database;
use BlackCat\Database\Crypto\IngressLocator;
use BlackCat\Database\Packages\Users\Repository\UserRepository;

$db = Database::getInstance();
$repo = new UserRepository($db);
$ingress = IngressLocator::adapter(); // fail-closed (throws when misconfigured)
$repo->setIngressAdapter($ingress, 'users');

$repo->insert([
    'email_hash' => 'alice@example.com'
]);
```

Note: newer `blackcat-database` repositories can auto-load ingress via `IngressLocator` (zero boilerplate). Explicit `setIngressAdapter()` is optional.

For transform-only usage (tests, queues, offline tooling), see `example.php` and `docs/INTEGRATIONS.md`.

Note: `PdoGateway` exists only as a legacy reference and is `@deprecated` (do not use in the ecosystem).

### Strategy options
- `encrypt` — uses `CryptoManager::encryptContext()` and stores an envelope (JSON string).
- `hmac` — uses `CryptoManager::hmac()` and stores a signature (hex/base64 depending on settings).
- `passthrough` — leaves the value unchanged (useful for combined maps).

### Write-path metadata (optional)
- `write_key_version: true` — fills `*_key_version` (or `key_version_column`) based on the key used for `encrypt`/`hmac`.
- `write_encryption_meta: true` — fills `encryption_meta` (or `encryption_meta_column`) as a JSON string with per-field metadata.

### Decrypt helper

- `DatabaseCryptoAdapter::decryptPayload()` (and `DatabaseIngressAdapter::decrypt()`) decrypts columns with `encrypt` strategy; `hmac` remains unchanged.

### Deterministic criteria helper

- `DatabaseIngressAdapter::criteria()` is used for queries/`upsertByKeys`: it transforms only `hmac` columns (deterministic) and rejects `encrypt` (non-deterministic).

### Schema snapshots

For optional validation against a live schema, prepare JSON as described in [docs/SCHEMA.md](./docs/SCHEMA.md). `db-crypto-plan` highlights missing columns and returns a non-zero exit code in CI.

## CLI / integrations

CLI tooling is provided by `blackcat-cli` (optional) to keep this repo a pure library:
- `blackcat db-crypto plan --schema-source=packages`
- `blackcat db-crypto telemetry --out=telemetry/db-crypto-metrics.json`
- `blackcat db-crypto stress --iterations=20000 --out=telemetry/db-crypto-stress.json`
- `blackcat db-crypto health --generate-keys=1 --max-contexts=25 --out=telemetry/db-crypto-health.json`

Practical integration notes (e.g. `blackcat-auth`) live in [docs/INTEGRATIONS.md](./docs/INTEGRATIONS.md).

### Map telemetry

Generate a quick map summary (tables/columns count, strategy/context distribution, HMAC encoding, missing strategy/context) and upload it as a CI artifact:

```bash
blackcat db-crypto telemetry --out=telemetry/db-crypto-metrics.json
```
The output is JSON suitable for CI checks (e.g. enforcing missing strategies/contexts).

## Licence
Proprietary / BlackCat Academy.
