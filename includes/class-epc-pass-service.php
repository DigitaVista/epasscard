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

		// Field types from the template snapshot saved with the mapping (uid => type).
		$types = array();
		if ( ! empty( $mapping['pass_fields'] ) && is_array( $mapping['pass_fields'] ) ) {
			foreach ( $mapping['pass_fields'] as $pass_field ) {
				if ( is_array( $pass_field ) && ! empty( $pass_field['uid'] ) ) {
					$types[ (string) $pass_field['uid'] ] = strtolower( (string) ( $pass_field['field_type'] ?? ( $pass_field['type'] ?? '' ) ) );
				}
			}
		}

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
			$value = self::coerce_field_value( $value, $types[ $pass_uid ] ?? '' );
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
	 * Convert a value to the format a typed pass field accepts.
	 *
	 * The API rejects the whole pass when a date field is not YYYY-MM-DD or a number
	 * field is not numeric, e.g. "October 3, 2125" or "$501.00". Values that cannot be
	 * converted are sent unchanged, exactly as in earlier releases.
	 *
	 * @param string $value Value.
	 * @param string $type  Pass field type.
	 * @return string
	 */
	public static function coerce_field_value( $value, $type ) {
		$value = (string) $value;
		if ( '' === $value || '' === $type ) {
			return $value;
		}

		if ( 'date' === $type ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				return $value;
			}
			$ts = strtotime( $value );
			// Unparseable: send as before rather than silently dropping the value.
			return false === $ts ? $value : gmdate( 'Y-m-d', $ts );
		}

		if ( 'number' === $type ) {
			if ( is_numeric( $value ) ) {
				return $value;
			}
			$plain = html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' );
			if ( preg_match( '/-?\d[\d,]*(?:\.\d+)?/', $plain, $m ) ) {
				$num = str_replace( ',', '', $m[0] );
				return is_numeric( $num ) ? $num : $value;
			}
			// No number in it (e.g. a status word): send as before; some templates accept text.
			return $value;
		}

		return $value;
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
	 * Build update payload (same shape as create: uid + fieldValue).
	 *
	 * @param array<string, mixed>  $mapping       Saved mapping config.
	 * @param array<string, string> $source_values Source values.
	 * @param string                $module_slug   Module slug.
	 * @return array<int, array{uid: string, fieldValue: string}>
	 */
	public static function build_update_fields( array $mapping, array $source_values, $module_slug = '' ) {
		return self::build_create_fields( $mapping, $source_values, $module_slug );
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
		// Several plugin hooks can fire for one change (e.g. UMP assigns and activates in one request).
		// If an identical sync already failed in this request, return that error instead of calling the API again.
		$failure_key = $module . '|' . $source_id . '|' . md5( (string) wp_json_encode( array( $entity_id, $mode, $mapping['template_uid'] ?? '', $source_values ) ) );
		if ( isset( self::$failed_in_request[ $failure_key ] ) ) {
			return self::$failed_in_request[ $failure_key ];
		}

		$result = self::run_sync_pass( $module, $source_id, $entity_id, $user_id, $mapping, $source_values, $mode );

		if ( is_wp_error( $result ) ) {
			self::$failed_in_request[ $failure_key ] = $result;
		}

		if ( class_exists( 'EPC_Pass_Issues' ) ) {
			if ( is_wp_error( $result ) ) {
				EPC_Pass_Issues::record( $module, $source_id, sanitize_key( (string) $mode ), $result );
			} else {
				EPC_Pass_Issues::clear( $module, $source_id );
			}
		}

		return $result;
	}

	/**
	 * Per-request guard so one record is not sent to EpassCard several times in one request.
	 *
	 * Gift card plugins fire several hooks while a card is generated; each used to send its
	 * own create call (one before the balance was set).
	 *
	 * @var array<string, true>
	 */
	private static $in_flight = array();

	/**
	 * Hash of the last field payload sent per record in this request.
	 *
	 * @var array<string, string>
	 */
	private static $sent_hash = array();

	/**
	 * Sync calls that already failed in this request, keyed by record and payload.
	 *
	 * @var array<string, \WP_Error>
	 */
	private static $failed_in_request = array();

	/**
	 * Sync implementation (see sync_pass()).
	 *
	 * @param string               $module        Module slug.
	 * @param int|string           $source_id     Source id.
	 * @param int                  $entity_id     Entity id.
	 * @param int                  $user_id       User id.
	 * @param array<string, mixed> $mapping       Mapping.
	 * @param array<string,string> $source_values Values.
	 * @param string               $mode          Mode.
	 * @return true|\WP_Error
	 */
	private static function run_sync_pass( $module, $source_id, $entity_id, $user_id, array $mapping, array $source_values, $mode = 'sync' ) {
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

			$orphaned = false;

			if ( 'revoked' === (string) ( $existing->status ?? '' ) ) {
				$restored = self::restore_expired_pass( $existing, $source_values, $module_slug );
				if ( is_wp_error( $restored ) ) {
					if ( ! EPC_Api_Client::is_ownership_error( $restored ) ) {
						return $restored;
					}
					$orphaned = true;
				}
			}

			$hash_key = $module_slug . '|' . EPC_DB::sanitize_source_id( $source_id );
			$hash     = md5( (string) wp_json_encode( $fields ) );
			if ( ! $orphaned && isset( self::$sent_hash[ $hash_key ] ) && self::$sent_hash[ $hash_key ] === $hash && 'active' === (string) ( $existing->status ?? '' ) ) {
				// The same values were already sent for this record in this request.
				return true;
			}

			if ( ! $orphaned ) {
				if ( class_exists( 'EPC_Api_Log' ) ) {
					EPC_Api_Log::set_request_context( $module_slug . ':update_pass' );
				}

				$result = EPC_Api_Client::update_pass( (string) $existing->pass_uid, $fields );
				if ( is_wp_error( $result ) ) {
					if ( ! EPC_Api_Client::is_ownership_error( $result ) ) {
						return $result;
					}
					$orphaned = true;
				} else {
					self::$sent_hash[ $hash_key ] = $hash;
				}
			}

			if ( $orphaned ) {
				/*
				 * The stored pass belongs to another EpassCard account (the site was reconnected
				 * with a different API key) or was deleted there. Issue a fresh pass for this
				 * record on the current account instead of failing forever.
				 */
				$old_meta                     = EPC_DB::get_pass_meta( $existing );
				$old_meta['replaced_pass_uid'] = (string) $existing->pass_uid;
				return self::create_new_pass( $module, $source_id, $entity_id, $user_id, $mapping, $source_values, $mode, $template_uid, $module_slug, $old_meta );
			}

			$meta = EPC_DB::get_pass_meta( $existing );
			if ( ! empty( $meta['revoke_pending'] ) ) {
				// The record is active again; an earlier failed revoke must not fire later.
				unset( $meta['revoke_pending'] );
				EPC_DB::update_pass_meta( $existing, $meta );
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

		return self::create_new_pass( $module, $source_id, $entity_id, $user_id, $mapping, $source_values, $mode, $template_uid, $module_slug );
	}

	/**
	 * Create a new pass for a record and store it.
	 *
	 * @param string                    $module        Module slug.
	 * @param int|string                $source_id     Source id.
	 * @param int                       $entity_id     Entity id.
	 * @param int                       $user_id       User id.
	 * @param array<string, mixed>      $mapping       Mapping.
	 * @param array<string, string>     $source_values Values.
	 * @param string                    $mode          Mode.
	 * @param string                    $template_uid  Template uid.
	 * @param string                    $module_slug   Sanitized module slug.
	 * @param array<string, mixed>|null $meta          Meta to store (null keeps existing).
	 * @return true|\WP_Error
	 */
	private static function create_new_pass( $module, $source_id, $entity_id, $user_id, array $mapping, array $source_values, $mode, $template_uid, $module_slug, $meta = null ) {
		$fields = self::build_create_fields( $mapping, $source_values, $module_slug );
		if ( empty( $fields ) ) {
			return new WP_Error( 'epc_no_fields', __( 'No mapped fields to send.', 'epasscard' ) );
		}

		$guard = $module_slug . '|' . EPC_DB::sanitize_source_id( $source_id );
		if ( isset( self::$in_flight[ $guard ] ) ) {
			return new WP_Error( 'epc_pass_in_flight', __( 'A pass for this record is already being created.', 'epasscard' ) );
		}
		self::$in_flight[ $guard ] = true;

		if ( class_exists( 'EPC_Api_Log' ) ) {
			EPC_Api_Log::set_request_context( $module_slug . ':create_pass' );
		}

		$result = EPC_Api_Client::create_pass( $template_uid, $fields );
		unset( self::$in_flight[ $guard ] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::$sent_hash[ $guard ] = md5( (string) wp_json_encode( $fields ) );

		$row = array(
			'module'       => $module,
			'source_id'    => $source_id,
			'entity_id'    => $entity_id,
			'user_id'      => $user_id,
			'pass_uid'     => $result['passUid'],
			'pass_link'    => $result['passLink'],
			'template_uid' => $template_uid,
			'status'       => 'active',
		);
		if ( is_array( $meta ) ) {
			unset( $meta['revoke_pending'] );
			$row['meta'] = $meta;
		}
		EPC_DB::upsert_pass( $row );

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
	 * Move an expired pass back into the future so update-pass is accepted.
	 *
	 * POST /pass-expire/{passUid} with a future date. Update-pass rejects an expired pass.
	 *
	 * @param object                $existing      Pass row.
	 * @param array<string, string> $source_values Source field values, including pass_expire_mysql.
	 * @param string                $module_slug   Module slug.
	 * @return true|\WP_Error
	 */
	private static function restore_expired_pass( $existing, array $source_values, $module_slug ) {
		$expire_date = isset( $source_values['pass_expire_mysql'] ) ? trim( (string) $source_values['pass_expire_mysql'] ) : '';
		if ( '' === $expire_date && function_exists( 'epc_pass_expire_mysql_timestamp' ) ) {
			$expire_date = epc_pass_expire_mysql_timestamp( 0 );
		}

		if ( class_exists( 'EPC_Api_Log' ) ) {
			EPC_Api_Log::set_request_context( sanitize_key( (string) $module_slug ) . ':restore_pass' );
		}

		$result = EPC_Api_Client::expire_pass( (string) $existing->pass_uid, $expire_date );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Expire the wallet pass and mark the local row revoked.
	 *
	 * Local status alone does not remove the pass from Apple Wallet or Google Wallet;
	 * POST /pass-expire/{passUid} with a past date does. Until 1.0.9 the row was marked
	 * revoked even when that call failed, so the wallet pass stayed live while the admin
	 * saw "Revoked". Now a failed call keeps the row active with a `revoke_pending` flag,
	 * records the failure for the merchant and retries automatically. When the pass belongs
	 * to another EpassCard account (or was deleted there) it cannot be expired from this
	 * site, so the row is marked revoked.
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source id.
	 * @param int        $attempt   Retry attempt (0 = first call).
	 * @return void
	 */
	public static function revoke_pass( $module, $source_id, $attempt = 0 ) {
		$existing = EPC_DB::get_pass( $module, $source_id );
		if ( ! $existing || empty( $existing->pass_uid ) ) {
			return;
		}
		if ( 'revoked' === (string) $existing->status ) {
			return;
		}

		$result = self::expire_issued_pass( $module, $source_id );
		if ( ! is_wp_error( $result ) ) {
			return;
		}

		$existing = EPC_DB::get_pass( $module, $source_id );
		if ( ! $existing ) {
			return;
		}

		$meta                   = EPC_DB::get_pass_meta( $existing );
		$meta['revoke_pending'] = 1;
		EPC_DB::update_pass_meta( $existing, $meta );

		if ( class_exists( 'EPC_Pass_Issues' ) ) {
			EPC_Pass_Issues::schedule_revoke_retry( $module, $source_id, $attempt );
		}
	}

	/**
	 * Expire one issued pass in every wallet, then mark it revoked.
	 *
	 * The local row stays unchanged when the API call fails so the action can be retried.
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source id.
	 * @return true|\WP_Error
	 */
	public static function expire_issued_pass( $module, $source_id ) {
		$existing = EPC_DB::get_pass( $module, $source_id );
		if ( ! $existing || empty( $existing->pass_uid ) ) {
			return new WP_Error( 'epc_pass_missing', __( 'No pass exists yet for this record.', 'epasscard' ) );
		}

		if ( ! EPC_Api_Client::is_configured() ) {
			return new WP_Error( 'epc_no_key', __( 'EpassCard is not connected.', 'epasscard' ) );
		}

		if ( class_exists( 'EPC_Api_Log' ) ) {
			EPC_Api_Log::set_request_context( sanitize_key( (string) $module ) . ':expire_pass' );
		}

		$result = EPC_Api_Client::expire_pass( (string) $existing->pass_uid );
		if ( is_wp_error( $result ) && ! EPC_Api_Client::is_ownership_error( $result ) ) {
			if ( class_exists( 'EPC_Pass_Issues' ) ) {
				EPC_Pass_Issues::record( $module, $source_id, 'expire', $result );
			}
			return $result;
		}

		self::store_revoked_status( $existing );

		$meta = EPC_DB::get_pass_meta( $existing );
		if ( ! empty( $meta['revoke_pending'] ) ) {
			unset( $meta['revoke_pending'] );
			$fresh = EPC_DB::get_pass( $module, $source_id );
			if ( $fresh ) {
				EPC_DB::update_pass_meta( $fresh, $meta );
			}
		}

		if ( class_exists( 'EPC_Pass_Issues' ) ) {
			EPC_Pass_Issues::clear( $module, $source_id );
		}

		return true;
	}

	/**
	 * Persist a revoked status for an existing pass row.
	 *
	 * @param object $existing Pass row.
	 * @return void
	 */
	private static function store_revoked_status( $existing ) {
		EPC_DB::upsert_pass(
			array(
				'module'       => (string) $existing->module,
				'source_id'    => $existing->source_id,
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
