<?php
/**
 * Pass issuance and update helpers shared by modules.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates/updates passes via EpassCard API using stored mappings.
 */
class EPC_Pass_Service {

	/**
	 * Build API field payload from mapping + source data.
	 *
	 * @param array<string, mixed> $mapping Saved mapping config.
	 * @param array<string, string> $source_values Source field slug => value.
	 * @return array<int, array{uid: string, fieldValue: string}>
	 */
	public static function build_create_fields( array $mapping, array $source_values, $module_slug = '' ) {
		$out = array();
		$map = isset( $mapping['field_mapping'] ) && is_array( $mapping['field_mapping'] )
			? $mapping['field_mapping']
			: array();

		foreach ( $map as $pass_field_uid => $entry ) {
			$pass_uid = EPC_Api_Client::sanitize_uid( (string) $pass_field_uid );
			if ( false === $pass_uid ) {
				continue;
			}

			$normalized = self::normalize_mapping_entry( $entry );
			if ( null === $normalized ) {
				continue;
			}

			/**
			 * Filter normalized mapping entry before value resolution.
			 *
			 * @param array|null           $normalized  Mapping entry or null to skip.
			 * @param mixed                $entry       Raw stored entry.
			 * @param string               $pass_uid    Pass field UUID.
			 * @param string               $module_slug Module slug.
			 */
			$normalized = apply_filters( 'epc_normalize_mapping_entry', $normalized, $entry, $pass_uid, sanitize_key( (string) $module_slug ) );
			if ( null === $normalized || ! is_array( $normalized ) ) {
				continue;
			}

			$value = self::resolve_mapped_value_for_module( $normalized, $source_values, $module_slug );
			if ( '' === $value ) {
				continue;
			}

			$out[] = array(
				'uid'        => $pass_uid,
				'fieldValue' => $value,
			);
		}

		return $out;
	}

	/**
	 * Normalize a stored mapping entry (supports legacy string slugs).
	 *
	 * @param mixed $entry Raw mapping entry.
	 * @return array{type: string, source?: string, value?: string}|null
	 */
	public static function normalize_mapping_entry( $entry ) {
		if ( is_string( $entry ) ) {
			$slug = sanitize_key( $entry );
			if ( '' === $slug ) {
				return null;
			}
			return array(
				'type'   => 'source',
				'source' => $slug,
			);
		}

		if ( ! is_array( $entry ) ) {
			return null;
		}

		$type = isset( $entry['type'] ) ? sanitize_key( (string) $entry['type'] ) : 'source';

		if ( 'custom' === $type ) {
			$value = isset( $entry['value'] ) ? sanitize_text_field( (string) $entry['value'] ) : '';
			if ( '' === $value ) {
				return null;
			}
			return array(
				'type'  => 'custom',
				'value' => $value,
			);
		}

		if ( 'source' !== $type ) {
			$value = isset( $entry['value'] ) ? sanitize_text_field( (string) $entry['value'] ) : '';
			return array(
				'type'  => $type,
				'value' => $value,
			);
		}

		$source = isset( $entry['source'] ) ? sanitize_key( (string) $entry['source'] ) : '';
		if ( '' === $source ) {
			return null;
		}

		return array(
			'type'   => 'source',
			'source' => $source,
		);
	}

	/**
	 * Resolve the pass field value from a normalized mapping entry.
	 *
	 * @param array{type: string, source?: string, value?: string} $entry Normalized entry.
	 * @param array<string, string>                                  $source_values Source values.
	 * @return string
	 */
	public static function resolve_mapped_value( array $entry, array $source_values ) {
		if ( 'custom' === $entry['type'] ) {
			return isset( $entry['value'] ) ? (string) $entry['value'] : '';
		}

		if ( 'source' !== $entry['type'] ) {
			return isset( $entry['value'] ) ? (string) $entry['value'] : '';
		}

		$slug = isset( $entry['source'] ) ? (string) $entry['source'] : '';
		return isset( $source_values[ $slug ] ) ? (string) $source_values[ $slug ] : '';
	}

