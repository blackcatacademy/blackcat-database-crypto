# blackcat-database-crypto – Roadmap

For the English version, see `ROADMAP.md`.

## Stage 1 – Adaptive Encryptor ✅
- [x] Konfigurovatelná mapa tabulek/sloupců (JSON nebo PHP array; YAML případně později).
- [x] `DatabaseCryptoAdapter` (encrypt + HMAC) delegující na libovolný gateway.
- [x] Legacy `PdoGateway` (nyní `@deprecated`) + unit testy pro `PayloadEncryptor` (pouze jako reference mimo ekosystém).
- [x] Manifest napojení (`BLACKCAT_CRYPTO_MANIFEST`) → shoda se zbytkem platformy.
- [x] Integrační test: `blackcat-database` `IngressLocator` boot + `encrypt()` (crypto ↔ database).
- [x] Read-side helper: `PayloadDecryptor` + `DatabaseIngressAdapter::decrypt()`.

## Stage 2 – Schema-Aware Diagnostics (current)
- `db-crypto-plan` validuje mapu oproti manifestu (`blackcat-crypto-manifests`) a umí validovat i proti schématu (snapshot `--schema`, nebo `--schema-source=packages` jako single source of truth; volitelně live DB přes `--dsn`).
- `db-crypto-schema` generuje snapshoty primárně z `blackcat-database` packages (Definitions), volitelně z live DB (`--source=db --dsn=...`) pro kontrolu instalace.
- `db-crypto-keys-sync` synchronizuje lokální `*_vN.key` soubory do DB tabulky `crypto_keys` (inventář/audit; baseline pro rotace).
- ✅ `db-crypto-telemetry` generuje JSON metriky mapy (coverage/strategie/kontexty) jako CI artefakt.
- ✅ CI gate: `phpstan` + `phpunit` + `db-crypto-plan` (schema-source=packages).
- ✅ Gateway `CoreDatabaseGateway` nad `BlackCat\Core\Database` (bez raw PDO; quoting + SQL comment).
- ✅ Volitelné write-path meta: `write_key_version` + `write_encryption_meta` (auto doplnění `*_key_version` a `encryption_meta`).
- ✅ Integrační test (skippable): `DatabaseIngressAdapter` ↔ generated repo (Orders) upsertByKeys + upsertManyRevive end-to-end.
- ✅ Integrace do ostatních repozitářů: `docs/INTEGRATIONS.cs.md` (např. `blackcat-auth` – criteria + zero‑boilerplate write‑path).
- ✅ Modularita mapy: `includes` (skládání více JSON map bez duplikace konfigurace).
- Pozn.: join/ops views pro crypto/KMS jsou řešené v `blackcat-database/views-library/crypto/joins-*.yaml` (single source of truth pro operace nad DB tabulkami).
- Linter pro PR (GitHub Action) – JSON schema + phpunit test pro mapu.
- Možnost označit sloupce jako `deterministic` (AEAD vs HMAC) podle potřeby indexů.

## Stage 3 – Transparent Query Hooks
- Deterministic query helper: `DatabaseIngressAdapter::criteria()` (HMAC-only) + `DatabaseIngressCriteriaAdapterInterface`; `GenericCrudService::upsertByKeys()`/`existsByKeys()` a generated repo `getByUnique()` transformují lookup klíče před dotazem.
- Middleware pro `blackcat-database` repositories (automatické zapojení do `BulkUpsertRepository`, `ContractRepository`).
- Eventy `beforeInsert`/`beforeUpdate` obohacené o `encryption_context` pro observabilitu.
- ✅ Podpora `decrypt()` helperů (např. pro audit logy, download endpoints).

## Stage 4 – Tokenization & Search
- Deterministické tokeny pro LIKE/ILIKE vyhledávání (kombinace HMAC + prefix tables).
- Bloom filter indexy pro anonymní vyhledávání.
- Pre/post hooks pro `blackcat-search` modul (automatické odmaskování při indexaci).

