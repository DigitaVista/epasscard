# Integrations

EpassCard ships with built-in integration modules. Each extends the same workflow: **map template → issue pass → sync on changes**.

## MemberPress

**Menu:** EpassCard → MemberPress

- Maps **membership products** to pass templates.
- Issues passes for active memberships/subscriptions.
- **Members list:** Create/Update pass column actions.
- **MemberPress account:** **Wallet Passes** tab for members.
- **Push reminders:** Subscription expire, renewal, trial, card expire (uses MemberPress reminder timing).

## Paid Memberships Pro

**Menu:** EpassCard → Paid Memberships Pro

- Maps **membership levels** to pass templates.
- Syncs on level assign / checkout; revoke on cancel or expire (configurable per status).
- **Push reminders:** Before level expires (EpassCard daily cron + **Days before** setting).

## Simple Membership

**Menu:** EpassCard → Simple Membership

- Maps **membership levels** to pass templates.
- Syncs on registration, level change, payment upgrade/renewal, and account-state updates.
- **Push reminders:** Before membership expires (EpassCard daily cron + **Days before** setting).

## The Events Calendar

**Menu:** EpassCard → The Events Calendar

- Maps **events** (`tribe_events`) to pass templates.
- Issues passes when **Event Tickets** (TEC add-on) attendees register for a mapped event (RSVP / Tickets Commerce / Woo).
- Includes venue, organizer, cost, and event URL source fields.
- Syncs on attendee create / update; revoke on delete (configurable per status).
- **Attendees list** (`Tickets → Attendees` / `tec-tickets-attendees`): **EpassCard** column with Create / Update / View pass and Email pass link; also under ticket row actions.
- **Push reminders:** Before event starts (EpassCard daily cron + **Days before** setting).
- Requires **Event Tickets** active for automatic issuance (calendar alone has no attendees). There is no separate EpassCard “Event Tickets” module — one path only, so each attendee gets a single pass.

## Events Manager

**Menu:** EpassCard → Events Manager

- Maps **events** to pass templates.
- Syncs on booking add / status change; revoke on cancel, reject, or delete (configurable).
- **Bookings list** (`Events → Bookings`): **EpassCard** column with Create / Update / View pass (and Email pass link when issued); also under the row Actions menu.
- **Push reminders:** Before event starts (EpassCard daily cron + **Days before** setting).

## PW Gift Cards

**Menu:** EpassCard → PW Gift Cards

- Maps **PW gift card products** to pass templates.
- Syncs when cards are created, balance changes, or cards are reactivated; revoke on deactivate / expire (configurable).
- **Push reminders:** Before gift card expires (EpassCard daily cron + **Days before** setting).

## YITH Gift Cards

**Menu:** EpassCard → YITH Gift Cards

- Maps **YITH gift card products** to pass templates.
- Syncs on card generation / save and balance updates; revoke on disable, dismiss, trash, or expire (configurable).
- **Push reminders:** Before gift card expires (EpassCard daily cron + **Days before** setting).

## WooCommerce Subscriptions

**Menu:** EpassCard → WooCommerce Subscriptions

- Maps **subscription products** to pass templates.
- Issues passes per subscription record.
- **Subscriptions list:** Row actions and pass column.
- **WooCommerce My Account:** **Wallet passes** endpoint.
- **Order emails:** Optional pass link block (Connection settings).
- **Push reminders:** Renewal, trial end, subscription end (uses WCS reminder timing).

## Ultimate Membership Pro

**Menu:** EpassCard → Ultimate Membership Pro

- Maps **membership levels** to pass templates.
- Issues passes per user-level assignment (`ihc_user_levels` record).
- **Users list** (`ihc_manage` → Users): Pass actions column.
- **Push reminders:** Before level expires (EpassCard daily cron + **Days before** setting).

## Enabling / disabling

Integrations are toggled on the **Connection** page. Disabled modules do not load hooks or appear in the admin menu.

## Per-module settings

Each integration page may include:

- **Pass Template mapping** — Required before passes can be issued.
- **Issued passes** — Searchable list with manual actions.
- **Push notification rules** — Custom title/message per reminder type.
- **Test notification** — Send a test push to a specific pass.

See [mapping.md](mapping.md) and [notifications.md](notifications.md) for details.
