# Loyalty pass template API (v2)

Source of truth: [https://app.epasscard.com/doc/api/v2](https://app.epasscard.com/doc/api/v2) (OpenAPI at `https://api.epasscard.com/api/docs/openapi.json`).

Authentication, errors, pagination, and origin/`x-api-key` rules match that document. Do not invent payload shapes from the WordPress v1 helper methods alone.

## Available now (used by this plugin)

### Create template (simplified)

- `POST https://api.epasscard.com/api/public/v2/create-pass-template`
- Content-Type: `application/json`
- Auth: `x-api-key` (or Bearer)
- Images: `logo`, optional `icon`, `strip_image` as public `https://` URLs or `data:image/png|jpeg;base64,…` URIs (fetched/optimized server-side; 10 MB input limit)
- Required loyalty-friendly fields include `template_name`, `organization_name`, `certificate`, `card_type` (`StoreCard`), `logo`, `strip_image`, `header_fields` (1–3), `secondary_fields` (1–4), `barcode`, `expire_date`, `pass_limit`
- Colors: optional `colors.background` / `text` / `label` / `strip` as `#RRGGBB`
- Placeholders such as `{Points}`, `{Name}`, `{Member No}` become pass fields; optional `fields[]` supplies type/required/unique hints
- Success: `201` with `data.uid` and `data.fields[].uid`

### Update template (simplified)

- `PUT https://api.epasscard.com/api/public/v2/update-pass-template/{templateUid}`
- Auth / origin / error envelope identical to create
- Request body: **same simplified schema as** `POST /create-pass-template`
- Path `{templateUid}` is the template `uid` returned by create (must belong to the API key’s organization)
- Re-fetch and re-optimize any changed `logo` / `icon` / `strip_image`
- Preserve certificate lock rules once passes exist (reject certificate changes with `400`, same as dashboard)
- Response: `200` with the same `data` object shape as create (`uid`, `fields[]`, links)
- Validation failures: `422`/`400` with `errors[]` as on create

`EPC_Api_Client::update_pass_template_v2( $templateUid, $payload )` sends this request. Callers such as `EPC_Loyalty_Pass_Design_Service::save_and_sync()` reuse `build_remote_payload()` for both create and update.

### Read template

- `GET https://api.epasscard.com/api/public/v1/template-details/{uid}`
- Linked from v2 create responses as `data.links.details`
- Also: `GET /api/public/v2/get-pass-template/{templateUid}` (simplified read)

### Pass fields / pass issue (existing)

- `GET /api/public/v1/pass-fields/{uid}`
- Pass create/update for customer sync continues to use the documented v1 single-pass routes already wrapped by `EPC_Api_Client` (`create-single-pass`, `update-single-pass`). V2 also documents `POST /api/public/v2/create-wallet-passes/{templateUid}` with `{ fields: [ { uid, value } ] }`.

## Legacy v1 update (do not use from the loyalty designer)

The dashboard-shaped v1 route remains:

- `PUT /api/public/v1/update-pass-template/{uid}` with required `design` + `template` objects (path `{uid}` is ignored; body `template.template_uid` wins)

That shape is unsuitable for the WordPress loyalty / wizard designers, which need the same simplified body as create.

## WordPress mapping

`EPC_Loyalty_Pass_Design_Service` builds the simplified payload, calls create or update, then writes `epc_mappings_woocommerce-loyalty` for entity `1` so customer pass sync can map:

| Placeholder | Source field |
| --- | --- |
| Points | `points_balance` |
| Name | `user_full_name` |
| Member No | `member_id` |
| Tier / Next Reward / Milestone | configured secondary mode |
| Lifetime Points | `lifetime_points` |
| Email | `user_email` |
| Reward Summary | `reward_summary` |
