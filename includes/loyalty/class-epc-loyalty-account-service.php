<?php
/**
 * Loyalty customer account persistence.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates loyalty identities and reads cached point totals.
 */
class EPC_Loyalty_Account_Service {

	/**
	 * Create an account when needed and return it.
	 *
	 * This method is safe to call inside a database transaction.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return object|\WP_Error
	 */
	public static function get_or_create( $user_id ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'epc_loyalty_invalid_user', __( 'A registered customer is required for loyalty points.', 'epasscard' ) );
		}

		$table     = EPC_DB::loyalty_accounts_table_name();
		$member_id = self::generate_member_id( $user_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom transactional account table.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (user_id, member_id) VALUES (%d, %s)
				ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)",
				$user_id,
				$member_id
			)
		);

		if ( false === $result ) {
			return new WP_Error( 'epc_loyalty_account_write_failed', __( 'The loyalty account could not be created.', 'epasscard' ) );
		}

		return self::get( $user_id );
	}

	/**
	 * Read an account by WordPress user ID.
	 *
	 * @param int  $user_id WordPress user ID.
	 * @param bool $for_update Lock the row for the current transaction.
	 * @return object|null
	 */
	public static function get( $user_id, $for_update = false ) {
		global $wpdb;

		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return null;
		}

		$table = EPC_DB::loyalty_accounts_table_name();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d LIMIT 1", $user_id );
		if ( $for_update ) {
			$sql .= ' FOR UPDATE';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above; table name is internal.
		$account = $wpdb->get_row( $sql );

		return $account ?: null;
	}

	/**
	 * Stable, non-secret membership identifier suitable for a barcode.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string
	 */
	public static function generate_member_id( $user_id ) {
		$encoded = strtoupper( base_convert( (string) absint( $user_id ), 10, 36 ) );
		return 'EPC-' . str_pad( $encoded, 10, '0', STR_PAD_LEFT );
	}

	/**
	 * Normalize a scanned or typed membership ID.
	 *
	 * @param string $member_id Raw member ID.
	 * @return string Empty when invalid.
	 */
	public static function normalize_member_id( $member_id ) {
		$member_id = strtoupper( preg_replace( '/\s+/', '', (string) $member_id ) );
		$member_id = substr( sanitize_text_field( $member_id ), 0, 64 );
		if ( '' === $member_id || ! preg_match( '/^EPC-[A-Z0-9]{1,60}$/', $member_id ) ) {
			return '';
		}
		return $member_id;
	}

	/**
	 * Read an account by non-secret membership barcode ID.
	 *
	 * @param string $member_id Membership ID.
	 * @return object|null
	 */
	public static function get_by_member_id( $member_id ) {
		global $wpdb;

		$member_id = self::normalize_member_id( $member_id );
		if ( '' === $member_id ) {
			return null;
		}

		$table = EPC_DB::loyalty_accounts_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Unique membership ID lookup.
		$account = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE member_id = %s LIMIT 1",
				$member_id
			)
		);

		return $account ?: null;
	}
}
