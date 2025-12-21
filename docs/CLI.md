# CLI Overview

| Command | Description |
| --- | --- |
| `bin/db-crypto-plan` | Validates the packages map (`blackcat-database/packages/*/schema/encryption-map.json`) and optionally validates it against schema (`--schema=path.json`, `--schema-source=packages` or `--dsn=...`). Use `--tables=a,b` to validate a subset. `--map=FILE` is intended only for tooling/debug. Exit code `2` indicates warnings. |
| `bin/db-crypto-schema` | Produces a schema snapshot in the format described in [SCHEMA.md](./SCHEMA.md). By default reads schema from `blackcat-database` packages (single source of truth); optionally from a live DB (`--source=db --dsn=...`). |
| `bin/db-crypto-keys-sync` | Syncs local `*_vN.key` files into the `crypto_keys` table (inventory/audit; baseline for rotations). |
| `bin/db-crypto-telemetry` | Generates JSON metrics from the packages map (CI artifact; quick coverage/strategy/encoding checks). |
| `bin/db-crypto-stress` | Transform-only stress/smoke using the packages map (no DB). Runs encrypt+decrypt, rotation fallback decrypt, and HMAC verification. If `BLACKCAT_KEYS_DIR` is missing, generates temporary per-context keys. |
| `bin/db-crypto-health` | Monitoring-friendly health report (JSON). Validates the map, optionally checks key availability for all contexts, and performs a sample encrypt/decrypt roundtrip + fallback + HMAC verify. |

Note: `--blackcat-db-root=DIR` forces where packages are loaded from (useful in CI/multi-checkout). `EncryptionMap::fromFile()` supports `includes` (tooling/tests).
