# Integrations into other repositories (zero boilerplate)

This package is a bridge between:

- `blackcat-crypto` (manifest + `CryptoManager`) — pure crypto logic,
- `blackcat-database` (repos/services/installer) — pure DB logic,
- `blackcat-database-crypto` — **transparent write-path encryption/HMAC** for sensitive DB data.

Goal: application repositories (e.g. `blackcat-auth`) should focus on business logic. DB connectivity, upserts, and crypto transforms are centralized.

## 1) Minimal configuration (runtime config)

The ingress adapter (`BlackCat\Database\Crypto\IngressLocator`) can boot automatically if packages maps + keys are available:

- (required) `blackcat-database/packages/*/schema/encryption-map.json` (1 file = 1 table; covers all columns from `Definitions::columns()`)
- (required) runtime config `crypto.keys_dir` (standard key files: `*_vN.key` / `*_vN.hex`)
- (recommended) runtime config `crypto.manifest` (`blackcat-crypto-manifests/contexts/*.json`)
- `DB_DSN=...` (optionally `DB_USER`, `DB_PASSWORD`) — for `BlackCat\Core\Database` (operational config; not crypto-critical)

Note: snapshot/gate tools use `blackcat-database` packages as the single source of truth (Definitions), so you do not have to duplicate schemas.

Note: the map source is intentionally **packages-only** (cannot be redirected via env/map file), so the source of truth stays unambiguous and safe.
Fail-closed is the default: if ingress cannot boot (map/keys/manifest), the application fails immediately.
If `blackcat-database` is installed as a git repo with submodules, `packages/*` must be checked out (init/update submodules).

### Recommended bootstrap (one line)

In application repositories, prefer the `blackcat-crypto` bootstrap which also configures the DB ingress locator.
It is **runtime-config-first** and does not rely on env by default:

```php
use BlackCat\Crypto\Bootstrap\PlatformBootstrap;

PlatformBootstrap::boot(); // CryptoManager + Core bridge + DB ingress
```

### Modular maps (`includes`)

An encryption map can be composed from multiple files via `includes` (useful for tooling/transform-only usage outside `IngressLocator`).
Note: the runtime ingress in `blackcat-database` uses packages-only maps.

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

Merge rule: includes are loaded first (in order) and the current file overrides them (shallow merge per column spec).

## 2) Boilerplate-free write path (recommended)

### Option A: via `GenericCrudService` (auto-attach ingress)

`blackcat-database` services call `IngressLocator::adapter()` during construction and attach ingress to the repository, so the write path (`create/update/upsert`) encrypts without extra code.

```php
use BlackCat\Core\Database;
use BlackCat\Database\Packages\Users\Repository\UserRepository;
use BlackCat\Database\Services\GenericCrudService;

$db = Database::getInstance();
$repo = new UserRepository($db);
$svc  = new GenericCrudService($db, $repo, 'id');

// Input is plaintext → transformed before write according to the map (encrypt/hmac/meta).
$svc->create([
    'id' => 1,
    'email_hash' => 'alice@example.com',
]);
```

### Option B: direct repository (optional attach)

If you use the repository directly, you can attach ingress explicitly (e.g. for older `blackcat-database` versions or if you need to override the table name):

```php
use BlackCat\Core\Database;
use BlackCat\Database\Crypto\IngressLocator;
use BlackCat\Database\Packages\Users\Repository\UserRepository;

$db = Database::getInstance();
$repo = new UserRepository($db);
$ingress = IngressLocator::adapter(); // fail-closed (throws when misconfigured)
$repo->setIngressAdapter($ingress, 'users');

$repo->insert([
    'id' => 1,
    'email_hash' => 'alice@example.com',
]);
```

## 3) Deterministic lookup (login, search) — `criteria()`

For lookups like “find user by email”, store a deterministic field as `hmac` (e.g. `users.email_hash`).

Then you never compute HMAC manually in the application — you use ingress:

```php
use BlackCat\Database\Crypto\IngressLocator;

$ingress = IngressLocator::adapter(); // fail-closed (throws when misconfigured)

$crit = $ingress->criteria('users', ['email_hash' => $email]); // HMAC-only
// … repo query / exists / upsertByKeys s $crit …
```

`criteria()` rejects `encrypt` (non-deterministic) to avoid false queries.

Note: in newer generated repositories from `blackcat-database`, `getByUnique()` tries to call `ingressCriteriaTransform()` automatically, so lookups by `hmac` columns can work without calling `criteria()` manually.

## 4) Practical example: `blackcat-auth` (recommended direction)

`blackcat-auth` can already use the `blackcat-database` schema (`users` table). Next steps:

1) Define an encryption map for sensitive `users` fields (at minimum deterministic `email_hash`) in `blackcat-database/packages/users/schema/encryption-map.json`.
2) In the login flow, use `IngressLocator::adapter()->criteria('users', …)` for lookups.
3) In the write path (seeding users, registration, email change), write plaintext — the repo/service will encrypt/HMAC it.

### 4.1 Email verification via DB queue (`blackcat-auth` + `blackcat-mailing`)

During registration (or resend), the auth module **does not create an SMTP connection** — it only inserts a notification into the DB table `notifications`.
Sending is handled by a separate worker from `blackcat-mailing` (`bin/mailing-worker`), which reads claimable rows via the view `vw_notifications_due`.

Thanks to the ingress layer:
- `notifications.payload` can be stored as a ciphertext envelope (AEAD) — the app writes plaintext JSON,
- lookups for rate-limit tables (`login_attempts`, `register_events`) use deterministic HMAC (no plaintext IP/username in DB).

### Example map (excerpt)

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

## 5) Operations: crypto views/joins (single source of truth)

Join/ops views for crypto/KMS/encryption governance are declarative in `blackcat-database/views-library/crypto/joins-*.yaml`
and are installed via `blackcat-database` (040_views_joins.* scripts). This is not another source of truth — it is an **operational layer**
on top of the tables defined by `blackcat-database` schemas.

## TODO (more integrations)

- `blackcat-sessions`: deterministic HMAC for token lookups + session payload encryption (`encrypt`).
- `blackcat-identity`: encrypt PII blobs + governance integration (`encrypted_fields_without_binding`).
- `blackcat-messaging`: encrypted message payloads + search tokenization (future Stage 4).
