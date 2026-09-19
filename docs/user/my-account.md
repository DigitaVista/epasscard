# My Account & shortcode

Members can access wallet passes without opening email links.

## WooCommerce My Account

When WooCommerce is active, logged-in customers see **Wallet passes** in the My Account menu (after **Orders** when possible).

URL pattern: `/my-account/wallet-passes/`

Requires at least one **active** pass with a link for the current user.

When the WooCommerce Loyalty module is enabled, **Loyalty wallet** appears as well:

URL pattern: `/my-account/loyalty-wallet/`

Points history shows 20 entries per page. Further pages use `/my-account/loyalty-wallet/2/` (and so on).

**Note:** After installing or updating EpassCard, visit **Settings → Permalinks** and save once if an endpoint 404s (activation and the loyalty endpoint also flush rewrite rules).

## MemberPress account

On the MemberPress account page, members see a **Wallet Passes** nav item linking to `?action=wallet_passes`.

## Shortcode

Add to any page or post:

```
[epc_my_passes]
```

- Logged-out visitors see a “Please log in” message.
- Logged-in users see a card list of their active passes.

## Pass list behavior

- Only **active** passes with a non-empty `pass_link` are shown, and only when that integration module is enabled (including its dependency plugin being active).
- Duplicate pass UIDs or links are collapsed to one card.
- Cards show the membership/product name, a pass type (loyalty, subscription, membership, ticket, gift card), and an **Add to wallet** action.
- Links open in a new tab.

## Customization (developers)

- `epc_frontend_user_passes` — Filter which passes appear
- `epc_frontend_pass_label` — Change the card title
- `epc_enqueue_frontend_assets` — Force-load pass list styles

See [developer/hooks.md](../developer/hooks.md).
