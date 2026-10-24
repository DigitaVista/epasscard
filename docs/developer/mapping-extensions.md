# Mapping extensions

Extend the field mapping UI and value resolution without forking core templates.

## Add source field options

```php
add_filter( 'epc_mapping_source_fields', function ( $fields, $slug, $module ) {
    if ( 'memberpress' !== $slug ) {
        return $fields;
    }

    $fields['company'] = __( 'Company name', 'my-plugin' );
    return $fields;
}, 10, 3 );
```

Populate values in your module by merging into `$source_values` before `EPC_Pass_Service::sync_pass()`, or override resolution (below).

## Add mapping modes

Default modes: `source`, `custom`, `user_meta` ("User profile field": `{ "type": "user_meta", "value": "<meta key>" }`, resolved from the card's WordPress user by `EPC_User_Fields`; secret keys are refused).

```php
add_filter( 'epc_mapping_modes', function ( $modes, $slug, $module ) {
    $modes['meta'] = __( 'User meta key', 'my-plugin' );
    return $modes;
}, 10, 3 );
```

The admin UI shows a text input for non-source/custom modes. Saved as `{ "type": "meta", "value": "billing_company" }`.

## Resolve custom mode values

```php
add_filter( 'epc_resolve_mapped_value', function ( $value, $entry, $source_values, $slug ) {
    if ( '' !== $value || 'meta' !== ( $entry['type'] ?? '' ) ) {
        return $value;
    }

    $meta_key = $entry['value'] ?? '';
    $user_id  = (int) ( $source_values['_user_id'] ?? 0 ); // if you pass it

    if ( $user_id && $meta_key ) {
        return (string) get_user_meta( $user_id, $meta_key, true );
    }

    return '';
}, 10, 4 );
```

Return a **non-empty** string to override default resolution.

## Normalize entries before sync

```php
add_filter( 'epc_normalize_mapping_entry', function ( $normalized, $raw, $pass_uid, $slug ) {
    if ( null === $normalized || 'custom' !== $normalized['type'] ) {
        return $normalized;
    }

    // Uppercase all custom static values
    $normalized['value'] = strtoupper( $normalized['value'] );
    return $normalized;
}, 10, 4 );
```

Return `null` to skip a field.

## Add mappable entities

```php
add_filter( 'epc_mappable_entities', function ( $entities, $slug, $module ) {
    if ( 'woocommerce-subscriptions' !== $slug ) {
        return $entities;
    }

    $entities[] = array(
        'id'    => 9999,
        'label' => __( 'Virtual bundle', 'my-plugin' ),
    );

    return $entities;
}, 10, 3 );
```

Ensure `get_mapping()` / sync logic handles custom entity ids if they are not real products.

## JavaScript

Mapping modes are passed as `epcAdmin.mappingModes` and `epcAdmin.sourceFields` when the mapping modal loads. For advanced UI, enqueue a script on module admin pages and extend row rendering (keep slug compatibility when saving `type` / `source` / `value`).

## Legacy mapping format

String mappings (`"email"`) are normalized to `{ type: source, source: email }` automatically.
