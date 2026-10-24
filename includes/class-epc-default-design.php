<?php
/**
 * Default design per integration: the design a plan, event or product uses when it has none of its own.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One option per integration, epc_default_mapping_{slug}:
 * {template_uid, template_name, template_id, field_mapping, pass_fields, excluded: int[]}.
 *
 * EPC_Module::get_mapping() falls back to it for items with no mapping of their own, so plans,
 * events and products added later get cards without a visit to the admin. Items in "excluded"
 * get no card. Per-item mappings (epc_mappings_{slug}) are never changed by this class, so sites
 * from 1.1 keep working exactly as before until a default is saved.
 */
class EPC_Default_Design {

	/**
	 * Option name prefix.
	 */
	const OPTION_PREFIX = 'epc_default_mapping_';

	/**
	 * Saved default of an integration.
	 *
	 * @param string $slug Module slug.
	 * @return array<string, mixed>|null
	 */
	public static function get( $slug ) {
		$saved = get_option( self::OPTION_PREFIX . sanitize_key( (string) $slug ), null );
		if ( ! is_array( $saved ) || empty( $saved['template_uid'] ) ) {
			return null;
		}
		$saved['excluded'] = array_values( array_unique( array_map( 'absint', (array) ( $saved['excluded'] ?? array() ) ) ) );
		return $saved;
	}

	/**
	 * Mapping an item gets from the default, or an empty array.
	 *
	 * @param string $slug      Module slug.
	 * @param int    $entity_id Plan, event or product id.
	 * @return array<string, mixed>
	 */
	public static function for_entity( $slug, $entity_id ) {
		$entity_id = absint( $entity_id );
		$saved     = self::get( $slug );
		if ( null === $saved || $entity_id <= 0 || in_array( $entity_id, $saved['excluded'], true ) ) {
			return array();
		}
		unset( $saved['excluded'] );
		$saved['is_default'] = true;
		return $saved;
	}

	/**
	 * Use a template as the default.
	 *
	 * @param string               $slug     Module slug.
	 * @param array<string, mixed> $record   Template record: template_uid, template_name, template_id, field_mapping, pass_fields.
	 * @param array<int, int>      $excluded Items that get no card.
	 * @return void
	 */
	public static function set( $slug, array $record, array $excluded = array() ) {
		update_option(
			self::OPTION_PREFIX . sanitize_key( (string) $slug ),
			array(
				'template_uid'  => (string) $record['template_uid'],
				'template_name' => (string) ( $record['template_name'] ?? '' ),
				'template_id'   => (int) ( $record['template_id'] ?? 0 ),
				'field_mapping' => (array) ( $record['field_mapping'] ?? array() ),
				'pass_fields'   => (array) ( $record['pass_fields'] ?? array() ),
				'excluded'      => array_values( array_unique( array_filter( array_map( 'absint', $excluded ) ) ) ),
			),
			false
		);
	}

	/**
	 * Stop using a default.
	 *
	 * @param string $slug Module slug.
	 * @return void
	 */
	public static function delete( $slug ) {
		delete_option( self::OPTION_PREFIX . sanitize_key( (string) $slug ) );
	}

	/**
	 * Keep the default in step with the integration's ready-made design after it is edited
	 * (EpassCard may return new field ids).
	 *
	 * @param array<string, mixed> $record Ready-made design record.
	 * @param EPC_Module           $module Module.
	 * @return void
	 */
	public static function on_starter_updated( $record, $module ) {
		if ( ! is_array( $record ) || ! $module instanceof EPC_Module ) {
			return;
		}
		$saved = self::get( $module->get_slug() );
		if ( null === $saved || (string) $saved['template_uid'] !== (string) ( $record['template_uid'] ?? '' ) ) {
			return;
		}
		self::set( $module->get_slug(), $record, $saved['excluded'] );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'epc_starter_template_updated', array( __CLASS__, 'on_starter_updated' ), 10, 2 );
	}
}
