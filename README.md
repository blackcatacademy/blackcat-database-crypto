# BlackCat Database Crypto Adapter

Automatic encryption/obfuscation adapter for `blackcat-database`.

Application code provides plaintext payloads and the adapter:

1. Reads per-package encryption maps (`blackcat-database/packages/*/schema/encryption-map.json`) and finds columns that require `encrypt` / `hmac`.
2. Uses `blackcat-crypto` (`CryptoManager` + manifest slots) to select the correct key and produce an envelope or HMAC.
3. Delegates the transformed payload back to the normal `blackcat-database` write path (repository / service / CLI).

That way, “input → encryption → database” becomes a single step.

For Czech docs, see `README.cs.md`.

## Quick start

```bash
composer install

# if Composer says "Could not authenticate against github.com":
# composer config -g github-oauth.github.com "$GITHUB_TOKEN"

export BLACKCAT_CRYPTO_MANIFEST=../blackcat-crypto-manifests/contexts/core.json
export BLACKCAT_KEYS_DIR=./tests/fixtures/keys
php example.php   # local demo (encrypt + criteria)

# validate packages map against generated schema (Definitions)
php bin/db-crypto-plan --schema-source=packages

# validate against a live DB (recommended: limit to installed modules via --tables=...)
DB_USER=root DB_PASSWORD=secret php bin/db-crypto-plan --dsn=\"mysql:host=127.0.0.1;dbname=blackcat\" --tables=orders,idempotency_keys

# map telemetry + smoke stress (transform-only; no DB)
php bin/db-crypto-telemetry --out=telemetry/db-crypto-metrics.json
php bin/db-crypto-stress --iterations=20000 --out=telemetry/db-crypto-stress.json
php bin/db-crypto-health --generate-keys=1 --max-contexts=25 --out=telemetry/db-crypto-health.json

# key inventory into DB (audit/rotations)
DB_DSN=\"mysql:host=127.0.0.1;dbname=blackcat\" DB_USER=root DB_PASSWORD=secret BLACKCAT_KEYS_DIR=./tests/fixtures/keys php bin/db-crypto-keys-sync
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

Use the CLI to validate the map and build schema snapshots:

- `php bin/db-crypto-plan --schema-source=packages` (validace packages mapy)
- `DB_USER=... DB_PASSWORD=... php bin/db-crypto-plan --dsn=\"...\" --tables=orders,idempotency_keys` (validace subsetu proti live DB)
- `php bin/db-crypto-telemetry --out=telemetry/db-crypto-metrics.json`
- `php bin/db-crypto-stress --iterations=20000 --out=telemetry/db-crypto-stress.json`
- `php bin/db-crypto-health --generate-keys=1 --max-contexts=25 --out=telemetry/db-crypto-health.json`

Practical integration notes (e.g. `blackcat-auth`) live in [docs/INTEGRATIONS.md](./docs/INTEGRATIONS.md).

### Map telemetry

Generate a quick map summary (tables/columns count, strategy/context distribution, HMAC encoding, missing strategy/context) and upload it as a CI artifact:

```bash
php bin/db-crypto-telemetry --out=telemetry/db-crypto-metrics.json
```
The output is JSON suitable for CI checks (e.g. enforcing missing strategies/contexts).

## Licence
Proprietary / BlackCat Academy.
