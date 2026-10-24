<?php
/**
 * Developer API: issue, update, void and read a card from code or over REST.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cards for records of an active integration, from custom code (epc_issue_pass() and friends)
 * or the REST API (epasscard/v1/passes).
 *
 * A card belongs to one record of an integration, named by the integration slug and the
 * record id the integration uses (a Paid Memberships Pro membership row, a WooCommerce
 * Subscriptions subscription, a loyalty customer's user id, a gift card id …). Each call runs
 * the integration's own sync, so the card gets the same design and field values as the
 * automatic flow, and a record never gets a second card.
 */
class EPC_Developer_Api {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'epasscard/v1';

	/**
	 * Record being issued, to note whether the call created its card: [module, source_id].
	 *
	 * @var array{0: string, 1: string}|null
	 */
	private static $issuing = null;

	/**
	 * Whether the record being issued got a new card.
	 *
	 * @var bool
	 */
	private static $created = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'epc_pass_created', array( __CLASS__, 'on_pass_created' ), 10, 2 );
	}

	/**
	 * Create the card for a record, or update it when it exists. A voided card is made live again.
	 *
	 * @param string               $module    Integration slug.
	 * @param string|int           $source_id Record id.
	 * @param array<string, mixed> $args      Optional: email (bool) – email the card link to the
	 *                                        customer (Settings → Emails) once, instead of the
	 *                                        automatic email.
	 * @return array<string, mixed>|\WP_Error Card data (see data()) plus created and emailed.
	 */
	public static function issue( $module, $source_id, array $args = array() ) {
		$found = self::resolve( $module, $source_id );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		list( $integration, $source_id ) = $found;
		$slug                            = $integration->get_slug();
		$email                           = ! empty( $args['email'] );

		// When the caller asks for the email, it is sent once below, not also automatically.
		$no_auto = static function () {
			return false;
		};
		if ( $email ) {
			add_filter( 'epc_pass_email_auto_send', $no_auto, 100 );
		}
		self::$issuing = array( $slug, $source_id );
		self::$created = false;
		try {
			$synced = $integration->sync_by_source_id( $source_id, 'sync' );
		} finally {
			self::$issuing = null;
			if ( $email ) {
				remove_filter( 'epc_pass_email_auto_send', $no_auto, 100 );
			}
		}
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}

		$row = EPC_DB::get_pass( $slug, $source_id );
		if ( ! $row || '' === (string) $row->pass_uid ) {
			return new WP_Error( 'epc_no_card', __( 'EpassCard did not return a card for this record.', 'epasscard' ) );
		}

		$out            = self::data( $row );
		$out['created'] = self::$created;
		$out['emailed'] = false;
		if ( $email ) {
			$sent           = EPC_Pass_Email::send_for_pass_row( $row );
			$out['emailed'] = true === $sent;
			if ( is_wp_error( $sent ) ) {
				$out['email_error'] = $sent->get_error_message();
			}
		}
		return $out;
	}

	/**
	 * Update the existing card of a record with the record's current values (and make a voided
	 * card live again). Fails when the record has no card.
	 *
	 * @param string     $module    Integration slug.
	 * @param string|int $source_id Record id.
	 * @return array<string, mixed>|\WP_Error Card data.
	 */
	public static function update( $module, $source_id ) {
		$found = self::resolve( $module, $source_id );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		list( $integration, $source_id ) = $found;

		$synced = $integration->sync_by_source_id( $source_id, 'update' );
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}
		$row = EPC_DB::get_pass( $integration->get_slug(), $source_id );
		return $row ? self::data( $row ) : new WP_Error( 'epc_pass_missing', __( 'No pass exists yet for this record.', 'epasscard' ) );
	}

	/**
	 * Void a record's card: it expires in Apple Wallet and Google Wallet and shows as revoked,
	 * like Expire on the Cards screen. issue() or update() makes it live again.
	 *
	 * @param string     $module    Integration slug.
	 * @param string|int $source_id Record id.
	 * @return array<string, mixed>|\WP_Error Card data.
	 */
	public static function void( $module, $source_id ) {
		$found = self::resolve( $module, $source_id );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		list( $integration, $source_id ) = $found;
		$slug                            = $integration->get_slug();

		$row = EPC_DB::get_pass( $slug, $source_id );
		if ( $row && 'revoked' === (string) $row->status ) {
			return self::data( $row );
		}
		$result = EPC_Pass_Service::expire_issued_pass( $slug, $source_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$row = EPC_DB::get_pass( $slug, $source_id );
		return $row ? self::data( $row ) : new WP_Error( 'epc_pass_missing', __( 'No pass exists yet for this record.', 'epasscard' ) );
	}

	/**
	 * A record's card.
	 *
	 * @param string     $module    Integration slug.
	 * @param string|int $source_id Record id.
	 * @return array<string, mixed>|\WP_Error Card data, or epc_pass_missing.
	 */
	public static function get( $module, $source_id ) {
		$found = self::resolve( $module, $source_id );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		list( $integration, $source_id ) = $found;

		$row = EPC_DB::get_pass( $integration->get_slug(), $source_id );
		if ( ! $row || '' === (string) $row->pass_uid ) {
			return new WP_Error( 'epc_pass_missing', __( 'No pass exists yet for this record.', 'epasscard' ) );
		}
		return self::data( $row );
	}

	/**
	 * Card data returned to callers.
	 *
	 * @param object $row Pass row.
	 * @return array{module: string, source_id: string, entity_id: int, user_id: int, pass_uid: string, pass_link: string, status: string, is_test: bool, created_at: string, updated_at: string}
	 */
	public static function data( $row ) {
		return array(
			'module'     => (string) $row->module,
			'source_id'  => (string) $row->source_id,
			'entity_id'  => (int) $row->entity_id,
			'user_id'    => (int) $row->user_id,
			'pass_uid'   => (string) $row->pass_uid,
			'pass_link'  => (string) $row->pass_link,
			'status'     => (string) $row->status,
			'is_test'    => ! empty( $row->is_test ),
			'created_at' => (string) $row->created_at,
			'updated_at' => (string) $row->updated_at,
		);
	}

	/**
	 * The integration (turned on, plugin active) and the clean record id.
	 *
	 * @param string     $module    Integration slug.
	 * @param string|int $source_id Record id.
	 * @return array{0: EPC_Module, 1: string}|\WP_Error
	 */
	private static function resolve( $module, $source_id ) {
		$slug        = sanitize_key( (string) $module );
		$integration = '' !== $slug && EPC_Module_Settings::is_enabled( $slug ) ? EPC_Module_Loader::get_module_instance( $slug ) : null;
		if ( ! $integration instanceof EPC_Module || ! $integration->is_available() ) {
			return new WP_Error( 'epc_unknown_integration', __( 'This integration is not turned on, or its plugin is not active.', 'epasscard' ) );
		}
		$clean = EPC_DB::sanitize_source_id( $source_id );
		if ( '' === $clean ) {
			return new WP_Error( 'epc_invalid_source_id', __( 'The record id is not valid.', 'epasscard' ) );
		}
		if ( ! EPC_Api_Client::is_configured() ) {
			return new WP_Error( 'epc_no_key', __( 'EpassCard is not connected.', 'epasscard' ) );
		}
		return array( $integration, $clean );
	}

	/**
	 * Note that the record being issued got a new card.
	 *
	 * @param string     $module    Module slug.
	 * @param string|int $source_id Source id.
	 * @return void
	 */
	public static function on_pass_created( $module, $source_id ) {
		if ( null !== self::$issuing && self::$issuing[0] === (string) $module && self::$issuing[1] === EPC_DB::sanitize_source_id( $source_id ) ) {
			self::$created = true;
		}
	}

	/* -------------------------------------------------------------------------
	 * REST
	 * ---------------------------------------------------------------------- */

	/**
	 * Routes: POST /passes, GET|PUT|PATCH|DELETE /passes/{module}/{source_id}.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/passes',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_issue' ),
				'permission_callback' => array( __CLASS__, 'rest_can' ),
				'args'                => array(
					'module'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'source_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'email'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			'/passes/(?P<module>[a-z0-9-]+)/(?P<source_id>[a-z0-9_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => array( __CLASS__, 'rest_can' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'rest_update' ),
					'permission_callback' => array( __CLASS__, 'rest_can' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'rest_void' ),
					'permission_callback' => array( __CLASS__, 'rest_can' ),
				),
			)
		);
	}

	/**
	 * Who may use the REST routes: users who can manage cards (administrators, and staff with
	 * the EpassCard staff capability).
	 *
	 * @return bool
	 */
	public static function rest_can() {
		return epc_current_user_can_manage_passes();
	}

	/**
	 * POST /passes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_issue( $request ) {
		$result = self::issue( (string) $request['module'], (string) $request['source_id'], array( 'email' => (bool) $request['email'] ) );
		if ( is_wp_error( $result ) ) {
			return self::rest_error( $result );
		}
		return new WP_REST_Response( $result, ! empty( $result['created'] ) ? 201 : 200 );
	}

	/**
	 * GET /passes/{module}/{source_id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get( $request ) {
		$result = self::get( (string) $request['module'], (string) $request['source_id'] );
		return is_wp_error( $result ) ? self::rest_error( $result ) : new WP_REST_Response( $result, 200 );
	}

	/**
	 * PUT|PATCH /passes/{module}/{source_id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_update( $request ) {
		$result = self::update( (string) $request['module'], (string) $request['source_id'] );
		return is_wp_error( $result ) ? self::rest_error( $result ) : new WP_REST_Response( $result, 200 );
	}

	/**
	 * DELETE /passes/{module}/{source_id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_void( $request ) {
		$result = self::void( (string) $request['module'], (string) $request['source_id'] );
		return is_wp_error( $result ) ? self::rest_error( $result ) : new WP_REST_Response( $result, 200 );
	}

	/**
	 * An error with an HTTP status.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private static function rest_error( WP_Error $error ) {
		$code   = (string) $error->get_error_code();
		$status = array(
			'epc_unknown_integration' => 404,
			'epc_invalid_source_id'   => 400,
			'epc_invalid_source'      => 404,
			'epc_pass_missing'        => 404,
			'epc_no_mapping'          => 409,
			'epc_no_key'              => 503,
			// EpassCard answered with an error or could not be reached.
			'epc_create_failed'       => 502,
			'epc_create_incomplete'   => 502,
			'epc_update_failed'       => 502,
			'epc_expire_failed'       => 502,
			'epc_bad_json'            => 502,
			'epc_http'                => 502,
			'epc_http_transport'      => 502,
			'epc_timeout'             => 504,
		);
		// Anything else is about the record (no user, no fields to send …).
		return new WP_Error( $code, $error->get_error_message(), array( 'status' => isset( $status[ $code ] ) ? $status[ $code ] : 400 ) );
	}
}
