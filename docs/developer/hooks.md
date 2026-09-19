# Hooks reference

## Module registration

| Hook | Type | Description |
|------|------|-------------|
| `epc_module_files` | filter | `slug => filename` relative to `modules/` |
| `epc_module_classes` | filter | `slug => Class_Name` extending `EPC_Module` |
| `epc_module_path` | filter | Absolute path to module file `( $path, $slug, $file )` |

Helper: `epc_register_module( $slug, $file, $class_name )` in `epc-dependencies.php`.

## Mapping

| Hook | Type | Description |
|------|------|-------------|
| `epc_mapping_source_fields` | filter | Add source field options `( $fields, $slug, $module )` |
| `epc_mapping_modes` | filter | Add mapping modes `( $modes, $slug, $module )` |
| `epc_mappable_entities` | filter | Add/remove mappable plans `( $entities, $slug, $module )` |
| `epc_normalize_mapping_entry` | filter | Adjust entry before resolve `( $normalized, $raw, $pass_uid, $slug )` |
| `epc_resolve_mapped_value` | filter | Custom value resolution `( $value, $entry, $source_values, $slug )` |

## Pass sync lifecycle

| Hook | Type | Description |
|------|------|-------------|
| `epc_before_sync_pass` | action | Before API call `( $slug, $source_id, $entity_id, $user_id, $mapping, $source_values, $mode )` |
| `epc_pass_synced` | action | After success `( $slug, $source_id, $pass_row, $mode, $created )` |
| `epc_pass_created` | action | New pass only `( $slug, $source_id, $pass_row )` |
| `epc_pass_updated` | action | Update only `( $slug, $source_id, $pass_row )` |

`$mode` is `sync`, `create`, or `update`.

## Pass email

| Hook | Type | Description |
|------|------|-------------|
| `epc_pass_email_auto_send` | filter | `( $send, $mode, $pass_row )` |
| `epc_pass_email_subject` | filter | `( $subject, $pass_row, $replacements, $args )` |
| `epc_pass_email_body` | filter | `( $body, $pass_row, $replacements, $args )` |
| `epc_pass_email_headers` | filter | `( $headers, $pass_row, $args )` |
| `epc_before_pass_email_send` | action | `( $to, $subject, $body, $pass_row )` |
| `epc_pass_email_sent` | action | `( $to, $pass_row, $subject )` |
| `epc_pass_email_include_on_wc_order` | filter | `( $include, $order, $email )` |
| `epc_pass_email_wc_order_block` | filter | `( $text, $passes, $order )` |

## Frontend

| Hook | Type | Description |
|------|------|-------------|
| `epc_frontend_user_passes` | filter | `( $passes, $user_id )` |
| `epc_frontend_pass_label` | filter | Card title `( $title, $pass_row )` |
| `epc_enqueue_frontend_assets` | filter | `( $should_load )` |
| `epc_loyalty_my_account_data` | filter | Wallet payload `( $data, $user_id )` |
| `epc_loyalty_my_account_history_per_page` | filter | Ledger rows per page `( $per_page, $user_id )` |

## Push notifications

| Hook | Type | Description |
|------|------|-------------|
| `epc_pass_push_title` | filter | `( $title, $pass_row )` before API send |
| `epc_pass_push_message` | filter | `( $message, $pass_row )` before API send |
| `epc_send_push_notification_url` | filter | `( $url, $pass_uid )` |
| `epc_send_push_notification_body` | filter | `( $body, $pass_uid, $title, $message )` |

See [push-notifications.md](push-notifications.md) for API endpoint and module patterns.

## API connection

| Hook | Type | Description |
|------|------|-------------|
| `epc_api_base` | filter | API base URL |
| `epc_api_allowed_domains` | filter | Site origin for key generation |
| `epc_api_request_headers` | filter | `( $headers, $origin )` — includes `X-Request-Origin` |
| `epc_api_timezone` | filter | Timezone sent when generating keys |

## PHP helpers

```php
epc_get_api_key();              // Decrypted API key
epc_is_memberpress_active();
epc_is_woocommerce_subscriptions_active();
epc_is_ultimate_membership_pro_active();
epc_register_module( $slug, $file, $class );
epc_plugin()->get_module( $slug );
```

## AJAX actions (admin)

Per module slug `{slug}`:

- `epc_save_mapping_{slug}`
- `epc_pass_action_{slug}`
- `epc_send_pass_email_{slug}`
- `epc_send_test_push_{slug}`

Global:

- `epc_get_templates`, `epc_get_pass_fields`
- `epc_connect_api_key`, `epc_disconnect`, `epc_save_modules`

Nonce: `epc_admin` (modules), `epc_connection` (connection page).
