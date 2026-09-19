<?php
/**
 * MemberPress integration module.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues EpassCard passes for MemberPress memberships.
 */
class EPC_Module_MemberPress extends EPC_Module {

	/**
	 * Source key prefix for subscriptions.
	 */
	public const SOURCE_SUB = 'sub';

	/**
	 * Source key prefix for one-off / lifetime transactions.
	 */
	public const SOURCE_TXN = 'txn';

	/**
	 * Build a namespaced MemberPress source key.
	 *
	 * @param string $type sub|txn.
	 * @param int    $id   Record id.
	 * @return string Empty when invalid.
	 */
	public static function encode_source_key( $type, $id ) {
		$type = sanitize_key( (string) $type );
		$id   = absint( $id );
		if ( ! in_array( $type, array( self::SOURCE_SUB, self::SOURCE_TXN ), true ) || $id <= 0 ) {
			return '';
		}

		return $type . '_' . $id;
	}

	/**
	 * Parse a MemberPress source key.
	 *
	 * @param mixed $source_id Source key.
	 * @return array{type: string, id: int}|null
	 */
	public static function parse_source_key( $source_id ) {
		$source_id = EPC_DB::sanitize_source_id( $source_id );
		if ( '' === $source_id ) {
			return null;
		}

		if ( preg_match( '/^(sub|txn)_(\d+)$/', $source_id, $matches ) ) {
			return array(
				'type' => $matches[1],
				'id'   => absint( $matches[2] ),
			);
		}

		return null;
	}

	/**
	 * Source key for a MemberPress transaction (subscription id preferred).
	 *
	 * @param MeprTransaction $txn Transaction.
	 * @return string
	 */
	public static function source_key_from_transaction( $txn ) {
		if ( ! $txn instanceof MeprTransaction || empty( $txn->id ) ) {
			return '';
		}

		$subscription_id = (int) $txn->subscription_id;
		if ( $subscription_id > 0 ) {
			return self::encode_source_key( self::SOURCE_SUB, $subscription_id );
		}

		return self::encode_source_key( self::SOURCE_TXN, (int) $txn->id );
	}