## Stage 5 – Runtime Governance
- Telemetrie (Prometheus) – počty zašifrovaných polí, chyby, vynechané mapy.
- Policy enforcement (napojení na `blackcat-governance`): „co se musí šifrovat“ vs. realita.
- CLI `db-crypto:enforce` – reencrypt existující data podle nové politiky (spolupráce s `vault:migrate`).

## Stage 6 – Multi-language SDK
- TypeScript + Go light-weight klienti sdílející stejnou mapu (`blackcat-crypto-manifests`).
- Declarativní kódgen (json-schema -> PHP trait pro repositories, TS decorators, etc.).

## Stage 7 – Secret-Aware Backups
- Adapter pro `blackcat-backup` – exportuje envelopes + manifest metainformace.
- Automatic wrap queue scheduling při obnově backupu (zajistí rewrap u odhalených klíčů).

## Stage 8 – Zero Trust DB Mesh
- Federované DB nody sdílející pouze encrypted payloady + policy handshake.
- Dynamic context negotiation (per tenant) – auto mapy podle `tenant.region` / `compliance profile`.
- Self-service CLI/portal pro security tým (audit, revoke, rewrap, compliance reporty).

## Stage 9 – Autonomous Observability
- Streaming audit feed do `blackcat-observability` (detekce ne-/zašifrovaných zápisů v reálném čase).
- Prometheus dashboard „encryption coverage“ + vzorkování plaintextů (bez hodnot, pouze meta).
- Hook do `blackcat-feedback` pro dev UX telemetry (kolik času ušetří automatické šifrování).

## Stage 10 – Intelligent Deidentification
- Strojové učení nad mapou (auto doporučení kontextů na základě datového profilu).
- Integrace s `blackcat-ai` pro generování anonymizovaných datasetů bez manuální konfigurace.
- „What-if“ simulátor – CLI `db-crypto:simulate anonymization` (počítá dopad na dotazy/indexy).

## Stage 11 – Runtime Policy Orchestrator
- Napojení na `blackcat-orchestrator` – při změně politiky se vyžádá rewrap/rehash, spustí se pipeline do `blackcat-crypto`.
- Webhooky do `blackcat-governance` pro approvals (např. povolení dočasného plaintext přístupu).
- Drift detection: porovná skutečnou DB (pg_dump) vs. mapu → auto ticket v `blackcat-support`.

## Stage 12 – Developer Delight / SDK Everywhere
- Jednotné SDK moduly (PHP, TS, Go, Rust) s generovanými typy + IDE helpers.
- VS Code / PHPStorm plugin: zvýrazní místa, kde chybí šifrovací strategie.
- `db-crypto playground` (web app) – prototypování mapy na sample datech, generování migration skriptů.

## Stage 13 – MPC & Threshold Enforcement (planned)
- Možnost přepnout mapu na threshold/MPC režim pro nejcitlivější sloupce (FROST/BLS podpisy pro audit).
- Recovery runbooky: air-gapped rewrap, split-key approvals (security + governance + ops).
- Validace konfigurace v CI s důrazem na „no-plaintext“ režim a zákazické BYOK scénáře.

## Stage 14 – Autonomous Compliance Mesh (exploratory)
- Auto-remediace driftu: watchdog porovná produkční DB se schválenou mapou, otevře ticket a spustí rewrap/anonymizaci.
- Continuous red-team simulace: syntetické útoky na tokeny/indexy, hodnocení odolnosti mapy.
- Multi-cloud handshake: konzistentní mapy pro regionální HA (PG/MySQL/MariaDB) + export do `blackcat-governance` a `blackcat-observability`.

## Stage 15 – Confidential Compute & Edge (planned)
- Integrace s TEE/HSM na okraji (edge nodes) – lokální šifrování bez úniku klíčů do cloudu, s atestačními tokeny.
- Regionální „split maps“ pro data residency: automaticky generované mapy podle tenant/region a compliance profilu.
- BYOK/BYO-KMS workflow pro zákazníky: registrace jejich klíčů/kms endpointů + validace politik v CI/CD.

