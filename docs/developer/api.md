# Card API (PHP and REST)

Issue, update, void and read a wallet card for a record of an active integration from your own
code, or over the WordPress REST API. Available since 1.2.0.

Each call runs the integration's own sync, so the card gets the same design and field values as
the automatic flow, and a record never gets a second card. Every card counts toward the
EpassCard plan like any other.

## Which record?

A card belongs to one record of an integration: the integration slug plus the record id that
integration uses.

| Integration | Slug | Record id |
|---|---|---|
| WooCommerce Loyalty | `woocommerce-loyalty` | The customer's WordPress user id |
| Paid Memberships Pro | `paid-memberships-pro` | Row id in `pmpro_memberships_users` |
| MemberPress | `memberpress` | `sub_<subscription id>` or `txn_<transaction id>` |
| Simple Membership | `simple-membership` | Member id |
| Ultimate Membership Pro | `ultimate-membership-pro` | Row id in `ihc_user_levels` |
| WooCommerce Subscriptions | `woocommerce-subscriptions` | Subscription id |
| The Events Calendar | `the-events-calendar` | Attendee id |
| Events Manager | `events-manager` | Booking id |
| PW Gift Cards | `pw-gift-cards` | Gift card id |
| YITH Gift Cards | `yith-gift-cards` | Gift card post id |
| Ultimate Member | `ultimate-member` | WordPress user id |
| WPLoyalty | `wployalty` | Row id in `wlr_users` (WPLoyalty's customer table) |
| Amelia appointments | `amelia-appointments` | Amelia booking id; with one card per person `p<booking id>_<n>` |
| Amelia events | `amelia-events` | Amelia booking id; with one card per person `p<booking id>_<n>` |
| Tickera | `tickera` | Tickera ticket (attendee) post id (`tc_tickets_instances`) |
| WPForms | `wpforms` | Row id in `epc_form_submissions` (EpassCard's copy of the submission) |
| WooCommerce tickets | `woocommerce-tickets` | `i<order item id>_<unit>`, one per unit bought (`i57_2` = second ticket of line 57) |
| WooCommerce vouchers | `woocommerce-vouchers` | `i<order item id>_<unit>` |
| WooCommerce product cards | `woocommerce-product-cards` | `i<order item id>_<unit>` |

The integration must be turned on and its plugin active, and the site must be connected to
EpassCard. Record ids are digits, or a lowercase prefix, an underscore and digits (`sub_12`).

## PHP

```php
// Create the card, or update it when it exists (a voided card is made live again).
// 'email' => true sends the card link once (Settings → Emails), instead of the automatic email.
$card = epc_issue_pass( 'paid-memberships-pro', $membership_row_id, array( 'email' => true ) );

// Update the existing card with the record's current values. Error when there is no card.
$card = epc_update_pass( 'paid-memberships-pro', $membership_row_id );

// Void: the card expires in Apple Wallet and Google Wallet and shows as revoked.
$card = epc_void_pass( 'paid-memberships-pro', $membership_row_id );

// Read.
$card = epc_get_pass( 'paid-memberships-pro', $membership_row_id );

if ( is_wp_error( $card ) ) {
    error_log( $card->get_error_code() . ': ' . $card->get_error_message() );
}
```

Each function returns an array or a `WP_Error`:

| Key | Type | Notes |
|---|---|---|
| `module` | string | Integration slug |
| `source_id` | string | Record id |
| `entity_id` | int | Plan, level, event or product id |
| `user_id` | int | WordPress user the card belongs to (0 = none) |
| `pass_uid` | string | Card id at EpassCard |
| `pass_link` | string | Link that adds the card to Apple Wallet or Google Wallet |
| `status` | string | `active` or `revoked` |
| `is_test` | bool | A test card from the Try it step |
| `created_at`, `updated_at` | string | Database time |
| `created` | bool | `epc_issue_pass()` only: this call created the card |
| `emailed` | bool | `epc_issue_pass()` only: the email went out (`email_error` holds the reason when not) |

The functions do not check the current user's capabilities; check them in your code.

## REST

Namespace `epasscard/v1`. Requests need a user who can manage cards (administrators, and staff
with the EpassCard staff capability): use an application password or the REST nonce.

| Method | Route | Does |
|---|---|---|
| `POST` | `/passes` (body: `module`, `source_id`, optional `email`) | Issue. `201` when the card was created, `200` when it was updated |
| `GET` | `/passes/{module}/{source_id}` | Read |
| `PUT`, `PATCH` | `/passes/{module}/{source_id}` | Update |
| `DELETE` | `/passes/{module}/{source_id}` | Void |

```bash
curl -u "admin:APPLICATION PASSWORD" -X POST \
  https://example.com/wp-json/epasscard/v1/passes \
  -d module=woocommerce-loyalty -d source_id=42 -d email=true
```

Errors use the same codes as PHP, with these statuses:

| Code | Status |
|---|---|
| `epc_unknown_integration`, `epc_invalid_source`, `epc_pass_missing` | 404 |
| `epc_invalid_source_id` | 400 |
| `epc_no_mapping` (no design for the plan, event or product) | 409 |
| `epc_no_key` (not connected) | 503 |
| `epc_http`, `epc_http_transport`, `epc_create_failed`, `epc_update_failed`, `epc_expire_failed`, `epc_bad_json` (EpassCard answered with an error or could not be reached) | 502 |
| `epc_timeout` | 504 |
| Anything else about the record (no user, no fields …) | 400 |