	/**
	 * Resolve mapped value with extension hook for custom mapping modes.
	 *
	 * @param array{type: string, source?: string, value?: string} $entry Normalized entry.
	 * @param array<string, string>                                  $source_values Source values.
	 * @param string                                                 $module_slug   Module slug.
	 * @return string
	 */
	public static function resolve_mapped_value_for_module( array $entry, array $source_values, $module_slug = '' ) {
		/**
		 * Filter resolved pass field value for custom mapping modes.
		 *
		 * Return non-empty string to override default resolution.
		 *
		 * @param string               $value         Resolved value (empty to use defaults).
		 * @param array                $entry         Normalized mapping entry.
		 * @param array<string,string> $source_values Source field values.
		 * @param string               $module_slug   Module slug.
		 */
		$filtered = apply_filters( 'epc_resolve_mapped_value', '', $entry, $source_values, sanitize_key( (string) $module_slug ) );
		if ( '' !== (string) $filtered ) {
			return (string) $filtered;
		}

		return self::resolve_mapped_value( $entry, $source_values );
	}

	/**
	 * Build update payload (field_value key).
	 *
	 * @param array<string, mixed> $mapping Saved mapping config.
	 * @param array<string, string> $source_values Source values.
	 * @return array<int, array{uid: string, field_value: string}>
	 */
	public static function build_update_fields( array $mapping, array $source_values, $module_slug = '' ) {
		$create = self::build_create_fields( $mapping, $source_values, $module_slug );
		$out    = array();
		foreach ( $create as $row ) {
			$out[] = array(
				'uid'          => $row['uid'],
				'field_value'  => $row['fieldValue'],
			);
		}
		return $out;
	}

	/**
	 * Issue or refresh a pass for a source record.
	 *
	 * @param string               $module        Module slug.
	 * @param int|string           $source_id     Membership/subscription id.
	 * @param int                  $entity_id     Product/level id.
	 * @param int                  $user_id       WP user id.
	 * @param array<string, mixed> $mapping       Mapping config.
	 * @param array<string, string> $source_values Field values.
	 * @param string               $mode          sync|create|update.
	 * @return true|\WP_Error
	 */
	public static function sync_pass( $module, $source_id, $entity_id, $user_id, array $mapping, array $source_values, $mode = 'sync' ) {
		$mode = sanitize_key( (string) $mode );
		if ( ! in_array( $mode, array( 'sync', 'create', 'update' ), true ) ) {
			$mode = 'sync';
		}

		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No pass template is mapped for this item.', 'epasscard' ) );
		}

		if ( ! EPC_Api_Client::is_configured() ) {
			return new WP_Error( 'epc_no_key', __( 'EpassCard is not connected.', 'epasscard' ) );
		}

		$template_uid = (string) $mapping['template_uid'];
		$existing     = EPC_DB::get_pass( $module, $source_id );
		$has_pass     = $existing && ! empty( $existing->pass_uid );
		$module_slug  = sanitize_key( (string) $module );

		/**
		 * Fires before a pass sync/create/update runs.
		 *
		 * @param string               $module_slug   Module slug.
		 * @param int                  $source_id     Source record id.
		 * @param int                  $entity_id     Product/level id.
		 * @param int                  $user_id       WordPress user id.
		 * @param array<string, mixed> $mapping       Mapping config.
		 * @param array<string,string> $source_values Field values.
		 * @param string               $mode          sync|create|update.
		 */
		do_action( 'epc_before_sync_pass', $module_slug, $source_id, $entity_id, $user_id, $mapping, $source_values, $mode );

		if ( 'create' === $mode && $has_pass ) {
			return new WP_Error( 'epc_pass_exists', __( 'A pass already exists for this record. Use Update or Sync instead.', 'epasscard' ) );
		}

		if ( 'update' === $mode && ! $has_pass ) {
			return new WP_Error( 'epc_pass_missing', __( 'No pass exists yet for this record. Use Create or Sync instead.', 'epasscard' ) );
		}

