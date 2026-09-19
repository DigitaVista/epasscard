# EpassCard documentation

Documentation for the **EpassCard** WordPress plugin.

## Browse documentation (HTML)

Open **[docs/index.html](index.html)** in your browser for the full styled documentation site.

- **User guides:** `docs/user/*.html`
- **Developer guides:** `docs/developer/*.html`
- **Assets:** `docs/assets/css/docs.css`, `docs/assets/js/docs.js`

Markdown sources remain in this folder for editing and version control.

## User guides (Markdown)

| Topic | Description |
|-------|-------------|
| [Getting started](user/getting-started.md) | Install, connect, enable integrations |
| [Onboarding flow](user/onboarding-flow.md) | First-run path from install to first wallet pass |
| [Connection](user/connection.md) | API key, integrations, pass email settings |
| [Integrations](user/integrations.md) | All modules: membership, WCS, events, gift cards |
| [Template mapping](user/mapping.md) | Map membership data to pass fields |
| [Passes & email](user/passes-and-email.md) | Issue passes, send links, member access |
| [Push notifications](user/notifications.md) | Wallet push reminders, timing, test tool |
| [API log](user/api-log.md) | Debug API requests |
| [My Account & shortcode](user/my-account.md) | Frontend pass access for members |

## Developer guides

| Topic | Description |
|-------|-------------|
| [Architecture](developer/architecture.md) | Plugin structure and data flow |
| [Hooks reference](developer/hooks.md) | Actions and filters |
| [Custom modules](developer/custom-modules.md) | Add new integrations |
| [Mapping extensions](developer/mapping-extensions.md) | Extra source fields and mapping modes |
| [Pass email API](developer/pass-email.md) | Programmatic email delivery |
| [Database](developer/database.md) | Custom tables and options |
| [Push notifications](developer/push-notifications.md) | Push API, cron, module extension |
| [Loyalty template API v2](developer/loyalty-template-api-v2.md) | Reviewed live v2 create/read + specified update contract |

## Quick links

- Plugin admin: **WordPress Admin → EpassCard**
- EpassCard app: [app.epasscard.com](https://app.epasscard.com)
- Helper: `epc_get_api_key()` for custom API usage
