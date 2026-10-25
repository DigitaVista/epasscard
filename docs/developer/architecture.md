# Architecture

## Overview

```
WordPress (membership / events / gift cards plugin)
        │
        ▼
  EPC_Module (integration)
    │  hooks: create, renew, status change, reminders
        ▼
  EPC_Pass_Service::sync_pass()
        │  mapping + source values → API payload
        ▼
  EPC_Api_Client → EpassCard REST API
        │
        ▼
  EPC_DB (epc_passes, epc_api_logs)
```

## Key components

| Class | Role |
|-------|------|
| `EPC_Plugin` | Bootstrap, module registry |
| `EPC_Module_Loader` | Load module files and classes |
| `EPC_Module` | Abstract integration (mapping UI, hooks) |
| `EPC_Pass_Service` | Create/update passes from mappings |
| `EPC_Api_Client` | HTTP client, templates, pass CRUD |
| `EPC_Loyalty_Pass_Design_Service` | Loyalty StoreCard designer → API v2 create/update |
| `EPC_DB` | `epc_passes` table; `epc_form_submissions` (DB 1.7.0): the card values of form submissions, for form plugins that keep no entries |
| `EPC_Api_Log` | Request/response logging |
| `EPC_Pass_Email` | `wp_mail` delivery |
| `EPC_Pass_Notifications` | Push notification cron, dedupe, send helpers |
| `EPC_Frontend` | My Account, shortcode |
| `EPC_Connection` | Encrypted API key storage |

## Module lifecycle

1. `epc_module_files` / `epc_module_classes` define available modules.
2. `EPC_Module_Settings` stores enabled slugs in `epc_enabled_modules`.
3. Enabled modules call `init()` → register admin UI + `register_event_hooks()`.

## Pass identity

A pass row is keyed by:

- `module` — slug (e.g. `memberpress`, `event_tickets`, `pw_gift_cards`)
- `source_id` — integration-specific record id (subscription id, attendee id, booking id, gift card id, etc.)
- `entity_id` — product/level/ticket/event id
- `user_id` — WordPress user (when available)

## Prefixes

- Functions: `epc_*`
- Classes: `EPC_*`
- Options: `epc_*`
- Tables: `epc_*`

## File layout

```
epasscard/
├── epasscard.php
├── includes/
│   ├── abstract-class-epc-module.php
│   ├── class-epc-*.php
│   └── epc-dependencies.php
├── modules/
│   ├── module-memberpress.php
│   ├── module-paid-memberships-pro.php
│   ├── module-simple-membership.php
│   ├── module-ultimate-membership-pro.php
│   ├── module-woocommerce-subscriptions.php
│   ├── module-the-events-calendar.php
│   ├── module-events-manager.php
│   ├── module-pw-gift-cards.php
│   ├── module-yith-gift-cards.php
│   ├── module-woocommerce-tickets.php, -vouchers.php, -product-cards.php
│   ├── module-ultimate-member.php
│   ├── module-wployalty.php
│   ├── module-wpforms.php
│   ├── abstract-module-amelia.php
│   ├── module-amelia-appointments.php, module-amelia-events.php
│   └── module-tickera.php
├── admin/
│   ├── js/
│   ├── css/
│   └── views/
└── docs/
```

## Boot order

`epasscard.php` loads only `EPC_Plugin`, `EPC_Autoloader` and the helper functions
(`includes/epc-dependencies.php`). Every other class is loaded the first time it is used
(`EPC_Autoloader::CLASSES` maps class → file; `tests/integration/test-autoload.php` keeps the map
in step with the code). Integration modules load only when enabled (`EPC_Module_Loader`).

`plugins_loaded` (priority 20) → `EPC_Plugin::boot()`:

1. DB upgrade (only when the stored `epc_db_version` is older)
2. Every request: pass email, front-end links, user profile sync, pass status, default design;
   privacy and REST hooks registered by class name (the class loads when the hook runs)
3. wp-admin, WP-Cron and WP-CLI only (`EPC_Plugin::is_background_request()`): API log, connection
   and notification crons, pass issues, retry and existing-customer jobs
4. wp-admin only (`is_admin()`, which includes admin-ajax.php and admin-post.php): the admin
   screens, setup wizard, template starter and support chat
5. Load enabled modules

A front-end page with one integration enabled loads about 16 of the plugin's files (≈230 KB of
PHP); before 1.2.0 it was every file (≈1.2 MB). New classes go in `EPC_Autoloader::CLASSES`; hooks
that must run on front-end requests must not be registered only from the admin group.

Register custom modules on `plugins_loaded` priority **10** or earlier.

## Push notification flow

```
MemberPress / WCS reminder hook     Daily cron (UMP, PMPro, Simple Membership,
        │                           TEC, Events Manager, PW, YITH)
        │                                        │
        ▼                                        ▼
  EPC_Module::build_push_notification_copy()
        │
        ▼
  EPC_Pass_Notifications::send_for_pass()
        │
        ▼
  EPC_Api_Client::send_pass_push()
        POST /send-pass-notification/{passUuid}
        body: { "message": "title\\n\\nbody" }
```

See [push-notifications.md](push-notifications.md) for hooks and extension patterns.
