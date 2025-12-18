# Integrace do ostatních repozitářů (zero‑boilerplate)

Tento balíček je „bridge“ mezi:

- `blackcat-crypto` (manifest + `CryptoManager`) – čistá crypto logika,
- `blackcat-database` (repos/services/installer) – čistá DB logika,
- `blackcat-database-crypto` – **transparentní write‑path šifrování/HMAC** pro citlivá data v DB.

Cíl: v aplikačních repozitářích (např. `blackcat-auth`) řešit pouze business logiku. DB připojení, upserty a šifrování jsou centralizované.

## 1) Minimální konfigurace (env)

Ingress adapter (`BlackCat\Database\Crypto\IngressLocator`) se umí nabootovat automaticky, pokud existuje mapa + klíče:

- `BLACKCAT_DB_ENCRYPTION_MAP=./config/encryption.json`
- `BLACKCAT_KEYS_DIR=./keys` (standard: `*_vN.key`)
- `BLACKCAT_CRYPTO_MANIFEST=/path/to/contexts/core.json`
- `DB_DSN=...` (a volitelně `DB_USER`, `DB_PASSWORD`) – pro `BlackCat\Core\Database`
- (doporučeno) `BLACKCAT_DB_ENCRYPTION_REQUIRED=1` – fail‑closed (pokud ingress nejde nabootovat, aplikace spadne hned)

Pozn.: Snapshot/gate nástroje defaultně používají `blackcat-database` packages jako single source of truth (Definitions), takže nemusíš duplikovat schémata.

### Doporučený bootstrap (1 řádek)

V aplikačních repozitářích je ideální použít `blackcat-crypto` bootstrap, který rovnou nakonfiguruje i DB ingress locator:

```php
use BlackCat\Crypto\Bootstrap\PlatformBootstrap;

PlatformBootstrap::boot(); // CryptoManager + Core bridge + DB ingress
```

### Modulární mapy (includes)

Šifrovací mapa může být složená z více souborů. To je ideální pro modulární ekosystém – každý modul/repo může dodat svůj malý fragment a „app-level“ mapa je jen složenina:

```json
{
  "includes": [
    "./maps/core.json",
    "./maps/auth.json"
  ],
  "tables": {
    "users": {
      "columns": {
        "email_hash": { "context": "core.hmac.email" }
      }
    }
  }
}
```

Pravidlo mergování: includes se načtou první (v pořadí) a aktuální soubor je přepíše (shallow merge per column spec).

## 2) Write‑path bez boilerplate (doporučeno)

### Varianta A: přes `GenericCrudService` (auto‑attach ingress)

`blackcat-database` služby při konstrukci automaticky zavolají `IngressLocator::adapter()` a připojí ingress do repository – takže write‑path (`create/update/upsert`) šifruje bez dalšího kódu.

```php
use BlackCat\Core\Database;
use BlackCat\Database\Packages\Users\Repository\UserRepository;
use BlackCat\Database\Services\GenericCrudService;

$db = Database::getInstance();
$repo = new UserRepository($db);
$svc  = new GenericCrudService($db, $repo, 'id');

// Vstup je plaintext → před zápisem se transformuje podle mapy (encrypt/hmac/meta).
$svc->create([
    'id' => 1,
    'email_hash' => 'alice@example.com',
]);
```

### Varianta B: přímé repo (volitelné attach)

Pokud používáš repo přímo, můžeš ingress nastavit explicitně (např. pro starší verze `blackcat-database` nebo pokud chceš přepsat tabulku):

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
    'id' => 1,
    'email_hash' => 'alice@example.com',
]);
```

## 3) Deterministic lookup (login, search) – `criteria()`

Pro lookupy typu „najdi uživatele podle e‑mailu“ ukládej deterministické pole jako `hmac` (např. `users.email_hash`).

Pak v aplikaci nikdy nepočítáš HMAC ručně – použiješ ingress:

```php
use BlackCat\Database\Crypto\IngressLocator;

$ingress = IngressLocator::adapter();
if ($ingress === null) {
    throw new RuntimeException('DB crypto ingress not configured');
}

$crit = $ingress->criteria('users', ['email_hash' => $email]); // HMAC-only
// … repo query / exists / upsertByKeys s $crit …
```

`criteria()` odmítne `encrypt` (nedeterministické), aby nedošlo k falešným dotazům.

Pozn.: V novějších generated repos z `blackcat-database` se `getByUnique()` snaží zavolat `ingressCriteriaTransform()` automaticky, takže lookup podle `hmac` sloupců může fungovat i bez ručního volání `criteria()`.

## 4) Praktický příklad: `blackcat-auth` (doporučený směr)

`blackcat-auth` už dnes umí používat `blackcat-database` schéma (`users` tabulka). Další krok je:

1) Nastavit `BLACKCAT_DB_ENCRYPTION_MAP` pro `users` citlivá pole (min. deterministické `email_hash`).
2) V login flow používat `IngressLocator::adapter()->criteria('users', …)` pro lookup.
3) Ve write‑path (seed uživatelů, registrace, změna e‑mailu) zapisovat plaintext – repo/service to samo zašifruje/HMAC.

### 4.1 Email verifikace přes DB queue (`blackcat-auth` + `blackcat-mailing`)

Auth modul při registraci (nebo resend) **nevytváří SMTP spojení** – pouze vloží notifikaci do DB tabulky `notifications`.
Odeslání řeší samostatný worker z `blackcat-mailing` (`bin/mailing-worker`), který čte claimable řádky přes view `vw_notifications_due`.

Díky ingress vrstvě:
- `notifications.payload` může být uložen jako ciphertext envelope (AEAD) – aplikace zapisuje plaintext JSON,
- lookupy pro rate-limit tabulky (`login_attempts`, `register_events`) používají deterministické HMAC (bez plaintext IP/username v DB).

### Příklad mapy (výřez)

```json
{
  "tables": {
    "users": {
      "columns": {
        "email_hash": {
          "strategy": "hmac",
          "context": "core.hmac.email",
          "write_key_version": true
        }
      }
    },
    "login_attempts": {
      "columns": {
        "ip_hash": { "strategy": "hmac", "context": "core.hmac.session" },
        "username_hash": { "strategy": "hmac", "context": "core.hmac.session" }
      }
    },
    "register_events": {
      "columns": {
        "ip_hash": { "strategy": "hmac", "context": "core.hmac.session" }
      }
    },
    "notifications": {
      "columns": {
        "payload": { "strategy": "encrypt", "context": "core.vault" }
      }
    }
  }
}
```

## 5) Operace: views/joins pro crypto (single source of truth)

Join/ops views pro crypto/KMS/encryption governance jsou deklarativně ve `blackcat-database/views-library/crypto/joins-*.yaml`
a instalují se přes `blackcat-database` (040_views_joins.* scripts). Nejde o další zdroj pravdy – je to **operational layer**
nad tabulkami z `blackcat-database` schémat.

## TODO (další integrace)

- `blackcat-sessions`: deterministické HMAC pro lookup tokenů + šifrování session payloadu (`encrypt`).
- `blackcat-identity`: šifrování PII blobů + governance napojení (`encrypted_fields_without_binding`).
- `blackcat-messaging`: šifrované message payloady + tokenizace pro search (budoucí Stage 4).
