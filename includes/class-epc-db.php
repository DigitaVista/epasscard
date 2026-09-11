<?php
/**
 * Custom database table for issued passes.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pass records persistence.
 */
class EPC_DB {

	/**
	 * Table name without prefix.
	 */
	public const TABLE = 'epc_passes';

	/**
	 * Loyalty account table name without prefix.
	 */
	public const LOYALTY_ACCOUNTS_TABLE = 'epc_loyalty_accounts';

	/**
	 * Loyalty ledger table name without prefix.
	 */
	public const LOYALTY_LEDGER_TABLE = 'epc_loyalty_ledger';

	/**
	 * Loyalty reward claims table name without prefix.
	 */
	public const LOYALTY_CLAIMS_TABLE = 'epc_loyalty_claims';

	/**
	 * Install or upgrade schema.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			module varchar(32) NOT NULL DEFAULT '',
			source_id varchar(64) NOT NULL DEFAULT '',
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			pass_uid varchar(36) NOT NULL DEFAULT '',
			pass_link text NOT NULL,
			template_uid varchar(36) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'active',
			meta longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY module_source (module, source_id),
			KEY user_id (user_id),
			KEY pass_uid (pass_uid)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::install_loyalty_tables();

		require_once EPC_PLUGIN_DIR . 'includes/class-epc-api-log.php';
		EPC_Api_Log::install();

		update_option( 'epc_db_version', EPC_DB_VERSION );
	}

	/**
	 * Install loyalty transactional tables.
	 *
	 * @return void
	 */
	private static function install_loyalty_tables() {
		global $wpdb;

		$accounts        = self::loyalty_accounts_table_name();
		$ledger          = self::loyalty_ledger_table_name();
		$claims          = self::loyalty_claims_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$accounts_sql = "CREATE TABLE {$accounts} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			member_id varchar(64) NOT NULL,
			points_balance bigint(20) NOT NULL DEFAULT 0,
			lifetime_points bigint(20) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			UNIQUE KEY member_id (member_id)
		) ENGINE=InnoDB {$charset_collate};";