	/**
	 * @inheritDoc
	 */
	public function get_slug() {
		return 'memberpress';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label() {
		return __( 'MemberPress', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function is_available() {
		return epc_is_memberpress_active();
	}

	/**
	 * @inheritDoc
	 */
	public function get_unavailable_message() {
		return __(
			'MemberPress is not installed or activated. Install MemberPress, activate it, then return here to map pass templates.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_source_fields() {
		return array(
			'user_display_name' => __( 'Display name', 'epasscard' ),
			'user_email'        => __( 'Email', 'epasscard' ),
			'user_first_name'   => __( 'First name', 'epasscard' ),
			'user_last_name'    => __( 'Last name', 'epasscard' ),
			'user_full_name'    => __( 'Full name', 'epasscard' ),
			'membership_id'     => __( 'Membership ID', 'epasscard' ),
			'membership_title'  => __( 'Membership product', 'epasscard' ),
			'membership_status' => __( 'Status', 'epasscard' ),
			'membership_start'  => __( 'Start date', 'epasscard' ),
			'membership_expires'=> __( 'Expiration date', 'epasscard' ),
			'transaction_id'    => __( 'Latest transaction ID', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function render_module_settings() {
		if ( ! $this->is_available() ) {
			return;
		}

		$this->render_native_reminder_notice(
			admin_url( 'edit.php?post_type=mp-reminder' ),
			__( 'Open MemberPress reminders (timing)', 'epasscard' )
		);
		$this->render_push_notification_settings();
	}

	/**
	 * @inheritDoc
	 */
	public function uses_native_reminder_timing() {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_types() {
		return array(
			'sub_expires'    => __( 'Membership / subscription expiring', 'epasscard' ),
			'sub_renews'     => __( 'Subscription renewing', 'epasscard' ),
			'sub_trial_ends' => __( 'Trial ending', 'epasscard' ),
			'cc_expires'     => __( 'Saved card expiring', 'epasscard' ),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_type_description( $type ) {
		$descriptions = array(
			'sub_expires'    => __( 'Sent according to membership end date.', 'epasscard' ),
			'sub_renews'     => __( 'Notification sent before next payment is charged.', 'epasscard' ),
			'sub_trial_ends' => __( 'Sent when trial access period is nearing completion.', 'epasscard' ),
			'cc_expires'     => __( 'Alert user to update billing method.', 'epasscard' ),
		);

		return $descriptions[ $type ] ?? '';
	}

	/**
	 * @inheritDoc
	 */
	public function get_default_notification_rules() {
		return array(
			'sub_expires' => array(
				'enabled' => false,
				'title'   => __( 'Membership ending soon', 'epasscard' ),
				'message' => __( 'Your {membership_title} membership expires on {membership_expires}. Renew to keep your pass active.', 'epasscard' ),
			),
			'sub_renews' => array(
				'enabled' => false,
				'title'   => __( 'Membership renewal coming up', 'epasscard' ),
				'message' => __( 'Your {membership_title} membership renews on {next_payment_date}.', 'epasscard' ),
			),
			'sub_trial_ends' => array(
				'enabled' => false,
				'title'   => __( 'Trial ending soon', 'epasscard' ),
				'message' => __( 'Your {membership_title} trial ends on {trial_end_date}.', 'epasscard' ),
			),
			'cc_expires' => array(
				'enabled' => false,
				'title'   => __( 'Update your payment card', 'epasscard' ),
				'message' => __( 'The card on file for {membership_title} expires on {card_expiry_date}. Update it to avoid interruption.', 'epasscard' ),
			),
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_notification_template_tags() {
		return array(
			'membership_title',
			'membership_expires',
			'next_payment_date',
			'trial_end_date',
			'card_expiry_date',
			'user_display_name',
			'user_first_name',
			'user_last_name',
			'user_full_name',
			'user_email',
		);
	}

	/**
	 * Mirror MemberPress reminder emails with wallet push notifications.
	 *
	 * @param bool       $disable  Whether the reminder email is disabled.
	 * @param MeprReminder $reminder Reminder config.
	 * @param MeprUser   $usr      Member.
	 * @param MeprProduct $prd     Membership product.
	 * @param MeprEvent  $event    Reminder event.
	 * @return bool
	 */
	public function maybe_send_reminder_push( $disable, $reminder, $usr, $prd, $event ) {
		if ( $disable || ! EPC_Api_Client::is_configured() || ! $reminder instanceof MeprReminder || ! $usr instanceof MeprUser || ! $event instanceof MeprEvent ) {
			return $disable;
		}

		$product_id = ( $prd instanceof MeprProduct && ! empty( $prd->ID ) ) ? (int) $prd->ID : 0;
		if ( ! $this->reminder_applies_to_product( $reminder, $product_id ) ) {
			return $disable;
		}

		$type = $this->get_notification_type_for_reminder( $reminder );
		if ( '' === $type ) {
			return $disable;
		}

		$copy = $this->build_push_notification_copy( $type, $this->build_memberpress_push_replacements( $usr, $prd, $event ) );
		if ( null === $copy ) {
			return $disable;
		}

		$pass = $this->find_pass_for_reminder_event( (int) $usr->ID, $event, $product_id );
		if ( $pass ) {
			EPC_Pass_Notifications::send_for_pass( $pass, $copy['title'], $copy['message'] );
		}

		return $disable;
	}

	/**
	 * Map a MemberPress reminder event to an EpassCard notification type.
	 *
	 * @param MeprReminder $reminder Reminder.
	 * @return string
	 */
	private function get_notification_type_for_reminder( $reminder ) {
		$map = array(
			'sub-expires'    => 'sub_expires',
			'sub-renews'     => 'sub_renews',
			'cc-expires'     => 'cc_expires',
			'sub-trial-ends' => 'sub_trial_ends',
		);

		return $map[ (string) $reminder->trigger_event ] ?? '';
	}

	/**
	 * Placeholder values for MemberPress push copy.
	 *
	 * @param MeprUser    $usr   Member.
	 * @param MeprProduct $prd   Product.
	 * @param MeprEvent   $event Event.
	 * @return array<string, string>
	 */
	private function build_memberpress_push_replacements( $usr, $prd, $event ) {
		$txn = null;
		$sub = null;

		if ( 'transactions' === (string) $event->evt_id_type && class_exists( 'MeprTransaction' ) ) {
			$txn = new MeprTransaction( (int) $event->evt_id );
			if ( empty( $txn->id ) ) {
				$txn = null;
			} elseif ( ! empty( $txn->subscription_id ) && class_exists( 'MeprSubscription' ) ) {
				$linked = new MeprSubscription( (int) $txn->subscription_id );
				if ( ! empty( $linked->id ) ) {
					$sub = $linked;
				}
			}
		} elseif ( 'subscriptions' === (string) $event->evt_id_type && class_exists( 'MeprSubscription' ) ) {
			$sub = new MeprSubscription( (int) $event->evt_id );
			if ( empty( $sub->id ) ) {
				$sub = null;
			} elseif ( method_exists( $sub, 'latest_txn' ) ) {
				$latest = $sub->latest_txn();
				if ( $latest instanceof MeprTransaction ) {
					$txn = $latest;
				}
			}
		}

		$product_id = ( $prd instanceof MeprProduct && ! empty( $prd->ID ) ) ? (int) $prd->ID : 0;
		if ( $product_id <= 0 && $sub instanceof MeprSubscription ) {
			$product_id = (int) $sub->product_id;
		}
		if ( $product_id <= 0 && $txn instanceof MeprTransaction ) {
			$product_id = (int) $txn->product_id;
		}

		$membership_ts = 0;
		$renew_ts      = 0;
		$trial_ts      = 0;
		$card_ts       = 0;

		if ( $txn instanceof MeprTransaction ) {
			$membership_ts = $this->get_memberpress_date_timestamp( $txn->expires_at ?? '' );
		}
		if ( $sub instanceof MeprSubscription ) {
			if ( $membership_ts <= 0 ) {
				$membership_ts = $this->get_memberpress_date_timestamp( $sub->expires_at ?? '' );
			}
			$renew_ts = $this->get_memberpress_date_timestamp( $sub->expires_at ?? '' );
			if ( method_exists( $sub, 'trial_expires_at' ) && ! empty( $sub->trial ) ) {
				$trial_ts = (int) $sub->trial_expires_at();
			}
			$card_ts = $this->get_memberpress_card_expiry_timestamp( $sub );
		}

		return $this->memberpress_push_replacements_from_records( $usr, $prd, $sub, $txn, $membership_ts, $renew_ts, $trial_ts, $card_ts );
	}

	/**
	 * Build placeholder map from MemberPress records.
	 *
	 * @param MeprUser             $usr           Member.
	 * @param MeprProduct          $prd           Product.
	 * @param MeprSubscription|null $sub          Subscription.
	 * @param MeprTransaction|null  $txn          Transaction.
	 * @param int                  $membership_ts Membership expiry timestamp.
	 * @param int                  $renew_ts      Renewal timestamp.
	 * @param int                  $trial_ts      Trial end timestamp.
	 * @param int                  $card_ts       Card expiry timestamp.
	 * @return array<string, string>
	 */
	private function memberpress_push_replacements_from_records( $usr, $prd, $sub, $txn, $membership_ts = 0, $renew_ts = 0, $trial_ts = 0, $card_ts = 0 ) {
		$product_id = ( $prd instanceof MeprProduct && ! empty( $prd->ID ) ) ? (int) $prd->ID : 0;
		if ( $product_id <= 0 && $sub instanceof MeprSubscription ) {
			$product_id = (int) $sub->product_id;
		}
		if ( $product_id <= 0 && $txn instanceof MeprTransaction ) {
			$product_id = (int) $txn->product_id;
		}

		if ( $membership_ts <= 0 && $renew_ts <= 0 && $trial_ts <= 0 && $card_ts <= 0 ) {
			if ( $txn instanceof MeprTransaction ) {
				$membership_ts = $this->get_memberpress_date_timestamp( $txn->expires_at ?? '' );
			}
			if ( $sub instanceof MeprSubscription ) {
				if ( $membership_ts <= 0 ) {
					$membership_ts = $this->get_memberpress_date_timestamp( $sub->expires_at ?? '' );
				}
				$renew_ts = $this->get_memberpress_date_timestamp( $sub->expires_at ?? '' );
				if ( method_exists( $sub, 'trial_expires_at' ) && ! empty( $sub->trial ) ) {
					$trial_ts = (int) $sub->trial_expires_at();
				}
				$card_ts = $this->get_memberpress_card_expiry_timestamp( $sub );
			}
		}

		$first = (string) get_user_meta( (int) $usr->ID, 'first_name', true );
		$last  = (string) get_user_meta( (int) $usr->ID, 'last_name', true );

		return array(
			'membership_title'   => $product_id > 0 ? $this->get_entity_label( $product_id ) : '',
			'membership_expires' => epc_format_pass_expiry_timestamp( $membership_ts ),
			'next_payment_date'  => $renew_ts > 0 ? wp_date( get_option( 'date_format' ), $renew_ts ) : '',
			'trial_end_date'     => $trial_ts > 0 ? wp_date( get_option( 'date_format' ), $trial_ts ) : '',
			'card_expiry_date'   => $card_ts > 0 ? wp_date( get_option( 'date_format' ), $card_ts ) : '',
			'user_display_name'  => (string) $usr->display_name,
			'user_first_name'    => $first,
			'user_last_name'     => $last,
			'user_full_name'     => epc_format_user_full_name( $first, $last, (string) $usr->display_name ),
			'user_email'         => (string) $usr->user_email,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function build_push_replacements_for_pass( $pass_row ) {
		if ( ! class_exists( 'MeprUser' ) || ! is_object( $pass_row ) ) {
			return array();
		}

		$usr = new MeprUser( (int) $pass_row->user_id );
		$prd = new MeprProduct( (int) $pass_row->entity_id );
		$sub = null;
		$txn = null;

		$parsed = self::parse_source_key( $pass_row->source_id ?? '' );
		if ( $parsed ) {
			if ( self::SOURCE_SUB === $parsed['type'] && class_exists( 'MeprSubscription' ) ) {
				$candidate = new MeprSubscription( $parsed['id'] );
				if ( ! empty( $candidate->id ) ) {
					$sub = $candidate;
				}
			} elseif ( self::SOURCE_TXN === $parsed['type'] && class_exists( 'MeprTransaction' ) ) {
				$candidate = new MeprTransaction( $parsed['id'] );
				if ( ! empty( $candidate->id ) ) {
					$txn = $candidate;
				}
			}
		} elseif ( class_exists( 'MeprSubscription' ) ) {
			// Legacy bare numeric source_id.
			$legacy_id = absint( $pass_row->source_id );
			$candidate = new MeprSubscription( $legacy_id );
			if ( ! empty( $candidate->id ) ) {
				$sub = $candidate;
			} elseif ( class_exists( 'MeprTransaction' ) ) {
				$candidate = new MeprTransaction( $legacy_id );
				if ( ! empty( $candidate->id ) ) {
					$txn = $candidate;
				}
			}
		}

		if ( $txn && ! empty( $txn->subscription_id ) && class_exists( 'MeprSubscription' ) ) {
			$linked = new MeprSubscription( (int) $txn->subscription_id );
			if ( ! empty( $linked->id ) ) {
				$sub = $linked;
			}
		} elseif ( $sub && method_exists( $sub, 'latest_txn' ) ) {
			$latest = $sub->latest_txn();
			if ( $latest instanceof MeprTransaction ) {
				$txn = $latest;
			}
		}

		return $this->memberpress_push_replacements_from_records( $usr, $prd, $sub, $txn );
	}

	/**
	 * Convert MemberPress datetime to timestamp.
	 *
	 * @param string $value Raw datetime.
	 * @return int
	 */
	private function get_memberpress_date_timestamp( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return 0;
		}

		if ( class_exists( 'MeprUtils' ) && $value === MeprUtils::db_lifetime() ) {
			return 0;
		}

		$ts = strtotime( $value );
		return $ts ? (int) $ts : 0;
	}

	/**
	 * Last day of saved card expiry month (Unix timestamp).
	 *
	 * @param MeprSubscription $sub Subscription.
	 * @return int
	 */
	private function get_memberpress_card_expiry_timestamp( $sub ) {
		if ( ! $sub instanceof MeprSubscription ) {
			return 0;
		}

		$month = absint( $sub->cc_exp_month );
		$year  = absint( $sub->cc_exp_year );
		if ( $year > 0 && $year < 100 ) {
			$year += 2000;
		}
		if ( $month < 1 || $month > 12 || $year < 2000 ) {
			return 0;
		}

		$dt = DateTimeImmutable::createFromFormat( 'Y-n-j', $year . '-' . $month . '-1', wp_timezone() );
		if ( ! $dt ) {
			return 0;
		}

		return (int) $dt->modify( 'last day of this month' )->setTime( 23, 59, 59 )->getTimestamp();
	}

	/**
	 * Whether a reminder is limited to specific membership products.
	 *
	 * @param MeprReminder $reminder   Reminder.
	 * @param int          $product_id Product id.
	 * @return bool
	 */
	private function reminder_applies_to_product( $reminder, $product_id ) {
		if ( empty( $reminder->filter_products ) || empty( $reminder->products ) || ! is_array( $reminder->products ) ) {
			return true;
		}

		if ( $product_id <= 0 ) {
			return false;
		}

		$allowed = array_map( 'absint', $reminder->products );
		return in_array( $product_id, $allowed, true );
	}

	/**
	 * Find an active pass for a MemberPress reminder event.
	 *
	 * @param int       $user_id    User id.
	 * @param MeprEvent $event      Reminder event.
	 * @param int       $product_id Membership product id.
	 * @return object|null
	 */
	private function find_pass_for_reminder_event( $user_id, $event, $product_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return null;
		}

		$candidate_source_ids = array();

		if ( 'transactions' === (string) $event->evt_id_type && class_exists( 'MeprTransaction' ) ) {
			$txn = new MeprTransaction( (int) $event->evt_id );
			if ( ! empty( $txn->id ) ) {
				$key = self::source_key_from_transaction( $txn );
				if ( '' !== $key ) {
					$candidate_source_ids[] = $key;
				}
				// Legacy bare ids (pre-1.2.0).
				$candidate_source_ids[] = (string) (int) $txn->id;
				if ( ! empty( $txn->subscription_id ) ) {
					$candidate_source_ids[] = (string) (int) $txn->subscription_id;
				}
			}
		} elseif ( 'subscriptions' === (string) $event->evt_id_type && class_exists( 'MeprSubscription' ) ) {
			$sub = new MeprSubscription( (int) $event->evt_id );
			if ( ! empty( $sub->id ) ) {
				$candidate_source_ids[] = self::encode_source_key( self::SOURCE_SUB, (int) $sub->id );
				$candidate_source_ids[] = (string) (int) $sub->id;
			}
		}

		foreach ( array_unique( array_filter( $candidate_source_ids ) ) as $source_id ) {
			$pass = EPC_DB::get_pass( $this->get_slug(), $source_id );
			if ( $pass && 'active' === (string) $pass->status && ! empty( $pass->pass_uid ) ) {
				if ( $product_id <= 0 || (int) $pass->entity_id === $product_id ) {
					return $pass;
				}
			}
		}

		foreach ( EPC_DB::get_active_passes_for_user( $user_id ) as $pass ) {
			if ( (string) $pass->module !== $this->get_slug() ) {
				continue;
			}

			if ( $product_id > 0 && (int) $pass->entity_id !== $product_id ) {
				continue;
			}

			return $pass;
		}

		return null;
	}

	/**
	 * Register MemberPress reminder hooks.
	 *
	 * @return void
	 */
	private function register_reminder_push_hooks() {
		$events = array(
			'sub-expires',
			'sub-renews',
			'cc-expires',
			'sub-trial-ends',
		);

		foreach ( $events as $event ) {
			add_filter(
				'mepr_' . $event . '_reminder_disable',
				array( $this, 'maybe_send_reminder_push' ),
				20,
				5
			);
		}
	}

	/**
	 * @inheritDoc
	 */
	public function get_mappable_entities() {
		if ( ! class_exists( 'MeprProduct' ) ) {
			return array();
		}

		$products = MeprProduct::get_all();
		$out      = array();

		foreach ( $products as $product ) {
			if ( ! $product instanceof MeprProduct ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $product->ID,
				'label' => (string) $product->post_title,
			);
		}

		return $out;
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_column_label() {
		return __( 'Membership', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_empty_entities_message() {
		return __(
			'Create a membership in MemberPress (Memberships → Add New), then return here to map a pass template.',
			'epasscard'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_url() {
		return admin_url( 'post-new.php?post_type=memberpressproduct' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_create_entity_label() {
		return __( 'Create membership', 'epasscard' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_entity_label( $entity_id ) {
		$post = get_post( absint( $entity_id ) );
		return $post ? (string) $post->post_title : '#' . absint( $entity_id );
	}

	/**
	 * @inheritDoc
	 */
	public function sync_by_source_id( $source_id, $mode = 'sync' ) {
		$parsed = self::parse_source_key( $source_id );

		if ( $parsed && self::SOURCE_TXN === $parsed['type'] && class_exists( 'MeprTransaction' ) ) {
			$txn = new MeprTransaction( $parsed['id'] );
			if ( ! empty( $txn->id ) ) {
				return $this->sync_from_transaction( $txn, $mode );
			}
		}

		if ( $parsed && self::SOURCE_SUB === $parsed['type'] && class_exists( 'MeprSubscription' ) ) {
			$sub = new MeprSubscription( $parsed['id'] );
			if ( ! empty( $sub->id ) && method_exists( $sub, 'latest_txn' ) ) {
				$txn = $sub->latest_txn();
				if ( $txn instanceof MeprTransaction ) {
					return $this->sync_from_transaction( $txn, $mode );
				}
			}
		}

		// Legacy bare numeric keys (pre-namespaced). Prefer subscription, then one-off txn.
		$legacy_id = absint( EPC_DB::sanitize_source_id( $source_id ) );
		if ( $legacy_id > 0 && ! $parsed ) {
			if ( class_exists( 'MeprSubscription' ) ) {
				$sub = new MeprSubscription( $legacy_id );
				if ( ! empty( $sub->id ) && method_exists( $sub, 'latest_txn' ) ) {
					$txn = $sub->latest_txn();
					if ( $txn instanceof MeprTransaction ) {
						return $this->sync_from_transaction( $txn, $mode );
					}
				}
			}

			if ( class_exists( 'MeprTransaction' ) ) {
				$txn = new MeprTransaction( $legacy_id );
				if ( ! empty( $txn->id ) && empty( $txn->subscription_id ) ) {
					return $this->sync_from_transaction( $txn, $mode );
				}
			}
		}

		return new WP_Error( 'epc_invalid_source', __( 'Membership record not found.', 'epasscard' ) );
	}

	/**
	 * @inheritDoc
	 */
	protected function register_event_hooks() {
		$this->register_reminder_push_hooks();

		add_action( 'mepr-event-transaction-completed', array( $this, 'on_transaction_completed' ), 10, 1 );
		add_action( 'mepr-event-subscription-stopped', array( $this, 'on_subscription_stopped' ), 10, 1 );
		add_action( 'mepr-account-subscriptions-table-row-action', array( $this, 'maybe_sync_existing' ), 10, 1 );

		add_filter( 'mepr_admin_members_cols', array( $this, 'members_list_columns' ) );
		add_action( 'mepr_members_list_table_row', array( $this, 'render_members_list_cell' ), 10, 4 );
	}

	/**
	 * Add EpassCard column to MemberPress members list.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function members_list_columns( $columns ) {
		if ( ! $this->current_user_can_manage_passes() ) {
			return $columns;
		}

		$columns['col_epasscard_pass'] = __( 'EpassCard', 'epasscard' );

		return $columns;
	}

	/**
	 * Render EpassCard column on MemberPress members list.
	 *
	 * @param string $attributes         TD attributes HTML.
	 * @param object $rec                Member row.
	 * @param string $column_name        Column key.
	 * @param string $column_display_name Column label.
	 * @return void
	 */
	public function render_members_list_cell( $attributes, $rec, $column_name, $column_display_name ) {
		unset( $column_display_name );

		if ( 'col_epasscard_pass' !== $column_name || ! is_object( $rec ) || empty( $rec->ID ) ) {
			return;
		}

		$html  = $this->render_pass_actions_for_user( (int) $rec->ID );
		$class = '';
		if ( is_string( $attributes ) && preg_match( '/\bclass=(["\'])(.*?)\1/i', $attributes, $matches ) ) {
			$class = $matches[2];
		}

		printf(
			'<td class="%1$s">%2$s</td>',
			esc_attr( $class ),
			wp_kses( (string) $html, EPC_Module::get_pass_action_allowed_html() )
		);
	}

	/**
	 * @inheritDoc
	 */
	protected function get_pass_action_source_ids_for_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! class_exists( 'MeprUser' ) ) {
			return array();
		}

		$source_ids = array();
		global $wpdb;

		$table = EPC_DB::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table lookup; not cacheable per request.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_id FROM %i WHERE module = %s AND user_id = %d AND pass_uid <> '' ORDER BY updated_at DESC",
				$table,
				$this->get_slug(),
				$user_id
			)
		);

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$key = EPC_DB::sanitize_source_id( $row->source_id ?? '' );
				if ( '' !== $key ) {
					$source_ids[] = $key;
				}
			}
		}

		$usr  = new MeprUser( $user_id );
		$txns = $usr->active_product_subscriptions( 'transactions' );
		if ( is_array( $txns ) ) {
			foreach ( $txns as $txn ) {
				if ( ! $txn instanceof MeprTransaction ) {
					continue;
				}

				$product_id = (int) $txn->product_id;
				if ( empty( $this->get_mapping( $product_id )['template_uid'] ) ) {
					continue;
				}

				$key = self::source_key_from_transaction( $txn );
				if ( '' !== $key ) {
					$source_ids[] = $key;
				}
			}
		}

		return array_values( array_unique( array_filter( $source_ids ) ) );
	}

	/**
	 * @inheritDoc
	 */
	public function should_enqueue_pass_action_assets( $hook ) {
		if ( parent::should_enqueue_pass_action_assets( $hook ) ) {
			return true;
		}

		return 'memberpress_page_memberpress-members' === $hook;
	}

	/**
	 * Sync pass when a transaction completes (new or renewal).
	 *
	 * @param MeprEvent $event MemberPress event.
	 * @return void
	 */
	public function on_transaction_completed( $event ) {
		if ( ! $event instanceof MeprEvent || ! class_exists( 'MeprTransaction' ) ) {
			return;
		}

		$txn = $event->get_data();
		if ( ! $txn instanceof MeprTransaction ) {
			return;
		}

		$this->sync_from_transaction( $txn, 'sync' );
	}

	/**
	 * Revoke local pass record when subscription stops.
	 *
	 * @param MeprEvent $event MemberPress event.
	 * @return void
	 */
	public function on_subscription_stopped( $event ) {
		if ( ! $event instanceof MeprEvent ) {
			return;
		}

		$sub = $event->get_data();
		if ( ! $sub instanceof MeprSubscription ) {
			return;
		}

		EPC_Pass_Service::revoke_pass( $this->get_slug(), self::encode_source_key( self::SOURCE_SUB, (int) $sub->id ) );
		// Legacy bare numeric key (pre-1.2.0).
		EPC_Pass_Service::revoke_pass( $this->get_slug(), (string) (int) $sub->id );
	}

	/**
	 * Placeholder for future row actions.
	 *
	 * @param mixed $unused Unused hook arg.
	 * @return void
	 */
	public function maybe_sync_existing( $unused ) {
		// Reserved for manual re-sync UI.
	}

	/**
	 * Rename a pre-1.2.0 bare-numeric pass row to a namespaced key when ownership matches.
	 *
	 * @param string $source_key Namespaced source key.
	 * @param int    $user_id    Expected WP user id.
	 * @return void
	 */
	private function claim_legacy_source_row( $source_key, $user_id ) {
		global $wpdb;

		$parsed = self::parse_source_key( $source_key );
		$user_id = absint( $user_id );
		if ( ! $parsed || $user_id <= 0 ) {
			return;
		}

		if ( EPC_DB::get_pass( $this->get_slug(), $source_key ) ) {
			return;
		}

		$legacy = EPC_DB::get_pass( $this->get_slug(), (string) $parsed['id'] );
		if ( ! $legacy || (int) $legacy->user_id !== $user_id ) {
			return;
		}

		$table = EPC_DB::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time legacy reclaim.
		$wpdb->update(
			$table,
			array( 'source_id' => $source_key ),
			array( 'id' => (int) $legacy->id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Issue/update pass from transaction.
	 *
	 * @param MeprTransaction $txn  Transaction.
	 * @param string          $mode sync|create|update.
	 * @return true|\WP_Error
	 */
	private function sync_from_transaction( $txn, $mode = 'sync' ) {
		if ( ! $txn instanceof MeprTransaction ) {
			return new WP_Error( 'epc_invalid_transaction', __( 'Transaction not found.', 'epasscard' ) );
		}

		$product_id = (int) $txn->product_id;
		$mapping    = $this->get_mapping( $product_id );

		if ( empty( $mapping['template_uid'] ) ) {
			return new WP_Error( 'epc_no_mapping', __( 'No pass template is mapped for this membership.', 'epasscard' ) );
		}

		$user_id = (int) $txn->user_id;
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'epc_no_user', __( 'Member user not found.', 'epasscard' ) );
		}

		$subscription_id = (int) $txn->subscription_id;
		$source_id       = self::source_key_from_transaction( $txn );
		if ( '' === $source_id ) {
			return new WP_Error( 'epc_invalid_source', __( 'Membership record not found.', 'epasscard' ) );
		}

		$this->claim_legacy_source_row( $source_id, $user_id );

		$membership_ref = $subscription_id > 0 ? $subscription_id : (int) $txn->id;

		$product = new MeprProduct( $product_id );
		$expires = epc_format_pass_expiry_datetime( ! empty( $txn->expires_at ) ? (string) $txn->expires_at : '' );

		$first = (string) get_user_meta( $user_id, 'first_name', true );
		$last  = (string) get_user_meta( $user_id, 'last_name', true );

		$values = array(
			'user_display_name' => $user->display_name,
			'user_email'        => $user->user_email,
			'user_first_name'   => $first,
			'user_last_name'    => $last,
			'user_full_name'    => epc_format_user_full_name( $first, $last, $user->display_name ),
			'membership_id'     => (string) $membership_ref,
			'membership_title'  => (string) $product->post_title,
			'membership_status' => (string) $txn->status,
			'membership_start'  => mysql2date( get_option( 'date_format' ), $txn->created_at ),
			'membership_expires'=> $expires,
			'transaction_id'    => (string) $txn->id,
		);

		return EPC_Pass_Service::sync_pass(
			$this->get_slug(),
			$source_id,
			$product_id,
			$user_id,
			$mapping,
			$values,
			$mode
		);
	}
}
