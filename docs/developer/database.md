# Database

## Schema version

Constant: `EPC_DB_VERSION` (currently `1.1.0`)

Upgrades run on `EPC_DB::maybe_upgrade()` each request.

## Table: `{prefix}epc_passes`

Stores issued wallet passes.

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | Primary key |
| module | varchar | Module slug |
| source_id | bigint | Integration record id |
| entity_id | bigint | Product/level id |
| user_id | bigint | WordPress user |
| pass_uid | varchar | EpassCard pass UUID |
| pass_link | text | Public wallet URL |
| template_uid | varchar | Template UUID |
| status | varchar | `active`, `revoked` |
| meta | longtext | JSON metadata (e.g. `notifications_sent` for push dedupe) |
| created_at | datetime | |
| updated_at | datetime | |

Unique key: `(module, source_id)`

### Common queries

```php
EPC_DB::get_pass( $module, $source_id );
EPC_DB::get_active_passes_for_user( $user_id );
EPC_DB::query_passes( array(
    'module'   => 'memberpress',
    'status'   => 'active',
    'page'     => 1,
    'per_page' => 20,
) );
EPC_DB::upsert_pass( array( /* row */ ) );
```

## Table: `{prefix}epc_api_logs`

API request log (see user [api-log.md](../user/api-log.md)).

## Options

| Option | Purpose |
|--------|---------|
| `epc_connection_settings` | Encrypted API key, expiry, email |
| `epc_enabled_modules` | Array of enabled module slugs |
| `epc_pass_email_settings` | Email templates and flags |
| `epc_mappings_{slug}` | Per-module Pass Template mappings |
| `epc_{slug}_notification_rules` | Per-module push notification rules |
| `epc_db_version` | Installed schema version |

Mapping structure per entity id:

```php
array(
    'template_uid'  => 'uuid',
    'template_name' => 'VIP Pass',
    'template_id'   => 123,
    'field_mapping' => array(
        'field-uuid' => array( 'type' => 'source', 'source' => 'email' ),
    ),
    'pass_fields'   => array( /* snapshot */ ),
)
```

## Encryption

API keys encrypted via `EPC_Encryption` before storage in `epc_connection_settings`.

Always use `epc_get_api_key()` or `EPC_Connection::get_api_key()`.

## Activation

`EPC_Activator::activate()` runs `EPC_DB::install()`, schedules crons, registers WC rewrite endpoint, flushes rewrite rules.

## Bump schema

When adding tables/columns:

1. Update `EPC_DB::install()` / upgrade method
2. Increment `EPC_DB_VERSION` in `epasscard.php`
