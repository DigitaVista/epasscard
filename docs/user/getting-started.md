# Getting started

## Requirements

- WordPress 6.5+
- PHP 8.1+
- An [EpassCard](https://app.epasscard.com) account with at least one pass template
- At least one supported plugin:
  - **Membership:** MemberPress, Paid Memberships Pro, Simple Membership, Ultimate Membership Pro
  - **Subscriptions:** WooCommerce Subscriptions
  - **Events:** The Events Calendar (with Event Tickets add-on), Events Manager
  - **Gift cards:** PW WooCommerce Gift Cards, YITH WooCommerce Gift Cards

## Installation

1. Upload the `epasscard` folder to `wp-content/plugins/` or install from your distribution package.
2. Activate **EpassCard** in **Plugins**.
3. Open **EpassCard → Setup Wizard** (recommended) or **EpassCard → Connection**.

## Basic setup (5 steps)

1. **Connect** — Paste your API key or sign in with EpassCard credentials to generate one.
2. **Enable integrations** — On the Connection page, check the plugins you use and click **Save integrations**.
3. **Open an integration** — e.g. **EpassCard → MemberPress** or **EpassCard → The Events Calendar**.
4. **Map templates** — For each product, level, ticket, event, or gift card product, choose a pass template and map fields.
5. **Issue passes** — Passes are created automatically when members subscribe, attendees register, or gift cards are issued (per module rules), or manually via **Create pass**.

## What happens after setup

- When a record qualifies, EpassCard creates a wallet pass via the API and stores the link locally.
- Profile / status changes can update or revoke existing passes (see each integration).
- Optional reminder pushes use native plugin timing (MemberPress, WooCommerce Subscriptions) or EpassCard’s daily cron (other modules).
- Pass links can be emailed automatically, included in WooCommerce order emails, or viewed in My Account / MemberPress account where supported.

## Getting help

- **Help** in the EpassCard top bar lists the tutorials and guides.
- **Chat with us** (next to Help) opens a chat with EpassCard support. The dot is green during support hours (10:00–19:00 Bangladesh time, Saturday to Thursday); outside them, leave a message and we reply by email. Nothing is loaded or sent until you start a chat; the panel shows what is sent (your name and email, site address, versions, EpassCard account and plan, integrations, number of designs and cards, the screen you are on and the last failed call, never the API key or your customers' data). Hide the button for yourself under Settings → Advanced. Developers can turn it off for the whole site with the `epc_support_chat_enabled` filter.

## Next steps

- [Connection & email settings](connection.md)
- [Integrations](integrations.md)
- [Pass Template mapping](mapping.md)
- [Passes & email delivery](passes-and-email.md)
