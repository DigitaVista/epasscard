# Pass Template mapping

Mapping connects a membership plan or subscription product to an EpassCard **pass template** and defines how WordPress data fills each pass field.

## Open the mapping UI

1. Go to the integration page (e.g. **EpassCard → MemberPress**).
2. In **Pass Template mapping**, click **Set up mapping** or **Edit mapping** for a plan/product.

## Steps in the modal

1. **Select template** — Loaded from your EpassCard account. Use **Refresh template list** if you recently created templates.
2. **Map fields** — For each pass field, choose:
   - **Source field** — Pull value from membership/subscription data (name, email, expiry, etc.).
   - **Custom value** — Fixed text for every pass on this plan.
3. **Save mapping** — Stored in WordPress options per entity id.

## Source fields

Available source fields depend on the integration. Typical examples:

- Member name and email
- Membership or subscription status
- Start / expiry dates
- Product or level title

Developers can add more options via the `epc_mapping_source_fields` filter (see [developer/mapping-extensions.md](../developer/mapping-extensions.md)).

## After mapping

- Automatic pass creation runs when the integration’s event hooks fire (new subscription, active membership, etc.).
- Use **Create pass** / **Update pass** on admin lists for manual control.
- Empty mapped fields are skipped when calling the API.

## Tips

- Map at least the fields required by your template in EpassCard.
- Use **Custom value** for static labels (e.g. venue name) shared by all members on a plan.
- Re-open mapping after changing templates in EpassCard to refresh field lists.
