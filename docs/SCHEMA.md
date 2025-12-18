# Schema Snapshot Format

`bin/db-crypto-plan` umí kromě manifestu porovnat mapu i se skutečným schématem. Kvůli jednoduchosti přijímá JSON:

```json
{
  "tables": {
    "users": ["id", "email_hash", "email_hash_key_version"],
    "orders": ["id", "encrypted_customer_blob", "encrypted_customer_blob_key_version", "encryption_meta"]
  }
}
```

Snapshot můžeš vygenerovat přímo přes `bin/db-crypto-schema` (defaultně ze schema/Definitions v `blackcat-database` packages – single source of truth), případně volitelně z live DB přes `--source=db --dsn=...`. `db-crypto-plan` pak upozorní na všechny šifrované sloupce, které ve schématu chybí, a vrátí exit code `2` pro snadnou integraci do CI.
