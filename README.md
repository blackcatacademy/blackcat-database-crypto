# BlackCat Database Crypto Adapter

Automatický šifrovací/obfuskační adapter pro `blackcat-database`. Cílem je, aby aplikační kód pouze předal vstupní payload a adapter sám:

1. Podle packages encryption mapy (`blackcat-database/packages/*/schema/encryption-map.json`) najde sloupce vyžadující `encrypt` / `hmac`.
2. Využije `blackcat-crypto` (`CryptoManager` + manifest slots) pro výběr správného klíče a vytvoření envelope nebo HMAC.
3. Deleguje výsledek na původní `blackcat-database` write‑path (repository / service / CLI akce).

Tím pádem se „vstup → šifrování → databáze“ zkrátí na jediný krok.

## Rychlý start

```bash
composer install

# pokud Composer hlásí "Could not authenticate against github.com":
# composer config -g github-oauth.github.com "$GITHUB_TOKEN"

export BLACKCAT_CRYPTO_MANIFEST=../blackcat-crypto-manifests/contexts/core.json
export BLACKCAT_KEYS_DIR=./tests/fixtures/keys
php example.php   # lokální demo (encrypt + criteria)

# validace packages mapy oproti generated schématu (Definitions)
php bin/db-crypto-plan --schema-source=packages

# validace proti živé DB (doporučeno omezit přes --tables=... na nainstalované moduly)
DB_USER=root DB_PASSWORD=secret php bin/db-crypto-plan --dsn=\"mysql:host=127.0.0.1;dbname=blackcat\" --tables=orders,idempotency_keys

# telemetrie mapy + smoke stress (transform-only; bez DB)
php bin/db-crypto-telemetry --out=telemetry/db-crypto-metrics.json
php bin/db-crypto-stress --iterations=20000 --out=telemetry/db-crypto-stress.json
php bin/db-crypto-health --generate-keys=1 --max-contexts=25 --out=telemetry/db-crypto-health.json

# inventář klíčů do DB (audit/rotace)
DB_DSN=\"mysql:host=127.0.0.1;dbname=blackcat\" DB_USER=root DB_PASSWORD=secret BLACKCAT_KEYS_DIR=./tests/fixtures/keys php bin/db-crypto-keys-sync
```

### Konfigurace šifrovaných polí

**Single source of truth:** per‑package mapy v `blackcat-database/packages/*/schema/encryption-map.json` (1 soubor = 1 tabulka).

Pravidla:
- tabulka v mapě musí odpovídat `Definitions::table()`
- mapa musí explicitně pokrýt všechny sloupce z `Definitions::columns()` (`encrypt`/`hmac`/`passthrough`)
- `Definitions::uniqueKeys()` nesmí obsahovat `strategy=encrypt` sloupce (nedeterministické; pro UNIQUE používej `hmac`/`passthrough`)
- `IngressLocator` mapu načítá **natvrdo z packages** (nejde přesměrovat přes env), aby byl zdroj pravdy jednoznačný

Pozn.: pokud máš `blackcat-database` jako git repo se submoduly, musí být `packages/*` checkoutnuté (např. `git submodule update --init --recursive`).

`EncryptionMap::fromFile()` (včetně `includes`) zůstává k dispozici pro tooling/testy/experimenty, ale runtime ingress v `blackcat-database` používá packages-only režim.

## API (doporučené použití přes `blackcat-database`)

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

- `php bin/db-crypto-plan --schema-source=packages` (validace packages mapy)
- `DB_USER=... DB_PASSWORD=... php bin/db-crypto-plan --dsn=\"...\" --tables=orders,idempotency_keys` (validace subsetu proti live DB)
- `php bin/db-crypto-telemetry --out=telemetry/db-crypto-metrics.json`
- `php bin/db-crypto-stress --iterations=20000 --out=telemetry/db-crypto-stress.json`
- `php bin/db-crypto-health --generate-keys=1 --max-contexts=25 --out=telemetry/db-crypto-health.json`

Praktické integrační poznámky (např. `blackcat-auth`) jsou v [docs/INTEGRATIONS.md](./docs/INTEGRATIONS.md).

### Telemetrie mapy

Generuj rychlý přehled mapy (počty tabulek/sloupců, rozložení strategií/kontextů/HMAC encoding, chybějící strategie/kontexty) a nahraj ho jako CI artefakt:

```bash
php bin/db-crypto-telemetry --out=telemetry/db-crypto-metrics.json
```
Výstup je JSON vhodný pro kontroly v CI (např. hlídání chybějících strategií/kontextů).

## Licence
Proprietární / BlackCat Academy.