## Stage 16 – Autonomous Residency & Recovery (exploratory)
- Multi-region failover s automatickým přepočtem map (rezidency-first) a MPC recovery scénáři pro kritické sloupce.
- Notarizované audit artefakty: Merkle log o šifrovacích operacích + push do SIEM / datagovernance.
- Self-healing režim: při detekci driftu spouští rewrap/reindex/token refresh a odkládá riskované dotazy (circuit breaker).

## Stage 17 – Privacy-Preserving Query Plane (future)
- Omezování plaintextu: deterministické tokeny + HE/TEE pro agregace bez dešifrování, předpřipravené pipeline do analytics.
- Policy-gated query execution: runtime check „kdo může co dešifrovat“ + audit trail per query.
- Anomální detekce na tokenech (frekvence/entropy) → auto-limity a rewrap na rizikových sloupcích.

## Stage 18 – Compliance Kits & Blueprints (future)
- Předpřipravené šablony pro PCI/HIPAA/NIS2: mapy, politiky, CI lint, runbooky.
- Auto-generované compliance reporty (rotace, coverage, drift) + exporty pro audity.
- Sandboxed „what-if“ simulátor: dopad politik na dotazy/indexy a provozní cost (pro bezpečnost i produkt).

## Stage 19 – Federated Clean Rooms & Synthetic Data (future)
- Integrace s privacy clean-room workflow: tokeny/envelopes kompatibilní s federovanými výpočty bez plaintextu.
- Synthetic data pipeline: generuje anonymizované datasety řízené mapou/politikou, s exporty pro vývoj/test/AI.
- SLA-aware governance: automatické rewrap/retoken při porušení limitů (čas, počet přístupů, partner trust).

## Stage 20 – Certifiable PQ & Disaster Readiness (future)
- PQ readiness kit: důkazy o rotacích, attestace TEE/HSM, simulace výpadků a automatické runbooky pro multi-cloud HA.
- Regionally-aware DR: mapy a tokeny přepočítané při failoveru, s Merkle auditem pro regulátory.
- Adaptive execution: volí strategii (deterministic tokeny vs TEE/HE) podle SLA, latency a compliance profilu.

## Stage 21 – ZK Enforcement & Least-Privilege Decryption (future)
- ZK ověření, že decrypt/search probíhá jen pro povolené role/politiky bez odhalení obsahu.
- Lease-based decryption: krátké „decrypt leases“ s auditními důkazy; automatické revokace a rewrap při anomáliích.
- Query intent signing: každá citlivá query podepsaná policy tokenem, ověřená před exekucí.

## Stage 22 – Resilience & Benchmark Suite (future)
- Standardizované testy: drift, latency, failover, token collision, search leakage – publikovaný scorecard.
- Chaos/DR scénáře pro mapy: automatické doporučení index/token strategií pro výkon i bezpečnost.
- Governance export: risk score a posture reporty pro SRE/Compliance, navázané na rotace a audit logy.

## Stage 23 – Policy-as-Code & Shadow Plans (future)
- Policy-as-code pro mapy: verifikovatelné balíčky (OPA/rego) s CI simulací dopadu na výkon, náklady a bezpečnost.
- „Shadow map“ režim: testuje nové tokenizační/šifrovací strategie paralelně a publikuje srovnávací metriky.
- Explainable planner: proč byla zvolená konkrétní strategie (deterministic vs TEE/HE), s odhadem rizika/cost.

## Stage 24 – AI-Augmented Governance (future)
- AI doporučení pro mapy: návrhy contextů/tokenizačních strategií podle datových profilů a incidentů.
- Predictive scaling a rewrap plánování na základě telemetry (load, drift, compliance events).
- Auto-runbooky a PRs do map/politik, včetně simulace dopadu a rollback scénářů.

