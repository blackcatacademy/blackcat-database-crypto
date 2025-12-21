# CLI Overview

For the English version, see `CLI.md`.

| Command | Description |
| --- | --- |
| `bin/db-crypto-plan` | Validuje packages mapu (`blackcat-database/packages/*/schema/encryption-map.json`) a volitelně proti schématu (`--schema=path.json`, `--schema-source=packages` nebo `--dsn=...`). Volitelně `--tables=a,b` pro validaci subsetu. `--map=FILE` je určené jen pro tooling/debug. Exit code `2` značí varování. |
| `bin/db-crypto-schema` | Vytvoří snapshot ve formátu [docs/SCHEMA.cs.md](./SCHEMA.cs.md). Defaultně čte schema z `blackcat-database` packages (single source of truth), volitelně z live DB (`--source=db --dsn=...`). |
| `bin/db-crypto-keys-sync` | Sync `*_vN.key` soubory do tabulky `crypto_keys` (audit/inventář, příprava na rotace). |
| `bin/db-crypto-telemetry` | Vygeneruje JSON metriky z packages mapy (CI artefakt, rychlá kontrola coverage/strategií/encoding). |
| `bin/db-crypto-stress` | Transform-only stress/smoke nad packages mapou (bez DB). Dělá encrypt+decrypt, decrypt fallback (rotace) a HMAC verify. Pokud `BLACKCAT_KEYS_DIR` není nastaven, vygeneruje dočasné per-context klíče. |
| `bin/db-crypto-health` | Monitoring-friendly health report (JSON). Ověří mapu, (volitelně) dostupnost klíčů pro všechny kontexty, a udělá sample roundtrip encrypt/decrypt + fallback + HMAC verify. |

Pozn.: `--blackcat-db-root=DIR` vynutí, odkud se mají načíst packages (užitečné v CI/multi-checkout). `EncryptionMap::fromFile()` podporuje `includes` (tooling/testy).