		$ledger_sql = "CREATE TABLE {$ledger} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			event_key varchar(191) NOT NULL,
			entry_type varchar(32) NOT NULL,
			points_delta bigint(20) NOT NULL DEFAULT 0,
			lifetime_delta bigint(20) NOT NULL DEFAULT 0,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			refund_id bigint(20) unsigned NOT NULL DEFAULT 0,
			amount decimal(20,6) NOT NULL DEFAULT 0,
			currency varchar(8) NOT NULL DEFAULT '',
			description text NULL,
			meta longtext NULL,
			expires_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY user_created (user_id, created_at),
			KEY order_id (order_id),
			KEY refund_id (refund_id),
			KEY expires_at (expires_at)
		) ENGINE=InnoDB {$charset_collate};";

		$claims_sql = "CREATE TABLE {$claims} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			reward_key varchar(191) NOT NULL,
			reward_type varchar(32) NOT NULL DEFAULT '',
			status varchar(24) NOT NULL DEFAULT 'available',
			usage_count bigint(20) unsigned NOT NULL DEFAULT 0,
			meta longtext NULL,
			expires_at datetime NULL DEFAULT NULL,
			claimed_at datetime NULL DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_reward (user_id, reward_key),
			KEY status_expires (status, expires_at)
		) ENGINE=InnoDB {$charset_collate};";

		dbDelta( $accounts_sql );
		dbDelta( $ledger_sql );
		dbDelta( $claims_sql );
	}

	/**
	 * Run schema upgrades when the plugin DB version changes.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( 'epc_db_version', '0' );
		if ( version_compare( $installed, EPC_DB_VERSION, '<' ) ) {
			self::install();
			if ( version_compare( $installed, '1.2.0', '<' ) ) {
				self::ensure_source_id_varchar();
				self::migrate_memberpress_source_ids();
			}
		}
	}

	/**
	 * Ensure source_id is varchar (dbDelta often skips type changes).
	 *
	 * @return void
	 */
	private static function ensure_source_id_varchar() {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
		$col = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'source_id' ), ARRAY_A );
		if ( ! is_array( $col ) || empty( $col['Type'] ) ) {
			return;
		}

		if ( false !== stripos( (string) $col['Type'], 'varchar' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required column type migration.
		$wpdb->query( "ALTER TABLE {$table} MODIFY source_id varchar(64) NOT NULL DEFAULT ''" );
	}

	/**
	 * Sanitize a pass source key (numeric or namespaced, e.g. sub_12 / txn_34).
	 *
	 * @param mixed $source_id Raw source id.
	 * @return string Empty string when invalid.
	 */
	public static function sanitize_source_id( $source_id ) {
		$source_id = trim( (string) $source_id );
		if ( '' === $source_id ) {
			return '';
		}

		if ( preg_match( '/^\d+$/', $source_id ) ) {
			return $source_id;
		}

		if ( preg_match( '/^[a-z][a-z0-9]*_\d+$/', $source_id ) ) {
			return $source_id;
		}

		return '';
	}

	/**
	 * Re-key MemberPress passes so subscription and transaction IDs cannot collide.
	 *
	 * Legacy rows stored bare numeric IDs from either wp_mepr_subscriptions or
	 * wp_mepr_transactions in the same source_id namespace.
	 *
	 * @return void
	 */
	private static function migrate_memberpress_source_ids() {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_id, user_id FROM %i WHERE module = %s AND source_id REGEXP '^[0-9]+$'",
				$table,
				'memberpress'
			)
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return;
		}

		$has_mepr = class_exists( 'MeprSubscription' ) && class_exists( 'MeprTransaction' );

		foreach ( $rows as $row ) {
			$legacy_id = absint( $row->source_id );
			$user_id   = absint( $row->user_id );
			if ( $legacy_id <= 0 ) {
				continue;
			}

			$new_key = '';

			if ( $has_mepr ) {
				$txn        = new MeprTransaction( $legacy_id );
				$txn_ok     = ! empty( $txn->id ) && (int) $txn->user_id === $user_id;
				$txn_oneoff = $txn_ok && empty( $txn->subscription_id );

				$sub    = new MeprSubscription( $legacy_id );
				$sub_ok = ! empty( $sub->id ) && (int) $sub->user_id === $user_id;

				if ( $txn_oneoff ) {
					$new_key = 'txn_' . $legacy_id;
				} elseif ( $sub_ok ) {
					$new_key = 'sub_' . $legacy_id;
				} elseif ( $txn_ok ) {
					$new_key = 'txn_' . $legacy_id;
				} elseif ( ! empty( $sub->id ) ) {
					$new_key = 'sub_' . $legacy_id;
				} elseif ( ! empty( $txn->id ) ) {
					$new_key = 'txn_' . $legacy_id;
				}
			}

			if ( '' === $new_key ) {
				// Prefer subscription namespace when MemberPress is unavailable.
				$new_key = 'sub_' . $legacy_id;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration write.
			$conflict = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE module = %s AND source_id = %s AND id <> %d LIMIT 1',
					$table,
					'memberpress',
					$new_key,
					(int) $row->id
				)
			);

			if ( $conflict ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration write.
			$wpdb->update(
				$table,
				array( 'source_id' => $new_key ),
				array( 'id' => (int) $row->id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Full loyalty accounts table name.
	 *
	 * @return string
	 */
	public static function loyalty_accounts_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::LOYALTY_ACCOUNTS_TABLE;
	}

	/**
	 * Full loyalty ledger table name.
	 *
	 * @return string
	 */
	public static function loyalty_ledger_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::LOYALTY_LEDGER_TABLE;
	}

	/**
	 * Full loyalty claims table name.
	 *
	 * @return string
	 */
	public static function loyalty_claims_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::LOYALTY_CLAIMS_TABLE;
	}

	/**
	 * Insert or update a pass row.
	 *
	 * @param array<string, mixed> $data Row data.
	 * @return int|false Row ID or false.
	 */
	public static function upsert_pass( array $data ) {
		global $wpdb;

		$table  = self::table_name();
		$module = sanitize_key( (string) ( $data['module'] ?? '' ) );
		$source = self::sanitize_source_id( $data['source_id'] ?? '' );

		if ( '' === $module || '' === $source ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table upsert lookup.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE module = %s AND source_id = %s LIMIT 1',
				$table,
				$module,
				$source
			)
		);

		$row = array(
			'module'        => $module,
			'source_id'     => $source,
			'entity_id'     => absint( $data['entity_id'] ?? 0 ),
			'user_id'       => absint( $data['user_id'] ?? 0 ),
			'pass_uid'      => sanitize_text_field( (string) ( $data['pass_uid'] ?? '' ) ),
			'pass_link'     => esc_url_raw( (string) ( $data['pass_link'] ?? '' ) ),
			'template_uid'  => sanitize_text_field( (string) ( $data['template_uid'] ?? '' ) ),
			'status'        => sanitize_key( (string) ( $data['status'] ?? 'active' ) ),
			'meta'          => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
		);

		$formats = array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' );

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
			$wpdb->update(
				$table,
				$row,
				array( 'id' => (int) $existing ),
				$formats,
				array( '%d' )
			);
			return (int) $existing;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
		$wpdb->insert(
			$table,
			$row,
			$formats
		);

		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Get pass by module + source id.
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source record id (numeric or namespaced).
	 * @return object|null
	 */
	public static function get_pass( $module, $source_id ) {
		global $wpdb;

		$module    = sanitize_key( (string) $module );
		$source_id = self::sanitize_source_id( $source_id );
		if ( '' === $module || '' === $source_id ) {
			return null;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE module = %s AND source_id = %s LIMIT 1',
				$table,
				$module,
				$source_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Resolve a pass row from a pass UUID, internal id, or source record id.
	 *
	 * @param string $module     Module slug.
	 * @param string $identifier Pass UUID, internal row id, or source id.
	 * @return object|null
	 */
	public static function resolve_pass_for_module( $module, $identifier ) {
		global $wpdb;

		$module     = sanitize_key( (string) $module );
		$identifier = trim( (string) $identifier );

		if ( '' === $module || '' === $identifier ) {
			return null;
		}

		$table = self::table_name();
		$san   = EPC_Api_Client::sanitize_uid( $identifier );

		if ( false !== $san ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE module = %s AND pass_uid = %s LIMIT 1',
					$table,
					$module,
					$san
				)
			);

			return $row ? $row : null;
		}

		if ( ! ctype_digit( $identifier ) ) {
			$source_key = self::sanitize_source_id( $identifier );
			return '' !== $source_key ? self::get_pass( $module, $source_key ) : null;
		}

		$numeric_id = absint( $identifier );
		if ( $numeric_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE module = %s AND id = %d LIMIT 1',
				$table,
				$module,
				$numeric_id
			)
		);
		if ( $row ) {
			return $row;
		}

		return self::get_pass( $module, (string) $numeric_id );
	}

	/**
	 * Query passes for list table.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array{items: array<int, object>, total: int}
	 */
	public static function query_passes( array $args ) {
		global $wpdb;

		$table      = self::table_name();
		$module     = sanitize_key( (string) ( $args['module'] ?? '' ) );
		$search     = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$status     = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$entity_id  = absint( $args['entity_id'] ?? 0 );
		$page       = max( 1, absint( $args['page'] ?? 1 ) );
		$limit      = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$count_only = ! empty( $args['count_only'] );
		$offset     = ( $page - 1 ) * $limit;

		$has_status = '' !== $status;
		$has_entity = $entity_id > 0;
		$has_search = '' !== $search;
		$like       = $has_search ? '%' . $wpdb->esc_like( $search ) . '%' : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table admin list.
		if ( ! $has_status && ! $has_entity && ! $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE module = %s',
					$table,
					$module
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE module = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table,
					$module,
					$limit,
					$offset
				)
			);
		} elseif ( $has_status && ! $has_entity && ! $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE module = %s AND status = %s',
					$table,
					$module,
					$status
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE module = %s AND status = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table,
					$module,
					$status,
					$limit,
					$offset
				)
			);
		} elseif ( ! $has_status && $has_entity && ! $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE module = %s AND entity_id = %d',
					$table,
					$module,
					$entity_id
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE module = %s AND entity_id = %d ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table,
					$module,
					$entity_id,
					$limit,
					$offset
				)
			);
		} elseif ( ! $has_status && ! $has_entity && $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE module = %s AND (pass_uid LIKE %s OR pass_link LIKE %s OR CAST(source_id AS CHAR) LIKE %s OR CAST(user_id AS CHAR) LIKE %s)',
					$table,
					$module,
					$like,
					$like,
					$like,
					$like
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE module = %s AND (pass_uid LIKE %s OR pass_link LIKE %s OR CAST(source_id AS CHAR) LIKE %s OR CAST(user_id AS CHAR) LIKE %s) ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table,
					$module,
					$like,
					$like,
					$like,
					$like,
					$limit,
					$offset
				)
			);
		} elseif ( $has_status && $has_entity && ! $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE module = %s AND status = %s AND entity_id = %d',
					$table,
					$module,
					$status,
					$entity_id
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE module = %s AND status = %s AND entity_id = %d ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table,
					$module,
					$status,
					$entity_id,
					$limit,
					$offset
				)
			);
		} elseif ( $has_status && ! $has_entity && $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE module = %s AND status = %s AND (pass_uid LIKE %s OR pass_link LIKE %s OR CAST(source_id AS CHAR) LIKE %s OR CAST(user_id AS CHAR) LIKE %s)',
					$table,
					$module,
					$status,
					$like,
					$like,
					$like,
					$like
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE module = %s AND status = %s AND (pass_uid LIKE %s OR pass_link LIKE %s OR CAST(source_id AS CHAR) LIKE %s OR CAST(user_id AS CHAR) LIKE %s) ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table,
					$module,
					$status,
					$like,
					$like,
					$like,
					$like,
					$limit,
					$offset
				)
			);
		} elseif ( ! $has_status && $has_entity && $has_search ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE module = %s AND entity_id = %d AND (pass_uid LIKE %s OR pass_link LIKE %s OR CAST(source_id AS CHAR) LIKE %s OR CAST(user_id AS CHAR) LIKE %s)',
					$table,
					$module,
					$entity_id,
					$like,
					$like,
					$like,
					$like
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE module = %s AND entity_id = %d AND (pass_uid LIKE %s OR pass_link LIKE %s OR CAST(source_id AS CHAR) LIKE %s OR CAST(user_id AS CHAR) LIKE %s) ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table,
					$module,
					$entity_id,
					$like,
					$like,
					$like,
					$like,
					$limit,
					$offset
				)
			);
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE module = %s AND status = %s AND entity_id = %d AND (pass_uid LIKE %s OR pass_link LIKE %s OR CAST(source_id AS CHAR) LIKE %s OR CAST(user_id AS CHAR) LIKE %s)',
					$table,
					$module,
					$status,
					$entity_id,
					$like,
					$like,
					$like,
					$like
				)
			);
			$items = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE module = %s AND status = %s AND entity_id = %d AND (pass_uid LIKE %s OR pass_link LIKE %s OR CAST(source_id AS CHAR) LIKE %s OR CAST(user_id AS CHAR) LIKE %s) ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					$table,
					$module,
					$status,
					$entity_id,
					$like,
					$like,
					$like,
					$like,
					$limit,
					$offset
				)
			);
		}

		if ( $count_only ) {
			return array(
				'items' => array(),
				'total' => $total,
			);
		}

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Active passes for a WordPress user.
	 *
	 * @param int $user_id User id.
	 * @return array<int, object>
	 */
	public static function get_active_passes_for_user( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE user_id = %d AND status = %s AND pass_uid <> '' ORDER BY updated_at DESC",
				$table,
				$user_id,
				'active'
			)
		);

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Active passes for a module.
	 *
	 * @param string $module Module slug.
	 * @return array<int, object>
	 */
	public static function get_active_passes_for_module( $module ) {
		global $wpdb;

		$module = sanitize_key( (string) $module );
		if ( '' === $module ) {
			return array();
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE module = %s AND status = %s AND pass_uid <> '' ORDER BY updated_at DESC",
				$table,
				$module,
				'active'
			)
		);

		return is_array( $items ) ? $items : array();
	}

	/**
	 * Decode pass meta JSON from a row.
	 *
	 * @param object $pass_row Pass row.
	 * @return array<string, mixed>
	 */
	public static function get_pass_meta( $pass_row ) {
		if ( ! is_object( $pass_row ) || empty( $pass_row->meta ) ) {
			return array();
		}

		$decoded = json_decode( (string) $pass_row->meta, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Persist pass meta JSON on a row.
	 *
	 * @param object               $pass_row Pass row.
	 * @param array<string, mixed> $meta     Meta array.
	 * @return bool
	 */
	public static function update_pass_meta( $pass_row, array $meta ) {
		global $wpdb;

		if ( ! is_object( $pass_row ) || empty( $pass_row->id ) ) {
			return false;
		}

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$updated = $wpdb->update(
			$table,
			array( 'meta' => wp_json_encode( $meta ) ),
			array( 'id' => (int) $pass_row->id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}
}
