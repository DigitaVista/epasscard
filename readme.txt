=== EpassCard – Apple & Google Wallet Passes for Loyalty, Memberships, Events & Gift Cards ===
Contributors: wooxperto, hasan350
Tags: apple wallet, google wallet, wallet pass, woocommerce, membership
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Issue Apple Wallet & Google Wallet passes from WordPress – WooCommerce loyalty cards, membership cards, event tickets & gift cards. Free: 50 passes.

== Description ==

**EpassCard turns your WordPress data into Apple Wallet and Google Wallet passes.** Loyalty cards for WooCommerce, membership cards for MemberPress and Paid Memberships Pro, event tickets, and gift cards are issued, updated and pushed to your customers' phones automatically. No code, no Zapier.

[youtube https://youtu.be/-arN8Db3fjA]

= Why EpassCard =

* **Built for WordPress.** One plugin with 10 integrations. Most wallet pass tools reach WordPress only through Zapier or cover a single plugin.
* **A loyalty program with a wallet card, built in.** Points, tiers and rewards for WooCommerce, with the card living in Apple Wallet and Google Wallet.
* **Both wallets from one template.** Apple Wallet (`.pkpass` for iPhone, iPad and Apple Watch) and Google Wallet (Android).
* **Passes stay up to date.** When points, a balance, a membership level or an expiry date changes, the pass on the phone changes too, and you can send push notifications.
* **Free to start.** 50 live passes free. Paid plans from $19/month, or $13/month billed yearly.

[Documentation](https://www.epasscard.com/documentation/) | [Pricing](https://www.epasscard.com/pricing/) | [Book a demo](https://www.epasscard.com/book-a-demo/)

= WooCommerce loyalty program with an Apple Wallet and Google Wallet card =

Give every customer a loyalty card that lives on their phone, not in a forgotten account page.

* Earn points on orders
* Tiers and milestone rewards
* Coupon rewards, and point redemption at checkout
* The wallet card shows the current points balance and updates automatically
* Push notifications to bring customers back

= Digital membership cards =

Turn memberships into **digital membership cards** members save to their phone wallet.

* **MemberPress** – issue a membership wallet pass on signup, renewal and profile updates; expire it on refund or expiry
* **Paid Memberships Pro** – issue passes when a level is assigned, renewed, cancelled or expires
* **Simple Membership** – issue passes when members register, change level or change account state
* **Ultimate Membership Pro** – issue passes for UMP members with the same template-mapping workflow
* **WooCommerce Subscriptions** – create a subscription wallet pass when a subscription activates or renews

= Event tickets in Apple Wallet and Google Wallet =

* **The Events Calendar** (with Event Tickets) – map events to pass templates and issue a wallet ticket to each attendee
* **Events Manager** – issue wallet passes when bookings are approved, and update them when the status changes

= Gift cards in the wallet =

* **PW WooCommerce Gift Cards** – issue a pass when a gift card is created, and update the balance on the pass
* **YITH WooCommerce Gift Cards** – issue and update passes when gift cards are generated or used

Map names, plans, expiry dates, QR codes, barcodes and custom fields from your plugin to your pass template.

= Other pass types =

Because passes are designed in EpassCard, you can also issue stamp cards, coupons, employee and student ID cards, visitor passes, conference badges, digital business cards (vCard) and reservation passes.

= Pricing =

Plans are based on **live passes**: passes in your customers' wallets that you can update and send notifications to at any time. Delete a pass you no longer need to free its slot.

* **Free** – 50 live passes, 3 templates
* **Starter** – $19/month – 250 live passes, 5 templates, AI pass designer
* **Growth** – $39/month – 1,000 live passes, 10 templates
* **Business** – $65/month – 5,000 live passes, 25 templates
* **Pro, Scale and Scale+** – up to 50,000 live passes

Save 30%+ with yearly billing. See [all plans](https://www.epasscard.com/pricing/).

= A PassKit alternative for WordPress =

Looking for a **PassKit**, **Passcreator** or **Pass2U alternative** that works natively inside WordPress? EpassCard is a wallet pass platform with a built-in WordPress connector, not a middleware automation. Connect your account, map your templates, and passes are created when things happen on your site.

= Features =

* **Apple Wallet and Google Wallet** – one integration, both wallets
* **Automatic pass issuing** – on signup, renewal, purchase, booking or points earned
* **Pass updates** – passes stay in sync when the source data changes
* **Template mapping** – map your plugin's fields to pass fields, including QR codes and barcodes
* **Push notifications** – reminders before expiry, renewal or an event
* **Built-in WooCommerce loyalty program** – points, tiers, rewards and checkout redemption
* **Issued passes list** – searchable table of passes and delivery links, with retry for failed actions
* **My Account access** – customers open their passes from their account area
* **Email delivery** – the pass link is emailed automatically

= Who is it for? =

* **WooCommerce stores** that want a loyalty program customers actually use
* **Membership sites** – gyms, clubs, associations and online courses
* **Subscription businesses** using WooCommerce Subscriptions
* **Event organisers** using The Events Calendar or Events Manager
* **Gift card sellers** using PW or YITH gift cards
* **Agencies** that build WordPress sites and want to offer wallet passes to clients

= Requirements =

* An [EpassCard](https://epasscard.com/) account (free plan available – sign up from the plugin or at [app.epasscard.com](https://app.epasscard.com))
* For the loyalty program: WooCommerce
* For other passes: at least one supported plugin (MemberPress, Paid Memberships Pro, Simple Membership, Ultimate Membership Pro, WooCommerce Subscriptions, The Events Calendar + Event Tickets, Events Manager, PW Gift Cards or YITH Gift Cards)

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

= How much does EpassCard cost? =

The plugin is free. The EpassCard service has a free plan with 50 live passes. Paid plans start at $19/month for 250 live passes, or $13/month billed yearly. See [pricing](https://www.epasscard.com/pricing/).

= What is a live pass? =

A live pass is a pass in a customer's wallet that you can update and send notifications to (for example a loyalty card whose points change). Your plan sets how many live passes you can have at once. Delete a pass you no longer need to free its slot.

= Do I need an Apple Developer account? =

No. EpassCard signs and delivers the passes for you.

= How does the WooCommerce loyalty program work? =

Enable the WooCommerce Loyalty module, set how customers earn points, and add tiers and rewards. Each customer gets a loyalty card in Apple Wallet or Google Wallet that shows their points and updates automatically. Customers can redeem points at checkout.

= Do I need an EpassCard account? =

Yes. Create a free account at [app.epasscard.com](https://app.epasscard.com), then connect it in **EpassCard → Connection**.

= Which plugins are supported? =

WooCommerce (built-in loyalty program), MemberPress, Paid Memberships Pro, Simple Membership, Ultimate Membership Pro, WooCommerce Subscriptions, The Events Calendar (with Event Tickets), Events Manager, PW Gift Cards and YITH Gift Cards. Enable only the modules you use.

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
= 1.1.0 =
* New: "Create pass design for me". On the Paid Memberships Pro, MemberPress, Simple Membership, Ultimate Membership Pro, WooCommerce Subscriptions, The Events Calendar, Events Manager, PW Gift Cards and YITH Gift Cards pages, one click creates a ready-made pass template in your EpassCard account (fields, QR code, colors and logo) and maps it to every plan, event or product that is not mapped yet. Existing mappings are never changed.
* New: Customize the ready-made pass before or after it is created: your own logo and strip image (from the Media Library or a URL), background, value and label colors, every field label on the front and back, and the QR/barcode format, with a live front and back preview. "Edit design" updates the template in EpassCard and keeps all mappings.
* Images from sites that are not public (localhost, .test, staging) are sent to EpassCard inline, so custom logos and strips also work on local and staging sites.
* New: "Use it for the unmapped items" button applies your ready-made design to plans, events or products you add later.
* New: A "Create a pass design for me" link next to "Create a new template" in the mapping window.
* Each integration creates at most one template, even after a double click or a retry. The design card shows how many items use it.
* Fix: Primary buttons inside EpassCard screens were shown in grey.
* Fix: Empty logo and strip previews on the WooCommerce Loyalty Pass Design screen showed broken images.
* Developer: New filters epc_starter_template_preset and epc_starter_template_payload (now also receives the design), and action epc_starter_template_updated. EPC_Api_Client::create_pass_template_v2() and update_pass_template_v2() accept an optional API Log context.
* Security: Every database query on EpassCard's own tables now uses prepared table-name placeholders, and request input in the setup wizard, pass actions and loyalty points adjustment is sanitized more strictly. The plugin passes WordPress Plugin Check with no errors.
* New: A dismissible Halloween deal notice for administrators. It stops showing automatically after October 31, 2026.

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
* WooCommerce fatal error fixed

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

= 1.1.0 =
Adds one-click, customizable pass designs (logo, strip, colors, labels) for membership, subscription, event and gift card integrations. Safe update; existing connections, mappings and passes are kept.

= 1.0.9 =
Security and stability release. Masks API keys in the API Log, sanitizes the plugin catalog, and fixes repeated push reminders. Safe update; existing connections, mappings and passes are kept.

= 1.0.1 =
Rebrand and admin UI improvements. Safe update for existing connections.