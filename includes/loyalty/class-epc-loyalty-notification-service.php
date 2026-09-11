<?php
/**
 * Loyalty email and wallet notifications.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends independently configurable loyalty lifecycle notifications.
 */
class EPC_Loyalty_Notification_Service {

	public const OPTION_KEY = 'epc_loyalty_notifications';

	/**
	 * Register event listeners.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'epc_loyalty_balance_changed', array( __CLASS__, 'on_balance_changed' ), 30, 2 );
		add_action( 'epc_loyalty_tier_reached', array( __CLASS__, 'on_tier_reached' ), 10, 3 );
		add_action( 'epc_loyalty_reward_available', array( __CLASS__, 'on_reward_available' ), 10, 2 );
		add_action( 'epc_loyalty_pass_ready', array( __CLASS__, 'on_pass_ready' ), 10, 2 );
	}

	/**
	 * Supported notification labels.
	 *
	 * @return array<string, string>
	 */
	public static function get_types() {
		return array(
			'pass_ready'       => __( 'Pass ready', 'epasscard' ),
			'points_earned'    => __( 'Points earned', 'epasscard' ),
			'points_reversed'  => __( 'Points reversed', 'epasscard' ),
			'points_expiring'  => __( 'Points expiring', 'epasscard' ),
			'tier_reached'     => __( 'Tier reached', 'epasscard' ),
			'reward_available' => __( 'Reward available', 'epasscard' ),
		);
	}

	/**
	 * Sanitized notification settings merged with defaults.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) && isset( $saved['notifications'] ) && is_array( $saved['notifications'] )
			? $saved['notifications']
			: ( is_array( $saved ) ? $saved : array() );
		$defaults = self::defaults();
		$clean    = array();

		foreach ( self::get_types() as $type => $label ) {
			unset( $label );
			$rule = isset( $saved[ $type ] ) && is_array( $saved[ $type ] ) ? $saved[ $type ] : array();
			$base = $defaults[ $type ];
			$clean[ $type ] = array(
				'email_enabled' => ! empty( $rule['email_enabled'] ),
				'push_enabled'  => ! empty( $rule['push_enabled'] ),
				'subject'       => sanitize_text_field( (string) ( $rule['subject'] ?? $base['subject'] ) ),
				'title'         => sanitize_text_field( (string) ( $rule['title'] ?? $base['title'] ) ),
				'message'       => sanitize_textarea_field( (string) ( $rule['message'] ?? $base['message'] ) ),
			);
			if ( 'points_expiring' === $type ) {
				$clean[ $type ]['days'] = min( 90, max( 1, absint( $rule['days'] ?? $base['days'] ) ) );
			}
		}
		return $clean;
	}

	/**
	 * Save sanitized notification settings.
	 *
	 * @param array<string, array<string, mixed>> $settings Settings.
	 * @return array<string, array<string, mixed>>
	 */
	public static function save_settings( array $settings ) {
		update_option( self::OPTION_KEY, array( 'version' => 1, 'notifications' => $settings ) );
		$clean = self::get_settings();
		update_option( self::OPTION_KEY, array( 'version' => 1, 'notifications' => $clean ) );
		return $clean;
	}

	/**
	 * Notify after a ledger mutation.
	 *
	 * @param object $entry Ledger entry.
	 * @param object $account Account.
	 * @return void
	 */
	public static function on_balance_changed( $entry, $account ) {
		$delta = (int) ( $entry->points_delta ?? 0 );
		if ( 0 === $delta ) {
			return;
		}

		$type = $delta > 0 ? 'points_earned' : 'points_reversed';
		if ( 'point_expiry' === (string) ( $entry->entry_type ?? '' ) ) {
			$type = 'points_reversed';
		}

		self::send(
			$type,
			absint( $account->user_id ?? 0 ),
			array(
				'points'  => number_format_i18n( abs( $delta ) ),
				'balance' => number_format_i18n( (int) ( $account->points_balance ?? 0 ) ),
				'reason'  => (string) ( $entry->description ?? '' ),
			)
		);
	}

	/**
	 * Notify when a tier is reached.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $tier Tier.
	 * @param object               $account Account.
	 * @return void
	 */
	public static function on_tier_reached( $user_id, array $tier, $account ) {
		self::send(
			'tier_reached',
			$user_id,
			array(
				'tier'    => (string) ( $tier['name'] ?? '' ),
				'balance' => number_format_i18n( (int) ( $account->points_balance ?? 0 ) ),
			)
		);
	}

	/**
	 * Notify when a milestone claim becomes available.
	 *
	 * @param object               $claim Claim.
	 * @param array<string, mixed> $milestone Milestone.
	 * @return void
	 */
	public static function on_reward_available( $claim, array $milestone ) {
		self::send(
			'reward_available',
			absint( $claim->user_id ?? 0 ),
			array(
				'reward'     => (string) ( $milestone['name'] ?? __( 'Loyalty reward', 'epasscard' ) ),
				'expires_at' => ! empty( $claim->expires_at ) ? get_date_from_gmt( $claim->expires_at, get_option( 'date_format' ) ) : '',
			)
		);
	}

	/**
	 * Notify when a loyalty pass is ready.
	 *
	 * @param int    $user_id User ID.
	 * @param string $pass_link Wallet pass link.
	 * @return void
	 */
	public static function on_pass_ready( $user_id, $pass_link ) {
		self::send( 'pass_ready', $user_id, array( 'pass_link' => esc_url_raw( (string) $pass_link ) ) );
	}

