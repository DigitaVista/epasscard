=== EpassCard (Google Wallet, Apple Wallet, and more) WooCommerce Loyalty, Membership, Event pass and Gift voucher pass generator  ===
Contributors: wooxperto, hasan350
Tags: Apple Wallet, Google Wallet, Wallet Pass, Membership, WooCommerce Loyalty
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create Apple & Google Wallet passes from WordPress for memberships, subscriptions, events, gift cards, and more with seamless integrations and automation using EpassCard.

== Description ==

**EpassCard** is a **WordPress digital wallet pass plugin** that connects your site to [EpassCard](https://epasscard.com/) and automatically issues **Apple Wallet** and **Google Wallet** passes for membership, event tickets, loyalty points, subscriptions, etc.

[youtube https://youtu.be/-arN8Db3fjA]

**One Platform. Endless Possibilities for Digital Passes.**
Loyalty/reward cards. Stamp Cards. Membership Cards. Event Passes. Employee /staff Cards. Gift Cards. Warranty Cards. Digital business Cards (vCard). Reservation Passes. Boarding Passes. And More


[**Documentation**](https://epasscard.com/wp-plugin/docs/) | [**USER GUIDE**](https://epasscard.com/wp-plugin/docs/user/getting-started) | [**DEVELOPER GUIDE**](https://epasscard.com/wp-plugin/docs/developer/architecture)

= Wallet pass generator and management for WordPress =

EpassCard is a **wallet pass solution** built for WordPress membership and subscription sites. It handles **pass creation**, **pass updates**, **pass distribution** (email links and member account pages), and **wallet pass notifications** (push reminders) — so members always have an up-to-date pass on their phone.

Supported pass platforms:

* **Apple Wallet** — `.pkpass` files for iPhone, iPad, and Apple Watch
* **Google Wallet** — digital passes for Android devices

= Digital membership cards =

Turn memberships into **digital membership cards** members save to their phone wallet.

* **MemberPress Apple Wallet and Google Wallet** — issue a **membership wallet pass** on signup, renewal, and profile updates
* **Paid Memberships Pro** — issue wallet passes when a member level is assigned, renewed, cancelled, or expires
* **Simple Membership** — issue wallet passes when members register, change level, or account state updates
* **WooCommerce Subscriptions wallet pass** — create a **subscription wallet pass** when a subscription activates or renews
* **Ultimate Membership Pro** — issue wallet passes for UMP members with the same template-mapping workflow
* **The Events Calendar** — map events to pass templates; issue wallet passes for Event Tickets (add-on) attendees on those events
* **Events Manager** — issue wallet passes when bookings are approved or status changes
* **PW Gift Cards** — issue wallet passes when PW gift cards are created or balance changes
* **YITH Gift Cards** — issue wallet passes when YITH gift cards are generated or updated

Map name, plan, expiration date, QR codes, barcodes, and custom fields from your membership, event, or gift card plugin to your pass template.

= Loyalty cards, event tickets, and more =

Because passes are designed in EpassCard, you are not limited to membership cards. Use the same WordPress integration to distribute:

* **Digital loyalty cards** and rewards passes
* **Event tickets** and conference badges
* **Employee ID cards** and visitor passes
* **Student ID** and campus cards
* **Digital coupons**, gift cards, and vouchers
* **QR code wallet passes** and barcode passes

If your EpassCard template supports it, EpassCard for WordPress can issue and update it automatically.

= PassKit alternative for WordPress =

Looking for a **PassKit alternative**, **PassCreator alternative**, or **wallet pass software** that works natively inside WordPress?

EpassCard is a **wallet pass platform** connector — not a middleware automation tool. MemberPress, Ultimate Membership Pro, and WooCommerce Subscriptions hooks are built in. Connect your API key, map templates, and passes are created when membership events fire.

= Features =

* **Apple Wallet and Google Wallet** — one integration, both mobile wallet platforms
* **PKPass support** — standard Apple Wallet `.pkpass` format
* **Automatic wallet pass issuance** — on signup, renewal, and mapped data changes
* **Pass updates** — keep wallet passes in sync when membership data changes
* **Pass Template mapping** — map membership fields to pass template fields (including QR and barcode fields)
* **Push notifications** — wallet pass reminders aligned with membership timing
* **Modular integrations** — MemberPress, Paid Memberships Pro, Simple Membership, Ultimate Membership Pro, WooCommerce Subscriptions, The Events Calendar, Events Manager, PW Gift Cards, YITH Gift Cards
* **Admin pass list** — searchable table of issued passes and delivery links
* **Member account access** — members open passes from their account area
* **API connection** — API key or email/password sign-in to the EpassCard pass API

= Who is this for? =

* **Membership sites** — associations, clubs, gyms, and online courses using MemberPress or UMP
* **Subscription businesses** — WooCommerce Subscriptions stores that want a **digital card** on the customer's phone
* **Event and conference organizers** — attendee / booking wallet passes via The Events Calendar (with Event Tickets) or Events Manager
* **Gift card stores** — PW or YITH WooCommerce gift cards as Apple/Google Wallet passes
* **Agencies** — WordPress developers who need a reliable **wallet pass integration** without building a custom pkpass server
What’s New
In built Loyalty system for WooCommerce store with Wallet Support.
Roadmap

= Requirements =

* An [EpassCard](https://epasscard.com/) account (sign up at [app.epasscard.com](https://app.epasscard.com))
* A supported integration plugin (MemberPress, Paid Memberships Pro, Simple Membership, Ultimate Membership Pro, WooCommerce Subscriptions, The Events Calendar + Event Tickets, Events Manager, PW Gift Cards, and/or YITH Gift Cards)

= External services =

This plugin relies on the following third-party services. Nothing is sent to them until you use the related feature.

**EpassCard API** (`https://api.epasscard.com`) — the service that creates and delivers the wallet passes.

* When you sign up from the plugin, your name and email address are sent to create an EpassCard account.
* When you sign in, your EpassCard email and password are sent once to generate an API key, together with your site name, site address (used to lock the key to your domain) and timezone. The password is not stored by the plugin.
* When you connect, refresh or renew an API key, the key is sent for validation.
* When passes are created, updated, expired or notified, the pass field values you map (for example member name, email, membership level, expiry date, ticket or gift card details, loyalty points) are sent, along with your site address in request headers.
* Pass templates and template fields are read from your EpassCard account.
* EpassCard [Terms and Conditions](https://www.epasscard.com/terms-and-conditions/) and [Privacy Policy](https://www.epasscard.com/privacy-policy/).

**WebCartisan plugin catalog** (`https://webcartisan.com`) — the "Our Products" section on the EpassCard Connection screen loads a list of our other plugins from `https://webcartisan.com/wp-json/webcartisan/v1/catalog`. Only the plugin slug (`epasscard`) and the standard WordPress user agent (which includes your site address) are sent; the result is cached for 12 hours. [WebCartisan](https://webcartisan.com/).

**Google Fonts** (`https://fonts.googleapis.com`) — the EpassCard admin screens load the DM Sans, Inter and Material Symbols fonts. Your browser requests them from Google when you open an EpassCard admin screen. [Google Privacy Policy](https://policies.google.com/privacy).

**YouTube (privacy-enhanced mode)** (`https://www.youtube-nocookie.com`) — an intro video is embedded on the EpassCard Connection screen. [Google Privacy Policy](https://policies.google.com/privacy).

**bwip-js barcode API** (`https://bwipjs-api.metafloor.com`) — the WooCommerce Loyalty pass designer shows a barcode preview image generated by this service. Only sample barcode settings are sent (no customer data). [bwip-js](https://github.com/metafloor/bwip-js).

## Privacy Policy 
EpassCard uses [Appsero](https://appsero.com) SDK to collect some telemetry data upon user's confirmation. This helps us to troubleshoot problems faster & make product improvements.

Appsero SDK **does not gather any data by default.** The SDK only starts gathering basic telemetry data **when a user allows it via the admin notice**. We collect the data to ensure a great user experience for all our users. 

Integrating Appsero SDK **DOES NOT IMMEDIATELY** start gathering data, **without confirmation from users in any case.**

Learn more about how [Appsero collects and uses this data](https://appsero.com/privacy-policy/).


== Installation ==

1. Easily install the EpassCard plugin by uploading the plugin folder to /wp-content/plugins/epasscard/ or by installing it directly from the WordPress.org repository, and follow the simple activation and setup process.
2. Activate through the **Plugins** screen.
3. Go to **EpassCard → Connection** and connect your account.
4. Open **Connection → Integrations** and enable the modules you need.
5. Map pass templates under each enabled integration.

== Frequently Asked Questions ==

= Do I need an EpassCard account? =

Yes. Create a free account at [app.epasscard.com](https://app.epasscard.com), then connect it in **EpassCard → Connection**.

= Which plugins are supported? =

MemberPress, Paid Memberships Pro, Simple Membership, Ultimate Membership Pro, WooCommerce Subscriptions, The Events Calendar (with Event Tickets), Events Manager, PW Gift Cards, and YITH Gift Cards. Enable only the modules you use.

= How do I create an Apple Wallet pass on WordPress? =

1. Create a pass template in your EpassCard account (membership card, loyalty card, event ticket, or other pass type).
2. Install and activate the EpassCard WordPress plugin, then connect your API key.
3. Enable the integration module for your plugin (membership or events).
4. Map source fields to pass template fields.
5. When a member subscribes, a ticket is purchased, or a booking is approved, they receive a link to add the pass to **Apple Wallet** or **Google Wallet**.

= How do I add a Google Wallet pass to my WordPress site? =

The workflow is the same as Apple Wallet. EpassCard creates passes for both platforms from one Pass Template mapping. Members choose **Add to Apple Wallet** or **Add to Google Wallet** from their pass link.

= Is there a WordPress plugin for Apple Wallet membership cards? =

Yes. EpassCard is a **WordPress wallet pass plugin** designed for membership and subscription sites. It issues **digital membership cards** to Apple Wallet and Google Wallet automatically when members sign up or renew.

= Does EpassCard work with WooCommerce Apple Wallet and Google Wallet? =

Yes. Enable the WooCommerce Subscriptions module to issue a **WooCommerce wallet pass** when subscriptions activate, renew, or update. This is ideal for subscription boxes, SaaS products, and recurring membership products sold through WooCommerce.

= What is PKPass and does EpassCard support it? =

PKPass (`.pkpass`) is the file format Apple Wallet uses for digital passes. EpassCard issues standard pkpass files compatible with iPhone, iPad, and Apple Watch. You design the pass in EpassCard; the WordPress plugin sends member data and triggers pass creation through the EpassCard API.

= Can I use QR codes and barcodes on wallet passes? =

Yes. If your EpassCard pass template includes QR code or barcode fields, map membership data (member ID, subscription ID, check-in code, etc.) to those fields. Members scan the code from their **mobile wallet pass**.

= Is EpassCard a PassKit alternative for WordPress? =

Yes. EpassCard is a native **WordPress wallet pass plugin** for issuing Apple Wallet and Google Wallet passes — without Zapier, Make, or custom API development. It is built for membership, subscription, event, and gift card sites using plugins such as MemberPress, Paid Memberships Pro, Ultimate Membership Pro, WooCommerce Subscriptions, The Events Calendar (with Event Tickets), Events Manager, PW Gift Cards, or YITH Gift Cards.

= How does wallet pass automation work? =

Passes are created automatically when configured membership events occur (signup, renewal, subscription activation). When mapped membership data changes, EpassCard updates the existing pass via the API. Push notification rules can remind members before expiry or renewal.

= Can I issue digital loyalty cards or event tickets? =

Yes. Create the pass type you need in EpassCard (loyalty card, event ticket, coupon, gift card, employee badge, etc.), then map your WordPress membership or subscription fields to the template. The plugin issues whatever pass type your template defines.

= Does EpassCard send wallet pass update notifications? =

Yes. Configure push reminder rules in the plugin admin for events like subscription expiry, renewal, trial ending, and card expiration. Reminders are delivered as **wallet pass notifications** on supported devices.

= What is the difference between Passbook and Apple Wallet? =

Passbook was renamed to Apple Wallet in 2015. The underlying `.pkpass` format is the same. EpassCard issues passes for modern Apple Wallet and Google Wallet.

= Can I manually create or resend a pass? =

Yes. From the admin **Issued passes** table or member screens, you can create a pass, update an existing pass, or email the pass link to a member.

== Screenshots ==

1. Connection settings — connect your EpassCard account and enable integrations.
2. Pass Template mapping — map membership fields to Apple Wallet and Google Wallet pass fields.
3. Issued passes — searchable list of wallet passes and delivery links.

== Changelog ==
= 1.0.9 =
* Security: API keys, passwords and tokens are now masked in both request and response bodies in the API Log. Existing log entries are cleaned automatically after updating.
* Security: The "Our Products" catalog is now sanitized before display and cached for 12 hours, so the Connection screen no longer waits on a remote request every time it loads.
* Fix: Wallet push reminders are no longer re-sent after a pass is updated, and each new expiry or event date now gets its own reminder.
* Fix: Events Manager pass behavior settings (per booking status) are now applied instead of always using the defaults.
* Fix: Loyalty reward coupon errors now show the correct message at checkout.
* Removed an unused file that was accidentally included in the package.
* Readme: listed all external services used by the plugin.
* Fix: Expiring a pass now uses the documented endpoint (POST /v1/pass-expire/{passUid}). In 1.0.8 expiry calls returned "Not found", so cancelled or refunded members kept a working pass. Passes revoked since then are re-expired automatically once after updating.
* Fix: Failed expiry calls are retried automatically (up to 3 times). The pass is only marked Revoked once EpassCard confirms it.
* New: "Pass actions that need attention" panel on each integration page, plus an admin notice, lists passes that could not be created, updated or expired, with the error from EpassCard and Retry / Dismiss buttons.
* New: "Create passes for existing members" button on each mapped plan, so members who joined before the mapping get a pass.
* Fix: If a stored pass belongs to a different EpassCard account (for example after reconnecting with another API key), a new pass is created instead of failing on every update.
* Fix: Date and number fields are converted to the format EpassCard expects (for example "$501.00" becomes 501.00 and dates become YYYY-MM-DD). Values that cannot be converted are sent unchanged, as before.
* Mapping: The mapping window now shows which pass fields are Required, Unique, Number or Date, blocks saving when a required field is not mapped, and warns about values that may not fit a number field.
* MemberPress: Refunded and expired transactions now expire the pass, and a new "Pass behavior by transaction status" panel lets you choose what happens for each status.
* Ultimate Membership Pro: Passes are now created when a level is assigned and expired when a level is removed or cancelled in UMP 10.x. The older hooks are still supported.
* Simple Membership: Members added from the admin "Add member" screen now get a pass.
* Gift cards (PW and YITH): Only one pass is created per card, after the balance is set; cards with a zero balance no longer get a pass; sender and recipient fall back to the order's billing name and email.
* Loyalty: Points are no longer earned on gift card purchases for new programs (option "Gift cards" in Program settings; existing programs keep their current behavior).
* Loyalty: Rewards that are no longer earned after a refund are withdrawn, and re-offered when the points are earned again.
* Loyalty: The order thank-you page shows the points earned, order notes record each points change, the checkout shows "Loyalty points" for the redeem coupon, and the redeem box is hidden when the customer has no points.
* Loyalty: The pass for a customer's first points is created right away, and the card name uses the pass design name.
* Admin: The Connection screen shows inactive supported plugins with an Activate link and hides the sign-in form once connected. The setup wizard has a proper page title.
* Emails: The pass link email subject now names the pass (new {pass_name} placeholder), so members with several passes can tell them apart. Sites that saved their own subject keep it. New filter epc_pass_email_recipient.

= 1.0.8 =
* Pass Expiration system added 
= 1.0.7 =
* New setup wizard and WooCommerce loyalty system added
= 1.0.6 =
* New integration: The Events Calendar (event mapping; Event Tickets attendees; venue/organizer fields; before-event push).
* Removed standalone Event Tickets module — use The Events Calendar only (avoids duplicate passes for the same attendee). Map events under EpassCard → The Events Calendar; Event Tickets remains required as the TEC add-on for attendees.

= 1.0.5 =
* Free setup help notice updated

= 1.0.4 =
* MemberPress card data fixed

= 1.0.3 =
* Pass link email setup fixed
* Woocommerce Fetal error fixed

= 1.0.2 =
* New integration: Paid Memberships Pro (level mapping, status-based pass behavior, expire push).
* New integration: Simple Membership (level mapping, account-state pass behavior, expire push).
* New integration: Event Tickets (ticket mapping; RSVP / Tickets Commerce / Woo attendees; before-event push).
* New integration: Events Manager (event mapping; booking status sync/revoke; before-event push).
* New integration: PW WooCommerce Gift Cards (product mapping; create/balance/deactivate sync; expire push).
* New integration: YITH WooCommerce Gift Cards (product mapping; generation/status/balance sync; expire push).
* Mapping: Full name (`user_full_name`) source field across membership modules.
* Expiry: lifetime / empty mapped expiry dates send now + 99 years.

= 1.0.1 =
* Rebrand display name to EpassCard.
* Premium SaaS admin UI, AJAX settings saves, and related improvements.
* Readme SEO: expanded Apple Wallet, Google Wallet, pkpass, membership, WooCommerce, and wallet pass management keywords.

= 1.0.0 =
* Initial release: Connection, MemberPress module, WooCommerce Subscriptions module.

== Upgrade Notice ==

= 1.0.9 =
Security and stability release. Masks API keys in the API Log, sanitizes the plugin catalog, and fixes repeated push reminders. Safe update; existing connections, mappings and passes are kept.

= 1.0.1 =
Rebrand and admin UI improvements. Safe update for existing connections.