# blackcat-database-crypto – Roadmap

## Stage 1 – Adaptive Encryptor ✅
- [x] Configurable table/column map (JSON or PHP array; YAML later).
- [x] `DatabaseCryptoAdapter` (encrypt + HMAC) delegating to any gateway.
- [x] Legacy `PdoGateway` (now `@deprecated`) + unit tests for `PayloadEncryptor` (reference only; not used in the ecosystem).
- [x] Manifest integration (runtime config `crypto.manifest`) to match the rest of the platform.
- [x] Integration test: `blackcat-database` `IngressLocator` boot + `encrypt()` (crypto ↔ database).
- [x] Read-side helper: `PayloadDecryptor` + `DatabaseIngressAdapter::decrypt()`.

## Stage 2 – Schema-Aware Diagnostics (current)
- `blackcat db-crypto plan` validates the map against the manifest (`blackcat-crypto-manifests`) and can also validate against schema (snapshot `--schema`, or `--schema-source=packages` as single source of truth; optional live DB via `--dsn`).
- `blackcat db-crypto schema` generates snapshots primarily from `blackcat-database` packages (Definitions), optionally from a live DB (`--source=db --dsn=...`) to verify installation.
- `blackcat db-crypto keys-sync` syncs local key material into the DB table `crypto_keys` (inventory/audit; baseline for rotations).
- ✅ `blackcat db-crypto telemetry` generates JSON map metrics (coverage/strategies/contexts) as a CI artifact.
- ✅ CI gate: `phpstan` + `phpunit` + `blackcat db-crypto plan` (`--schema-source=packages`).
- ✅ Gateway `CoreDatabaseGateway` over `BlackCat\Core\Database` (no raw PDO; quoting + SQL comment).
- ✅ Optional write-path metadata: `write_key_version` + `write_encryption_meta` (auto-fill `*_key_version` and `encryption_meta`).
- ✅ Integration test: `DatabaseIngressAdapter` ↔ generated repo (Orders) upsertByKeys + upsertManyRevive end-to-end (runs against the Docker test DB by default).
- ✅ Integration notes for other repositories: `docs/INTEGRATIONS.md` (e.g. `blackcat-auth` — criteria + zero-boilerplate write path).
- ✅ Map modularity: `includes` (compose multiple JSON maps without duplicating configuration).
- Note: join/ops views for crypto/KMS are defined in `blackcat-database/views-library/crypto/joins-*.yaml` (single source of truth for operational queries across DB tables).
- PR linter (GitHub Action) — JSON schema + phpunit test for the map.
- Option to mark columns as `deterministic` (AEAD vs HMAC) depending on index needs.

## Stage 3 – Transparent Query Hooks
- Deterministic query helper: `DatabaseIngressAdapter::criteria()` (HMAC-only) + `DatabaseIngressCriteriaAdapterInterface`; `GenericCrudService::upsertByKeys()`/`existsByKeys()` and generated repo `getByUnique()` transform lookup keys before the query.
- Middleware for `blackcat-database` repositories (auto-wiring into `BulkUpsertRepository`, `ContractRepository`).
- `beforeInsert`/`beforeUpdate` events enriched with `encryption_context` for observability.
- ✅ Support `decrypt()` helpers (e.g. for audit logs, download endpoints).

## Stage 4 – Tokenization & Search
- Deterministic tokens for LIKE/ILIKE search (HMAC + prefix tables).
- Bloom filter indexes for anonymous searching.
- Pre/post hooks for the `blackcat-search` module (automatic unmasking during indexing).

## Stage 5 – Runtime Governance
- Telemetry (Prometheus) — counts of encrypted fields, errors, missing maps.
- Policy enforcement (integration with `blackcat-governance`): “what must be encrypted” vs. reality.
- CLI `db-crypto:enforce` — re-encrypt existing data according to a new policy (works with `vault:migrate`).

## Stage 6 – Multi-language SDK
- TypeScript + Go lightweight clients sharing the same map (`blackcat-crypto-manifests`).
- Declarative codegen (json-schema -> PHP trait for repositories, TS decorators, etc.).

## Stage 7 – Secret-Aware Backups
- Adapter for `blackcat-backup` — exports envelopes + manifest metadata.
- Automatic wrap queue scheduling during restore (ensures rewrap for exposed keys).

## Stage 8 – Zero Trust DB Mesh
- Federated DB nodes sharing only encrypted payloads + policy handshake.
- Dynamic context negotiation (per tenant) — auto maps based on `tenant.region` / `compliance profile`.
- Self-service CLI/portal for security teams (audit, revoke, rewrap, compliance reports).

## Stage 9 – Autonomous Observability
- Streaming audit feed into `blackcat-observability` (real-time detection of encrypted/non-encrypted writes).
- Prometheus dashboard “encryption coverage” + plaintext sampling (no values, metadata only).
- Hook into `blackcat-feedback` for developer UX telemetry (how much time transparent crypto saves).

## Stage 10 – Intelligent Deidentification
- ML over the map (auto-recommend contexts based on data profiles).
- Integration with `blackcat-ai` to generate anonymized datasets without manual configuration.
- “What-if” simulator — CLI `db-crypto:simulate anonymization` (estimates impact on queries/indexes).