	/**
	 * Notify about point awards nearing expiry once per ledger entry.
	 *
	 * @return void
	 */
	public static function process_expiring_points() {
		global $wpdb;

		$rule = self::get_settings()['points_expiring'];
		if ( empty( $rule['email_enabled'] ) && empty( $rule['push_enabled'] ) ) {
			return;
		}

		$table = EPC_DB::loyalty_ledger_table_name();
		$now   = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + ( (int) $rule['days'] * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled expiry notification scan.
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE points_delta > 0 AND expires_at IS NOT NULL
				AND expires_at > %s AND expires_at <= %s ORDER BY expires_at ASC LIMIT 500",
				$now,
				$until
			)
		);

		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			$key = 'epc_loyalty_expiry_notice_' . (int) $entry->id;
			if ( get_user_meta( (int) $entry->user_id, $key, true ) ) {
				continue;
			}
			$account = EPC_Loyalty_Account_Service::get( (int) $entry->user_id );
			$outstanding = (int) $entry->points_delta;
			if ( (int) $entry->order_id > 0 ) {
				$outstanding = max(
					0,
					$outstanding - EPC_Loyalty_Ledger_Service::get_reversed_points_for_order( (int) $entry->order_id )
				);
			}
			$points = $account ? min( max( 0, (int) $account->points_balance ), $outstanding ) : 0;
			if ( $points <= 0 ) {
				continue;
			}
			$sent = self::send(
				'points_expiring',
				(int) $entry->user_id,
				array(
					'points'     => number_format_i18n( $points ),
					'expires_at' => get_date_from_gmt( (string) $entry->expires_at, get_option( 'date_format' ) ),
					'balance'    => number_format_i18n( (int) $account->points_balance ),
				)
			);
			if ( $sent ) {
				update_user_meta( (int) $entry->user_id, $key, current_time( 'mysql', true ) );
			}
		}
	}

	/**
	 * Send configured email and/or wallet push.
	 *
	 * @param string               $type Event type.
	 * @param int                  $user_id User ID.
	 * @param array<string, string> $replacements Tags.
	 * @return bool
	 */
	public static function send( $type, $user_id, array $replacements = array() ) {
		$type     = sanitize_key( (string) $type );
		$user_id  = absint( $user_id );
		$settings = self::get_settings();
		$user     = get_userdata( $user_id );

		if ( ! isset( $settings[ $type ] ) || ! $user ) {
			return false;
		}

		$rule = $settings[ $type ];
		$tags = array_merge(
			array(
				'customer_name' => (string) $user->display_name,
				'customer_email'=> (string) $user->user_email,
			),
			$replacements
		);
		$subject = EPC_Pass_Notifications::replace_tags( $rule['subject'], $tags );
		$title   = EPC_Pass_Notifications::replace_tags( $rule['title'], $tags );
		$message = EPC_Pass_Notifications::replace_tags( $rule['message'], $tags );
		$sent    = false;

		if ( ! empty( $rule['email_enabled'] ) && '' !== trim( $subject ) && '' !== trim( $message ) ) {
			$sent = wp_mail( $user->user_email, $subject, $message ) || $sent;
		}
		if ( ! empty( $rule['push_enabled'] ) && '' !== trim( $title ) && '' !== trim( $message ) ) {
			$sent = EPC_Pass_Notifications::send_for_module_source( 'woocommerce-loyalty', $user_id, $title, $message ) || $sent;
		}

		return $sent;
	}

	/**
	 * Default editable copy.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function defaults() {
		return array(
			'pass_ready' => array(
				'subject' => __( 'Your loyalty pass is ready', 'epasscard' ),
				'title'   => __( 'Your loyalty pass is ready', 'epasscard' ),
				'message' => __( 'Hi {customer_name}, your loyalty wallet pass is ready: {pass_link}', 'epasscard' ),
			),
			'points_earned' => array(
				'subject' => __( 'You earned {points} loyalty points', 'epasscard' ),
				'title'   => __( 'Points earned', 'epasscard' ),
				'message' => __( 'You earned {points} points. Your balance is now {balance}.', 'epasscard' ),
			),
			'points_reversed' => array(
				'subject' => __( 'Your loyalty points changed', 'epasscard' ),
				'title'   => __( 'Points updated', 'epasscard' ),
				'message' => __( '{points} points were removed. Your balance is now {balance}. {reason}', 'epasscard' ),
			),
			'points_expiring' => array(
				'subject' => __( '{points} loyalty points expire soon', 'epasscard' ),
				'title'   => __( 'Points expiring soon', 'epasscard' ),
				'message' => __( '{points} points expire on {expires_at}. Your current balance is {balance}.', 'epasscard' ),
				'days'    => 30,
			),
			'tier_reached' => array(
				'subject' => __( 'You reached the {tier} tier', 'epasscard' ),
				'title'   => __( 'New loyalty tier', 'epasscard' ),
				'message' => __( 'Congratulations {customer_name}, you reached the {tier} tier.', 'epasscard' ),
			),
			'reward_available' => array(
				'subject' => __( 'A new loyalty reward is available', 'epasscard' ),
				'title'   => __( 'New loyalty reward', 'epasscard' ),
				'message' => __( 'You unlocked {reward}. Expiry: {expires_at}', 'epasscard' ),
			),
		);
	}
}
