# CLI Overview

| Command | Description |
| --- | --- |
| `bin/db-crypto-plan` | Validuje `config/encryption.*` proti manifestu a volitelně proti schématu (`--schema=path.json`, `--schema-source=packages` nebo `--dsn=...`). Exit code `2` značí varování. |
| `bin/db-crypto-schema` | Vytvoří snapshot ve formátu [docs/SCHEMA.md](./SCHEMA.md). Defaultně čte schema z `blackcat-database` packages (single source of truth), volitelně z live DB (`--source=db --dsn=...`). |
| `bin/db-crypto-keys-sync` | Sync `*_vN.key` soubory do tabulky `crypto_keys` (audit/inventář, příprava na rotace). |
| `bin/db-crypto-telemetry` | Vygeneruje JSON metriky z encryption mapy (CI artefakt, rychlá kontrola coverage/strategií/encoding). |

Pozn.: `EncryptionMap::fromFile()` podporuje `includes` (skládání více JSON map).