		if ( $has_pass && 'create' !== $mode ) {
			$fields = self::build_update_fields( $mapping, $source_values, $module_slug );
			if ( empty( $fields ) ) {
				return new WP_Error( 'epc_no_fields', __( 'No mapped fields to update.', 'epasscard' ) );
			}

			if ( class_exists( 'EPC_Api_Log' ) ) {
				EPC_Api_Log::set_request_context( $module_slug . ':update_pass' );
			}

			$result = EPC_Api_Client::update_pass( (string) $existing->pass_uid, $fields );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			EPC_DB::upsert_pass(
				array(
					'module'       => $module,
					'source_id'    => $source_id,
					'entity_id'    => $entity_id,
					'user_id'      => $user_id,
					'pass_uid'     => (string) $existing->pass_uid,
					'pass_link'    => (string) $existing->pass_link,
					'template_uid' => $template_uid,
					'status'       => 'active',
				)
			);

			$pass_row = EPC_DB::get_pass( $module_slug, $source_id );
			self::fire_pass_synced_hooks( $module_slug, $source_id, $pass_row, $mode, false );

			return true;
		}

		$fields = self::build_create_fields( $mapping, $source_values, $module_slug );
		if ( empty( $fields ) ) {
			return new WP_Error( 'epc_no_fields', __( 'No mapped fields to send.', 'epasscard' ) );
		}

		if ( class_exists( 'EPC_Api_Log' ) ) {
			EPC_Api_Log::set_request_context( $module_slug . ':create_pass' );
		}

		$result = EPC_Api_Client::create_pass( $template_uid, $fields );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		EPC_DB::upsert_pass(
			array(
				'module'       => $module,
				'source_id'    => $source_id,
				'entity_id'    => $entity_id,
				'user_id'      => $user_id,
				'pass_uid'     => $result['passUid'],
				'pass_link'    => $result['passLink'],
				'template_uid' => $template_uid,
				'status'       => 'active',
			)
		);

		$pass_row = EPC_DB::get_pass( $module_slug, $source_id );
		self::fire_pass_synced_hooks( $module_slug, $source_id, $pass_row, $mode, true );

		return true;
	}

	/**
	 * Fire pass lifecycle hooks and optional auto-email.
	 *
	 * @param string      $module_slug Module slug.
	 * @param int         $source_id   Source id.
	 * @param object|null $pass_row    Pass row.
	 * @param string      $mode        Sync mode.
	 * @param bool        $created     Whether a new pass was created.
	 * @return void
	 */
	private static function fire_pass_synced_hooks( $module_slug, $source_id, $pass_row, $mode, $created ) {
		if ( ! $pass_row ) {
			return;
		}

		/**
		 * Fires after a pass is synced successfully.
		 *
		 * @param string $module_slug Module slug.
		 * @param int    $source_id   Source record id.
		 * @param object $pass_row    Pass row.
		 * @param string $mode        sync|create|update.
		 * @param bool   $created     True when a new pass was created.
		 */
		do_action( 'epc_pass_synced', $module_slug, $source_id, $pass_row, $mode, $created );

		if ( $created ) {
			/**
			 * Fires after a new wallet pass is created.
			 *
			 * @param string $module_slug Module slug.
			 * @param int    $source_id   Source record id.
			 * @param object $pass_row    Pass row.
			 */
			do_action( 'epc_pass_created', $module_slug, $source_id, $pass_row );
		} else {
			/**
			 * Fires after an existing wallet pass is updated.
			 *
			 * @param string $module_slug Module slug.
			 * @param int    $source_id   Source record id.
			 * @param object $pass_row    Pass row.
			 */
			do_action( 'epc_pass_updated', $module_slug, $source_id, $pass_row );
		}

		if ( class_exists( 'EPC_Pass_Email' ) ) {
			EPC_Pass_Email::maybe_send_after_sync( $module_slug, $source_id, $mode, $created );
		}
	}

	/**
	 * Mark pass revoked locally (API revoke can be added when endpoint is available).
	 *
	 * @param string $module    Module slug.
	 * @param int    $source_id Source id.
	 * @return void
	 */
	public static function revoke_pass( $module, $source_id ) {
		$existing = EPC_DB::get_pass( $module, $source_id );
		if ( ! $existing ) {
			return;
		}

		EPC_DB::upsert_pass(
			array(
				'module'       => $module,
				'source_id'    => $source_id,
				'entity_id'    => (int) $existing->entity_id,
				'user_id'      => (int) $existing->user_id,
				'pass_uid'     => (string) $existing->pass_uid,
				'pass_link'    => (string) $existing->pass_link,
				'template_uid' => (string) $existing->template_uid,
				'status'       => 'revoked',
			)
		);
	}
}