## Stage 11 – Runtime Policy Orchestrator
- Integration with `blackcat-orchestrator` — policy changes request rewrap/rehash and trigger a pipeline into `blackcat-crypto`.
- Webhooks into `blackcat-governance` for approvals (e.g. temporary plaintext access).
- Drift detection: compare actual DB (pg_dump) vs. the map → auto ticket in `blackcat-support`.

## Stage 12 – Developer Delight / SDK Everywhere
- Unified SDK modules (PHP, TS, Go, Rust) with generated types + IDE helpers.
- VS Code / PHPStorm plugin: highlights missing encryption strategies.
- `db-crypto playground` (web app) — prototype maps on sample data, generate migration scripts.

## Stage 13 – MPC & Threshold Enforcement (planned)
- Option to switch the map to threshold/MPC mode for the most sensitive columns (FROST/BLS signatures for audit).
- Recovery runbooks: air-gapped rewrap, split-key approvals (security + governance + ops).
- CI config validation with emphasis on “no-plaintext” mode and strict BYOK scenarios.

## Stage 14 – Autonomous Compliance Mesh (exploratory)
- Auto-remediation of drift: watchdog compares production DB to the approved map, opens a ticket, and triggers rewrap/anonymization.
- Continuous red-team simulations: synthetic attacks on tokens/indexes; scoring map resilience.
- Multi-cloud handshake: consistent maps for regional HA (PG/MySQL/MariaDB) + export into `blackcat-governance` and `blackcat-observability`.

## Stage 15 – Confidential Compute & Edge (planned)
- Integration with TEE/HSM at the edge (edge nodes) — local encryption without leaking keys to the cloud, with attestation tokens.
- Regional “split maps” for data residency: auto-generated maps based on tenant/region and compliance profile.
- BYOK/BYO-KMS workflow for customers: register their keys/KMS endpoints + validate policies in CI/CD.

## Stage 16 – Autonomous Residency & Recovery (exploratory)
- Multi-region failover with automatic map recomputation (residency-first) and MPC recovery scenarios for critical columns.
- Notarized audit artifacts: Merkle log of crypto operations + push to SIEM/data governance.
- Self-healing mode: on drift detection triggers rewrap/reindex/token refresh and defers risky queries (circuit breaker).

## Stage 17 – Privacy-Preserving Query Plane (future)
- Plaintext minimization: deterministic tokens + HE/TEE for aggregates without decryption; prebuilt pipelines into analytics.
- Policy-gated query execution: runtime check “who can decrypt what” + per-query audit trail.
- Anomaly detection on tokens (frequency/entropy) → auto-limits and rewrap on risky columns.

## Stage 18 – Compliance Kits & Blueprints (future)
- Prebuilt kits for PCI/HIPAA/NIS2: maps, policies, CI lint, runbooks.
- Auto-generated compliance reports (rotation, coverage, drift) + exports for audits.
- Sandboxed “what-if” simulator: impact of policies on queries/indexes and operational cost (security + product).

## Stage 19 – Federated Clean Rooms & Synthetic Data (future)
- Integration with privacy clean-room workflows: tokens/envelopes compatible with federated computation without plaintext.
- Synthetic data pipeline: generates anonymized datasets driven by the map/policy, with exports for dev/test/AI.
- SLA-aware governance: automatic rewrap/retoken on limit breaches (time, number of accesses, partner trust).

## Stage 20 – Certifiable PQ & Disaster Readiness (future)
- PQ readiness kit: rotation proofs, TEE/HSM attestations, outage simulations, and automatic runbooks for multi-cloud HA.
- Region-aware DR: maps and tokens recomputed during failover, with Merkle audit for regulators.
- Adaptive execution: chooses strategy (deterministic tokens vs TEE/HE) based on SLA, latency, and compliance profile.

## Stage 21 – ZK Enforcement & Least-Privilege Decryption (future)
- ZK verification that decrypt/search runs only for allowed roles/policies without revealing content.
- Lease-based decryption: short “decrypt leases” with audit proofs; automatic revocations and rewrap on anomalies.
- Query intent signing: every sensitive query signed by a policy token and verified before execution.

## Stage 22 – Resilience & Benchmark Suite (future)
- Standardized tests: drift, latency, failover, token collision, search leakage — published scorecard.
- Chaos/DR scenarios for maps: automatic recommendations for index/token strategies for performance and security.
- Governance export: risk score and posture reports for SRE/Compliance, tied to rotations and audit logs.

## Stage 23 – Policy-as-Code & Shadow Plans (future)
- Policy-as-code for maps: verifiable bundles (OPA/rego) with CI simulation of performance/cost/security impact.
- “Shadow map” mode: tests new tokenization/encryption strategies in parallel and publishes comparative metrics.
- Explainable planner: why a given strategy was chosen (deterministic vs TEE/HE), with risk/cost estimates.

## Stage 24 – AI-Augmented Governance (future)
- AI recommendations for maps: propose contexts/tokenization strategies based on data profiles and incidents.
- Predictive scaling and rewrap planning based on telemetry (load, drift, compliance events).
- Auto-runbooks and PRs for maps/policies, including impact simulation and rollback scenarios.
