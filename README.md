# BlackCat Database Crypto Adapter

Automatický šifrovací/obfuskační adapter pro `blackcat-database`. Cílem je, aby aplikační kód pouze předal vstupní payload a adapter sám:

1. Podle manifestu najde sloupce/sloty vyžadující šifrování nebo HMAC.
2. Využije `blackcat-crypto` (`CryptoManager`) pro vytvoření envelope nebo značky.
3. Deleguje výsledek na původní `blackcat-database` write‑path (repository / service / CLI akce).

Tím pádem se „vstup → šifrování → databáze“ zkrátí na jediný krok.

## Rychlý start

```bash
composer install

# pokud Composer hlásí "Could not authenticate against github.com":
# composer config -g github-oauth.github.com "$GITHUB_TOKEN"

export BLACKCAT_CRYPTO_MANIFEST=../blackcat-crypto-manifests/contexts/core.json
php example.php   # lokální demo (encrypt + criteria)
# validace mapy (snapshot)
php bin/db-crypto-plan --schema=config/schema.snapshot.json config/encryption.example.json
# nebo přímo proti živé DB (použije $DB_USER / $DB_PASSWORD)
DB_USER=root DB_PASSWORD=secret php bin/db-crypto-plan --dsn=\"mysql:host=127.0.0.1;dbname=blackcat\" config/encryption.example.json
# export schématu pro CI
# (default: schema z blackcat-database packages)
php bin/db-crypto-schema --map=config/encryption.example.json config/schema.snapshot.json
# nebo přímo z live DB
DB_USER=root DB_PASSWORD=secret php bin/db-crypto-schema --source=db --dsn=\"mysql:host=127.0.0.1;dbname=blackcat\" config/schema.snapshot.json

# inventář klíčů do DB (audit/rotace)
DB_DSN=\"mysql:host=127.0.0.1;dbname=blackcat\" DB_USER=root DB_PASSWORD=secret BLACKCAT_KEYS_DIR=./tests/fixtures/keys php bin/db-crypto-keys-sync
```

### Konfigurace šifrovaných polí

`config/encryption.example.json` obsahuje mapu tabulek/sloupců:

```json
{
  "tables": {
    "users": {
      "columns": {
        "email_hash": {
          "strategy": "hmac",
          "context": "core.hmac.email",
          "encoding": "raw",
          "write_key_version": true
        }
      }
    },
    "orders": {
      "columns": {
        "encrypted_customer_blob": {
          "strategy": "encrypt",
          "context": "core.vault",
          "write_key_version": true,
          "write_encryption_meta": true
        }
      }
    }
  }
}
```

Mapa může být i složená z více souborů přes `includes` (užitečné pro modularitu ekosystému) – viz `docs/INTEGRATIONS.md`.

Nahraj vlastní JSON / PHP pole, načti přes `EncryptionMap::fromFile()` a použij přes `blackcat-database` ingress (`IngressLocator`) nebo přímo přes `DatabaseCryptoAdapter` (transform-only).

## API (doporučené použití přes `blackcat-database`)

```php
use BlackCat\Core\Database;
use BlackCat\Database\Crypto\IngressLocator;
use BlackCat\Database\Packages\Users\Repository\UserRepository;

$db = Database::getInstance();
$repo = new UserRepository($db);
if ($ingress = IngressLocator::adapter()) {
    $repo->setIngressAdapter($ingress, 'users');
}

$repo->insert([
    'email_hash' => 'alice@example.com'
]);
```

Pozn.: Novější `blackcat-database` repos umí ingress načíst i automaticky přes `IngressLocator` (zero‑boilerplate) – explicitní `setIngressAdapter()` je pak volitelné.

Pro transform-only použití (např. testy, queue, offline tooling) viz `example.php` a `docs/INTEGRATIONS.md`.

Pozn.: `PdoGateway` existuje jen jako legacy reference a je `@deprecated` (v ekosystému nepoužívat).

### Možnosti strategií
- `encrypt` – použije `CryptoManager::encryptContext()` a uloží envelope (JSON string).
- `hmac` – použije `CryptoManager::hmac()` a uloží podpis (hex/base64 podle nastavení).
- `passthrough` – ponechá hodnotu beze změny (užitečné při kombinovaných mapách).

### Write‑path metadata (volitelné)
- `write_key_version: true` – doplní `*_key_version` (nebo `key_version_column`) podle klíče použitého pro `encrypt`/`hmac`.
- `write_encryption_meta: true` – doplní `encryption_meta` (nebo `encryption_meta_column`) jako JSON string s metadaty per field.

### Decrypt helper

- `DatabaseCryptoAdapter::decryptPayload()` (a `DatabaseIngressAdapter::decrypt()`) umí dešifrovat sloupce se strategií `encrypt`; `hmac` zůstává beze změny.

### Deterministic criteria helper

- `DatabaseIngressAdapter::criteria()` slouží pro query/`upsertByKeys` – transformuje pouze `hmac` sloupce (deterministicky) a odmítne `encrypt` (nedeterministické).

### Schema snapshots

Pro volitelnou kontrolu proti živému schématu připrav JSON dle [docs/SCHEMA.md](./docs/SCHEMA.md). `db-crypto-plan` pak zvýrazní sloupce, které v DB chybí, a v CI vrátí nenulový exit kód.

## CLI / Integrace

Použij CLI pro rychlou validaci mapy a tvorbu schema snapshotů:

- `php bin/db-crypto-plan --schema-source=packages config/encryption.example.json` (single source of truth: `blackcat-database` packages)
- `php bin/db-crypto-plan --schema=config/schema.snapshot.json config/encryption.example.json`
- `DB_USER=... DB_PASSWORD=... php bin/db-crypto-plan --dsn=\"...\" config/encryption.example.json`

Praktické integrační poznámky (např. `blackcat-auth`) jsou v [docs/INTEGRATIONS.md](./docs/INTEGRATIONS.md).

### Telemetrie mapy

Generuj rychlý přehled mapy (počty tabulek/sloupců, rozložení strategií/kontextů/HMAC encoding, chybějící strategie/kontexty) a nahraj ho jako CI artefakt:

```bash
# vstup z env BLACKCAT_CRYPTO_MAP nebo první argument (default config/encryption.example.json)
php bin/db-crypto-telemetry config/encryption.example.json --out=telemetry/db-crypto-metrics.json
```
Výstup je JSON vhodný pro kontroly v CI (např. hlídání chybějících strategií/kontextů).

## Licence
Proprietární / BlackCat Academy.
