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
	 * Form submissions table name without prefix (DB 1.7.0): the values a form card shows,
	 * for form plugins that keep no entries on the site (WPForms Lite).
	 */
	public const FORM_SUBMISSIONS_TABLE = 'epc_form_submissions';

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
			is_test tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY module_source_unique (module, source_id),
			KEY user_id (user_id),
			KEY pass_uid (pass_uid)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		self::install_loyalty_tables();
		self::install_form_submissions_table();

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
	 * Install the form submissions table (DB 1.7.0).
	 *
	 * One row per form submission that gets a card: only the values the card shows, the
	 * address the card goes to and the card code. The row id is the card's record id.
	 *
	 * @return void
	 */
	private static function install_form_submissions_table() {
		global $wpdb;

		$table           = self::form_submissions_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			module varchar(32) NOT NULL DEFAULT '',
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entry_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(190) NOT NULL DEFAULT '',
			card_code varchar(16) NOT NULL DEFAULT '',
			field_values longtext NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY module_form (module, form_id),
			KEY email (email)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Run schema upgrades when the plugin DB version changes.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( 'epc_db_version', '0' );
		if ( version_compare( $installed, EPC_DB_VERSION, '<' ) ) {
			// Merge duplicate rows and add the unique key before dbDelta runs, so dbDelta
			// never tries to add the unique key to a table that still holds duplicates.
			$unique_ok = self::ensure_pass_unique_key();
			self::install();
			if ( version_compare( $installed, '1.2.0', '<' ) ) {
				self::ensure_source_id_varchar();
				self::migrate_memberpress_source_ids();
			}
			if ( version_compare( $installed, '1.4.0', '<' ) ) {
				// The Halloween 2026 notice was removed in plugin 1.2.0; drop its dismissal flag.
				delete_option( 'epasscard_halloween_deal_notice' );
			}
			if ( version_compare( $installed, '1.5.0', '<' ) ) {
				self::stop_autoloading_mappings();
			}
			// 1.6.0: the is_test column is added by install() above (dbDelta); existing rows read 0.
			// 1.7.0: the form submissions table is added by install() above.
			if ( ! $unique_ok ) {
				// Retry on the next load (a duplicate slipped in between the merge and the ALTER),
				// at most 5 times so a database that refuses the index is not retried forever.
				$attempts = (int) get_option( 'epc_pass_unique_attempts', 0 ) + 1;
				update_option( 'epc_pass_unique_attempts', $attempts, false );
				if ( $attempts < 5 ) {
					update_option( 'epc_db_version', '1.3.0' );
				}
			}
		}
	}

	/**
	 * Make (module, source_id) unique on the passes table (1.4.0).
	 *
	 * One record must own exactly one pass row. Before 1.1.1 two requests handling the same
	 * record at the same moment could each create a pass, leaving duplicate rows (and a second
	 * billable pass on EpassCard). Duplicates are merged first: the row every read already used
	 * (lowest id with a pass) is kept, and the other pass UIDs are saved in its meta under
	 * `duplicate_pass_uids` so they can be reviewed. Nothing is deleted on EpassCard.
	 *
	 * Idempotent and safe to call on every upgrade.
	 *
	 * @return bool True when the unique key exists (or the table does not exist yet).
	 */
	public static function ensure_pass_unique_key() {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $exists !== $table ) {
			return true; // Fresh install: dbDelta creates the table with the unique key.
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		$names   = array();
		foreach ( (array) $indexes as $index ) {
			$names[ (string) ( $index['Key_name'] ?? '' ) ] = true;
		}

		if ( isset( $names['module_source_unique'] ) ) {
			return true;
		}

		self::merge_duplicate_passes();

		$suppress = $wpdb->suppress_errors( true );
		if ( isset( $names['module_source'] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time index migration.
			$result = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX module_source, ADD UNIQUE KEY module_source_unique (module, source_id)', $table ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time index migration.
			$result = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD UNIQUE KEY module_source_unique (module, source_id)', $table ) );
		}
		$wpdb->suppress_errors( $suppress );

		return false !== $result;
	}

	/**
	 * Stop loading template mappings (epc_mappings_*) on every request.
	 *
	 * They are read only when a pass is issued or updated, or on EpassCard admin screens.
	 * New saves already use autoload off (EPC_Module::save_mapping()); this switches rows
	 * written by 1.1.x. Idempotent.
	 *
	 * @return void
	 */
	public static function stop_autoloading_mappings() {
		global $wpdb;

		if ( ! function_exists( 'wp_set_option_autoload_values' ) ) {
			return; // WordPress < 6.4 (EpassCard requires 6.5).
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration lookup.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'epc_mappings_' ) . '%'
			)
		);

		if ( ! empty( $names ) ) {
			wp_set_option_autoload_values( array_fill_keys( array_map( 'strval', $names ), false ) );
		}
	}

	/**
	 * Merge duplicate pass rows (same module + source_id) into one row per record.
	 *
	 * Processes up to 500 duplicated records per call; the unique-key step retries on the
	 * next load until none are left.
	 *
	 * @return int Number of rows removed.
	 */
	private static function merge_duplicate_passes() {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
		$groups = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT module, source_id FROM %i GROUP BY module, source_id HAVING COUNT(*) > 1 LIMIT 500',
				$table
			)
		);

		$removed = 0;
		foreach ( (array) $groups as $group ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE module = %s AND source_id = %s ORDER BY id ASC',
					$table,
					$group->module,
					$group->source_id
				)
			);
			if ( ! is_array( $rows ) || count( $rows ) < 2 ) {
				continue;
			}

			$keep = self::pick_pass_row_to_keep( $rows );
			$extra_uids = array();
			foreach ( $rows as $row ) {
				if ( (int) $row->id === (int) $keep->id ) {
					continue;
				}
				$uid = (string) $row->pass_uid;
				if ( '' !== $uid && $uid !== (string) $keep->pass_uid ) {
					$extra_uids[] = $uid;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration.
				$removed += (int) $wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
			}

			if ( ! empty( $extra_uids ) ) {
				$meta = self::get_pass_meta( $keep );
				$old  = isset( $meta['duplicate_pass_uids'] ) && is_array( $meta['duplicate_pass_uids'] ) ? $meta['duplicate_pass_uids'] : array();
				$meta['duplicate_pass_uids'] = array_values( array_unique( array_merge( $old, $extra_uids ) ) );
				self::update_pass_meta( $keep, $meta );
			}
		}

		return $removed;
	}

	/**
	 * Choose which duplicate row to keep: the lowest id that has a pass UID.
	 *
	 * That is the row get_pass() returned before 1.1.1, so it is the one every later update,
	 * revoke and reminder was written to.
	 *
	 * @param array<int, object> $rows Rows ordered by id ascending.
	 * @return object
	 */
	private static function pick_pass_row_to_keep( array $rows ) {
		foreach ( $rows as $row ) {
			if ( '' !== (string) $row->pass_uid ) {
				return $row;
			}
		}
		return $rows[0];
	}

	/**
	 * Take a cross-request lock for one pass record (MySQL named lock).
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source record id.
	 * @param int        $timeout   Seconds to wait for another request to finish.
	 * @return bool|null True when held, false when another request still holds it after the
	 *                   timeout, null when the database does not support named locks.
	 */
	public static function acquire_pass_lock( $module, $source_id, $timeout = 15 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock, not a data read.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::pass_lock_name( $module, $source_id ), max( 0, (int) $timeout ) ) );
		if ( null === $got ) {
			return null;
		}

		return '1' === (string) $got;
	}

	/**
	 * Release a lock taken with acquire_pass_lock().
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source record id.
	 * @return void
	 */
	public static function release_pass_lock( $module, $source_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Named lock, not a data read.
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::pass_lock_name( $module, $source_id ) ) );
	}

	/**
	 * Lock name for one record: unique per site (table prefix), module and source id; 41 chars.
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source record id.
	 * @return string
	 */
	private static function pass_lock_name( $module, $source_id ) {
		global $wpdb;

		return 'epc_pass_' . md5( $wpdb->prefix . '|' . sanitize_key( (string) $module ) . '|' . self::sanitize_source_id( $source_id ) );
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
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i MODIFY source_id varchar(64) NOT NULL DEFAULT ''", $table ) );
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
	 * Full form submissions table name.
	 *
	 * @return string
	 */
	public static function form_submissions_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::FORM_SUBMISSIONS_TABLE;
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
		);

		$formats = array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' );

		// Test flag (DB 1.6.0): written only when given or inside a test-card call, so an
		// ordinary update never clears it.
		if ( array_key_exists( 'is_test', $data ) ) {
			$row['is_test'] = empty( $data['is_test'] ) ? 0 : 1;
			$formats[]      = '%d';
		} elseif ( class_exists( 'EPC_Pass_Status' ) && EPC_Pass_Status::is_test_context() ) {
			$row['is_test'] = 1;
			$formats[]      = '%d';
		}

		// Meta stays last: update_pass_row() drops the last column when no meta was given.
		$row['meta'] = isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null;
		$formats[]   = '%s';

		if ( $existing ) {
			return self::update_pass_row( (int) $existing, $row, $formats, array_key_exists( 'meta', $data ) );
		}

		// The unique key on (module, source_id) rejects a second row for the same record.
		// If another request inserted it a moment ago, update that row instead.
		$suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table write.
		$inserted = $wpdb->insert(
			$table,
			$row,
			$formats
		);
		$wpdb->suppress_errors( $suppress );

		if ( false === $inserted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table upsert lookup.
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE module = %s AND source_id = %s LIMIT 1',
					$table,
					$module,
					$source
				)
			);
			return $existing ? self::update_pass_row( (int) $existing, $row, $formats, array_key_exists( 'meta', $data ) ) : false;
		}

		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update an existing pass row.
	 *
	 * @param int                  $id       Row id.
	 * @param array<string, mixed> $row      Column values (meta last).
	 * @param array<int, string>   $formats  Formats matching $row.
	 * @param bool                 $has_meta Whether the caller explicitly provided meta.
	 * @return int Row id.
	 */
	private static function update_pass_row( $id, array $row, array $formats, $has_meta ) {
		global $wpdb;

		// Keep stored meta (e.g. sent reminder flags) unless the caller explicitly provides new meta.
		if ( ! $has_meta ) {
			unset( $row['meta'] );
			array_pop( $formats );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$wpdb->update(
			self::table_name(),
			$row,
			array( 'id' => (int) $id ),
			$formats,
			array( '%d' )
		);
		return (int) $id;
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
	 * The newest active, real card of an integration for a user, optionally for one entity
	 * (plan, level) or one record.
	 *
	 * @param string          $module    Module slug.
	 * @param int             $user_id   User id (0 to match by record only).
	 * @param int|null        $entity_id Entity id, or null for any.
	 * @param string|int|null $source_id Record id, or null for any.
	 * @return object|null
	 */
	public static function find_active_card( $module, $user_id, $entity_id = null, $source_id = null ) {
		global $wpdb;

		$module  = sanitize_key( (string) $module );
		$user_id = absint( $user_id );
		if ( '' === $module || ( $user_id <= 0 && null === $source_id ) ) {
			return null;
		}

		$sql  = "SELECT * FROM %i WHERE module = %s AND status = 'active' AND is_test = 0 AND pass_uid <> '' AND pass_link <> ''";
		$args = array( self::table_name(), $module );
		if ( $user_id > 0 ) {
			$sql   .= ' AND user_id = %d';
			$args[] = $user_id;
		}
		if ( null !== $entity_id ) {
			$sql   .= ' AND entity_id = %d';
			$args[] = absint( $entity_id );
		}
		if ( null !== $source_id ) {
			$sql   .= ' AND source_id = %s';
			$args[] = self::sanitize_source_id( $source_id );
		}
		$sql .= ' ORDER BY updated_at DESC LIMIT 1';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table read; $sql is built from fixed fragments above.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ) );
		return $row ? $row : null;
	}

	/**
	 * Which of these records already have a card (a row with a pass id, any status).
	 *
	 * @param string                  $module     Module slug.
	 * @param array<int, string|int> $source_ids Source record ids.
	 * @return array<string, true> Source ids that have a card.
	 */
	public static function source_ids_with_card( $module, array $source_ids ) {
		global $wpdb;

		$module = sanitize_key( (string) $module );
		$ids    = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'sanitize_source_id' ), $source_ids ), 'strlen' ) ) );
		if ( '' === $module || empty( $ids ) ) {
			return array();
		}

		$out = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table read; the IN list is one %s per id.
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT source_id FROM %i WHERE module = %s AND pass_uid <> '' AND source_id IN (" . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')',
					array_merge( array( self::table_name(), $module ), $chunk )
				)
			);
			// phpcs:enable
			foreach ( (array) $found as $source_id ) {
				$out[ (string) $source_id ] = true;
			}
		}
		return $out;
	}

	/**
	 * Record ids of a module's cards that start with a prefix (any status).
	 *
	 * @param string $module Module slug.
	 * @param string $prefix Start of the record id.
	 * @return array<int, string>
	 */
	public static function source_ids_with_prefix( $module, $prefix ) {
		global $wpdb;

		$module = sanitize_key( (string) $module );
		$prefix = (string) $prefix;
		if ( '' === $module || '' === $prefix ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT source_id FROM %i WHERE module = %s AND source_id LIKE %s ORDER BY id ASC',
				self::table_name(),
				$module,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		return array_map( 'strval', (array) $found );
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table admin list; paginated, filtered admin data that must be fresh.
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

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * Without $limit: every active pass, newest update first (1.1.x behaviour).
	 * With $limit: up to $limit passes with id > $after_id, by id ascending.
	 *
	 * @param string $module   Module slug.
	 * @param int    $after_id Return passes after this id (batched mode).
	 * @param int    $limit    Batch size; 0 = no limit.
	 * @return array<int, object>
	 */
	public static function get_active_passes_for_module( $module, $after_id = 0, $limit = 0 ) {
		global $wpdb;

		$module = sanitize_key( (string) $module );
		if ( '' === $module ) {
			return array();
		}

		$table = self::table_name();

		// Batched read for the daily notification job: ascending id after a cursor.
		if ( absint( $limit ) > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read.
			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE module = %s AND status = %s AND pass_uid <> '' AND id > %d ORDER BY id ASC LIMIT %d",
					$table,
					$module,
					'active',
					absint( $after_id ),
					absint( $limit )
				)
			);
			return is_array( $items ) ? $items : array();
		}
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
	 * Mark or unmark a pass row as a test card.
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source id.
	 * @param bool       $is_test   Test card or not.
	 * @return bool True when a row was found and saved.
	 */
	public static function set_pass_test_flag( $module, $source_id, $is_test ) {
		global $wpdb;

		$module    = sanitize_key( (string) $module );
		$source_id = self::sanitize_source_id( $source_id );
		if ( '' === $module || '' === $source_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table write.
		$updated = $wpdb->update(
			self::table_name(),
			array( 'is_test' => $is_test ? 1 : 0 ),
			array(
				'module'    => $module,
				'source_id' => $source_id,
			),
			array( '%d' ),
			array( '%s', '%s' )
		);

		return false !== $updated;
	}

	/**
	 * Count a module's issued passes, leaving test cards out.
	 *
	 * @param string $module Module slug.
	 * @param string $status Stored status to match ('active', 'revoked'), or '' for any.
	 * @return int
	 */
	public static function count_real_passes( $module, $status = '' ) {
		global $wpdb;

		$module = sanitize_key( (string) $module );
		$status = sanitize_key( (string) $status );
		if ( '' === $module ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table count; must be fresh.
		if ( '' === $status ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE module = %s AND is_test = 0",
					self::table_name(),
					$module
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE module = %s AND status = %s AND is_test = 0",
					self::table_name(),
					$module,
					$status
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * Cards of every integration for the Cards list.
	 *
	 * @param array<string, mixed> $args {
	 *     @type string[] $modules  Module slugs to include (required; empty = none).
	 *     @type string   $view     all|active|revoked|test (default all; all and active leave test cards out).
	 *     @type int[]    $user_ids Only these users (from a name / email search), or null for any.
	 *     @type string   $search   Matches pass id, link, record id.
	 *     @type int      $page     Page (1-based).
	 *     @type int      $per_page Rows per page (max 100).
	 * }
	 * @return array{total: int, items: array<int, object>}
	 */
	public static function query_all_passes( array $args ) {
		global $wpdb;

		$modules = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) ( $args['modules'] ?? array() ) ) ) ) );
		if ( empty( $modules ) ) {
			return array(
				'total' => 0,
				'items' => array(),
			);
		}

		$view     = sanitize_key( (string) ( $args['view'] ?? 'all' ) );
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$limit    = max( 1, min( 100, absint( $args['per_page'] ?? 20 ) ) );
		$search   = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
		$user_ids = isset( $args['user_ids'] ) && is_array( $args['user_ids'] ) ? array_values( array_filter( array_map( 'absint', $args['user_ids'] ) ) ) : null;

		// Only fixed SQL fragments and %s / %d placeholders are joined; every value is a prepare() argument.
		$where  = array( 'module IN (' . implode( ',', array_fill( 0, count( $modules ), '%s' ) ) . ')' );
		$params = $modules;

		if ( 'test' === $view ) {
			$where[] = 'is_test = 1';
		} else {
			$where[] = 'is_test = 0';
			if ( 'active' === $view || 'revoked' === $view ) {
				$where[]  = 'status = %s';
				$params[] = $view;
			}
		}

		$or = array();
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$or[] = '(pass_uid LIKE %s OR pass_link LIKE %s OR source_id LIKE %s)';
			array_push( $params, $like, $like, $like );
		}
		if ( null !== $user_ids && ! empty( $user_ids ) ) {
			$or[]   = 'user_id IN (' . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $user_ids );
		}
		if ( ! empty( $or ) ) {
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		} elseif ( null !== $user_ids ) {
			// A name / email search that matched no user and nothing else.
			$where[] = '1 = 0';
		}

		$sql_where = implode( ' AND ', $where );
		$table     = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql_where holds only fixed fragments and placeholders (see above), passed as one array; admin list that must be fresh.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$sql_where}", array_merge( array( $table ), $params ) ) );
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$sql_where} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( array( $table ), $params, array( $limit, ( $page - 1 ) * $limit ) ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'total' => $total,
			'items' => is_array( $items ) ? $items : array(),
		);
	}

	/**
	 * Real (non-test) active cards whose link was emailed at least once.
	 *
	 * @return int
	 */
	public static function count_emailed_real_passes() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table count; must be fresh.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE is_test = 0 AND status = 'active' AND pass_uid <> '' AND meta LIKE %s", self::table_name(), '%' . $wpdb->esc_like( '"emailed_at"' ) . '%' ) );
	}

	/**
	 * Active real (non-test) cards of a module per plan, event or product.
	 *
	 * @param string $module Module slug.
	 * @return array<int, int> entity id => cards
	 */
	public static function count_active_real_passes_by_entity( $module ) {
		global $wpdb;

		$module = sanitize_key( (string) $module );
		if ( '' === $module ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table count; must be fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT entity_id, COUNT(*) AS cards FROM %i WHERE module = %s AND status = 'active' AND is_test = 0 AND pass_uid <> '' GROUP BY entity_id", self::table_name(), $module ) );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->entity_id ] = (int) $row->cards;
		}
		return $out;
	}

	/**
	 * Count real (non-test) issued passes of every integration.
	 *
	 * @param string $status Stored status ('active', 'revoked'), or '' for any.
	 * @return int
	 */
	public static function count_all_real_passes( $status = '' ) {
		global $wpdb;

		$status = sanitize_key( (string) $status );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table count; must be fresh.
		if ( '' === $status ) {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE is_test = 0 AND pass_uid <> ''", self::table_name() ) );
		} else {
			$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status = %s AND is_test = 0 AND pass_uid <> ''", self::table_name(), $status ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * Whether a real (non-test) card was created at least N days ago (database clock, which
	 * also fills created_at).
	 *
	 * @param int $days Days.
	 * @return bool
	 */
	public static function has_real_pass_older_than_days( $days ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read; must be fresh.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE is_test = 0 AND pass_uid <> '' AND created_at <= NOW() - INTERVAL %d DAY LIMIT 1", self::table_name(), max( 1, absint( $days ) ) ) );
		return null !== $id;
	}

	/**
	 * Whole days since the first real (non-test) card was created (database clock), or null
	 * when there is none.
	 *
	 * @return int|null
	 */
	public static function first_real_pass_age_days() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table read; must be fresh.
		$days = $wpdb->get_var( $wpdb->prepare( "SELECT DATEDIFF( NOW(), MIN(created_at) ) FROM %i WHERE is_test = 0 AND pass_uid <> ''", self::table_name() ) );
		return null === $days ? null : max( 0, (int) $days );
	}

	/**
	 * Real (non-test) passes created in the last N days, for every integration.
	 *
	 * Compared with the database clock, which also fills created_at.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public static function count_real_passes_last_days( $days ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table count; must be fresh.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE is_test = 0 AND pass_uid <> '' AND created_at >= NOW() - INTERVAL %d DAY", self::table_name(), max( 1, absint( $days ) ) ) );
	}

	/**
	 * Number of real (non-test) passes of the given modules with a row id below $id.
	 *
	 * Revoked passes count: they were real cards once.
	 *
	 * @param array<int, string> $modules Module slugs.
	 * @param int                $id      Row id.
	 * @return int
	 */
	public static function count_real_passes_before( array $modules, $id ) {
		global $wpdb;

		$modules = array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', $modules ) ) ) );
		if ( empty( $modules ) ) {
			return 0;
		}

		$args = array_merge( array( self::table_name() ), $modules, array( absint( $id ) ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table count; must be fresh.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE module IN (" . implode( ', ', array_fill( 0, count( $modules ), '%s' ) ) . ") AND is_test = 0 AND pass_uid <> '' AND id < %d",
				$args
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
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
