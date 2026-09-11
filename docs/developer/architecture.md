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
| `EPC_DB` | `epc_passes` table |
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
│   └── module-yith-gift-cards.php
├── admin/
│   ├── js/
│   ├── css/
│   └── views/
└── docs/
```

## Boot order

`plugins_loaded` (priority 20) → `EPC_Plugin::boot()`:

1. DB upgrade
2. API log, email, frontend, connection cron
3. User pass sync
4. Pass notifications
5. Admin menu
6. Load enabled modules

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
