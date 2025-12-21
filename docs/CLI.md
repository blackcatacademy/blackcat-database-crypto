# CLI Overview

| Command | Description |
| --- | --- |
| `blackcat db-crypto plan` | Validates the packages map (`blackcat-database/packages/*/schema/encryption-map.json`) and optionally validates it against schema (`--schema=path.json`, `--schema-source=packages` or `--dsn=...`). Use `--tables=a,b` to validate a subset. `--map=FILE` is intended only for tooling/debug. Exit code `2` indicates warnings. |
| `blackcat db-crypto schema` | Produces a schema snapshot in the format described in [SCHEMA.md](./SCHEMA.md). By default reads schema from `blackcat-database` packages (single source of truth); optionally from a live DB (`--source=db --dsn=...`). |
| `blackcat db-crypto keys-sync` | Syncs local key material into the `crypto_keys` table (inventory/audit; baseline for rotations). |
| `blackcat db-crypto telemetry` | Generates JSON metrics from the packages map (CI artifact; quick coverage/strategy/encoding checks). |
| `blackcat db-crypto stress` | Transform-only stress/smoke using the packages map (no DB). Runs encrypt+decrypt, rotation fallback decrypt, and HMAC verification. |
| `blackcat db-crypto health` | Monitoring-friendly health report (JSON). Validates the map, optionally checks key availability for all contexts, and performs a sample encrypt/decrypt roundtrip + fallback + HMAC verify. |

Note:
- `--blackcat-db-root=DIR` forces where packages are loaded from (useful in CI/multi-checkout).
- Crypto paths are read from runtime config (`crypto.keys_dir`, `crypto.manifest`) or passed explicitly via `--keys-dir` / `--manifest` / `--config=...`.
