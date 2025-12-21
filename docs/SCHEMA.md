# Schema Snapshot Format

`bin/db-crypto-plan` can validate the map not only against the manifest, but also against the actual schema. For simplicity it accepts JSON:

```json
{
  "tables": {
    "users": ["id", "email_hash", "email_hash_key_version"],
    "orders": ["id", "encrypted_customer_blob", "encrypted_customer_blob_key_version", "encryption_meta"]
  }
}
```

You can generate a snapshot via `bin/db-crypto-schema` (by default from schema/Definitions in `blackcat-database` packages – the single source of truth), or optionally from a live DB via `--source=db --dsn=...`. `db-crypto-plan` then reports encrypted columns missing from the schema and returns exit code `2` for easy CI gating.
