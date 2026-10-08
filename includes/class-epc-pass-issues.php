<?php
/**
 * Records failed pass operations so merchants can see and retry them.
 *
 * Before 1.0.9 failed create/update/expire calls were only visible in the API Log.
 * Failures are now kept (one entry per module + record) until the next successful
 * operation for that record, a manual retry, or a dismiss.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Failed pass operation store + admin notice.
 */
class EPC_Pass_Issues {

	/**
	 * Option key (not autoloaded).
	 */
	const OPTION = 'epc_pass_issues';

	/**
	 * Maximum entries kept.
	 */
	const MAX_ENTRIES = 200;

	/**
	 * Hook admin UI.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_action( 'admin_post_epc_dismiss_pass_issue', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'epc_retry_pass_revoke', array( __CLASS__, 'run_revoke_retry' ), 10, 3 );
		add_action( 'epc_resync_revoked_passes', array( __CLASS__, 'resync_revoked_passes' ) );

		// One-time repair for 1.0.8, whose expire calls could fail while rows were marked revoked.
		if ( false === get_option( 'epc_revoked_resync_1010', false ) ) {
			update_option( 'epc_revoked_resync_1010', array( 'state' => 'pending', 'last_id' => 0 ), false );
			if ( ! wp_next_scheduled( 'epc_resync_revoked_passes' ) ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'epc_resync_revoked_passes' );
			}
		}
	}

	/**
	 * Re-send the expire call for passes stored as revoked (batch of 25 per run).
	 *
	 * Expiring an already-expired pass is harmless, so this is safe to repeat.
	 *
	 * @return void
	 */
	public static function resync_revoked_passes() {
		$state = get_option( 'epc_revoked_resync_1010', array() );
		if ( ! is_array( $state ) || 'done' === ( $state['state'] ?? '' ) || ! EPC_Api_Client::is_configured() ) {
			return;
		}

		global $wpdb;
		$last = absint( $state['last_id'] ?? 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time repair batch.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'revoked' AND pass_uid <> '' AND id > %d AND updated_at >= %s ORDER BY id ASC LIMIT 25",
				EPC_DB::table_name(),
				$last,
				'2026-09-20 00:00:00'
			)
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$last = (int) $row->id;
			if ( class_exists( 'EPC_Api_Log' ) ) {
				EPC_Api_Log::set_request_context( sanitize_key( (string) $row->module ) . ':expire_pass_repair' );
			}
			EPC_Api_Client::expire_pass( (string) $row->pass_uid );
		}

		if ( is_array( $rows ) && 25 === count( $rows ) ) {
			update_option( 'epc_revoked_resync_1010', array( 'state' => 'pending', 'last_id' => $last ), false );
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'epc_resync_revoked_passes' );
			return;
		}

		update_option( 'epc_revoked_resync_1010', array( 'state' => 'done', 'last_id' => $last ), false );
	}

	/**
	 * All stored issues, newest first.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all() {
		$issues = get_option( self::OPTION, array() );
		if ( ! is_array( $issues ) ) {
			return array();
		}
		uasort(
			$issues,
			static function ( $a, $b ) {
				return (int) ( $b['time'] ?? 0 ) <=> (int) ( $a['time'] ?? 0 );
			}
		);
		return $issues;
	}

	/**
	 * Issues for one module.
	 *
	 * @param string $module Module slug.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_module( $module ) {
		$module = sanitize_key( (string) $module );
		return array_filter(
			self::all(),
			static function ( $issue ) use ( $module ) {
				return isset( $issue['module'] ) && $module === $issue['module'];
			}
		);
	}

	/**
	 * Storage key for a record.
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source id.
	 * @return string
	 */
	private static function key( $module, $source_id ) {
		return sanitize_key( (string) $module ) . '|' . EPC_DB::sanitize_source_id( $source_id );
	}

	/**
	 * Record a failed operation.
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source id.
	 * @param string     $action    create|update|sync|expire|restore.
	 * @param \WP_Error  $error     Error.
	 * @return void
	 */
	public static function record( $module, $source_id, $action, $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		$code = (string) $error->get_error_code();
		// Configuration states the merchant already sees on screen are not failures.
		if ( in_array( $code, array( 'epc_no_mapping', 'epc_no_key', 'epc_pass_exists', 'epc_pass_missing', 'epc_loyalty_pass_locked', 'epc_pass_in_flight' ), true ) ) {
			return;
		}

		$issues = get_option( self::OPTION, array() );
		$issues = is_array( $issues ) ? $issues : array();
		$key    = self::key( $module, $source_id );
		$prev   = isset( $issues[ $key ] ) && is_array( $issues[ $key ] ) ? $issues[ $key ] : array();
		$data   = $error->get_error_data();

		$issues[ $key ] = array(
			'module'    => sanitize_key( (string) $module ),
			'source_id' => EPC_DB::sanitize_source_id( $source_id ),
			'action'    => sanitize_key( (string) $action ),
			'code'      => sanitize_key( $code ),
			'status'    => is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0,
			'message'   => sanitize_text_field( (string) $error->get_error_message() ),
			'time'      => time(),
			'count'     => (int) ( $prev['count'] ?? 0 ) + 1,
		);

		if ( count( $issues ) > self::MAX_ENTRIES ) {
			uasort(
				$issues,
				static function ( $a, $b ) {
					return (int) ( $b['time'] ?? 0 ) <=> (int) ( $a['time'] ?? 0 );
				}
			);
			$issues = array_slice( $issues, 0, self::MAX_ENTRIES, true );
		}

		update_option( self::OPTION, $issues, false );

		/**
		 * Fires when a pass operation fails and is recorded for the merchant.
		 *
		 * @param string    $module    Module slug.
		 * @param string    $source_id Source id.
		 * @param string    $action    Operation.
		 * @param \WP_Error $error     Error.
		 */
		do_action( 'epc_pass_operation_failed', sanitize_key( (string) $module ), EPC_DB::sanitize_source_id( $source_id ), sanitize_key( (string) $action ), $error );
	}

	/**
	 * Clear the issue for a record (after a successful operation).
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source id.
	 * @return void
	 */
	public static function clear( $module, $source_id ) {
		$issues = get_option( self::OPTION, array() );
		if ( ! is_array( $issues ) || empty( $issues ) ) {
			return;
		}
		$key = self::key( $module, $source_id );
		if ( isset( $issues[ $key ] ) ) {
			unset( $issues[ $key ] );
			update_option( self::OPTION, $issues, false );
		}
	}

	/**
	 * Get the issue for one record.
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source id.
	 * @return array<string, mixed>|null
	 */
	public static function get( $module, $source_id ) {
		$issues = get_option( self::OPTION, array() );
		$key    = self::key( $module, $source_id );
		return is_array( $issues ) && isset( $issues[ $key ] ) && is_array( $issues[ $key ] ) ? $issues[ $key ] : null;
	}

	/**
	 * Schedule a retry of a failed revoke.
	 *
	 * @param string     $module    Module slug.
	 * @param int|string $source_id Source id.
	 * @param int        $attempt   Attempt number already made.
	 * @return void
	 */
	public static function schedule_revoke_retry( $module, $source_id, $attempt ) {
		$attempt = absint( $attempt );
		/**
		 * Filter how many automatic revoke retries are attempted.
		 *
		 * @param int $max Max retries.
		 */
		$max = (int) apply_filters( 'epc_revoke_retry_max', 3 );
		if ( $attempt >= $max ) {
			return;
		}
		$args  = array( sanitize_key( (string) $module ), EPC_DB::sanitize_source_id( $source_id ), $attempt + 1 );
		$delay = 15 * MINUTE_IN_SECONDS * ( $attempt + 1 );
		if ( ! wp_next_scheduled( 'epc_retry_pass_revoke', $args ) ) {
			wp_schedule_single_event( time() + $delay, 'epc_retry_pass_revoke', $args );
		}
	}

	/**
	 * Cron: retry a revoke that failed earlier.
	 *
	 * @param string $module    Module slug.
	 * @param string $source_id Source id.
	 * @param int    $attempt   Attempt number.
	 * @return void
	 */
	public static function run_revoke_retry( $module, $source_id, $attempt = 1 ) {
		$existing = EPC_DB::get_pass( $module, $source_id );
		if ( ! $existing || empty( $existing->pass_uid ) || 'revoked' === (string) $existing->status ) {
			return;
		}
		$meta = EPC_DB::get_pass_meta( $existing );
		if ( empty( $meta['revoke_pending'] ) ) {
			return;
		}
		EPC_Pass_Service::revoke_pass( $module, $source_id, absint( $attempt ) );
	}

	/**
	 * Dismiss one issue (admin-post).
	 *
	 * @return void
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_dismiss_pass_issue' );
		$module    = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( (string) $_GET['module'] ) ) : '';
		$source_id = isset( $_GET['source_id'] ) ? EPC_DB::sanitize_source_id( sanitize_text_field( wp_unslash( (string) $_GET['source_id'] ) ) ) : '';
		if ( '' !== $module && '' !== $source_id ) {
			self::clear( $module, $source_id );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=epasscard' ) );
		exit;
	}

	/**
	 * Dismiss URL.
	 *
	 * @param string $module    Module slug.
	 * @param string $source_id Source id.
	 * @return string
	 */
	public static function dismiss_url( $module, $source_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'epc_dismiss_pass_issue',
					'module'    => $module,
					'source_id' => $source_id,
				),
				admin_url( 'admin-post.php' )
			),
			'epc_dismiss_pass_issue'
		);
	}

	/**
	 * Human label for an operation.
	 *
	 * @param string $action Action slug.
	 * @return string
	 */
	public static function action_label( $action ) {
		switch ( $action ) {
			case 'expire':
				return __( 'Expire / revoke', 'epasscard' );
			case 'restore':
				return __( 'Reactivate', 'epasscard' );
			case 'create':
				return __( 'Create', 'epasscard' );
			case 'update':
				return __( 'Update', 'epasscard' );
			default:
				return __( 'Create or update', 'epasscard' );
		}
	}

	/**
	 * Admin notice on the dashboard and EpassCard screens.
	 *
	 * @return void
	 */
	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$is_epc  = 0 === strpos( $page, 'epc-' ) || 'epasscard' === $page;
		if ( 'dashboard' !== $id && ! $is_epc ) {
			return;
		}

		$issues = self::all();
		if ( empty( $issues ) ) {
			return;
		}

		$by_module = array();
		foreach ( $issues as $issue ) {
			$slug               = (string) ( $issue['module'] ?? '' );
			$by_module[ $slug ] = ( $by_module[ $slug ] ?? 0 ) + 1;
		}

		$links = array();
		foreach ( $by_module as $slug => $count ) {
			$module = function_exists( 'epc_plugin' ) ? epc_plugin()->get_module( $slug ) : null;
			$label  = $module ? $module->get_label() : $slug;
			$url    = $module ? $module->get_issued_passes_admin_url() : admin_url( 'admin.php?page=epc-api-log' );
			$links[] = sprintf( '<a href="%1$s#epc-section-pass-issues">%2$s (%3$d)</a>', esc_url( $url ), esc_html( $label ), (int) $count );
		}
		?>
		<div class="notice notice-warning epc-pass-issues-notice">
			<p>
				<strong><?php esc_html_e( 'EpassCard: some wallet passes could not be created, updated or expired.', 'epasscard' ); ?></strong>
				<?php esc_html_e( 'Review and retry them:', 'epasscard' ); ?>
				<?php echo wp_kses( implode( ' · ', $links ), array( 'a' => array( 'href' => true ) ) ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the failures panel above a module's issued passes table.
	 *
	 * @param EPC_Module $module Module.
	 * @return void
	 */
	public static function render_module_panel( $module ) {
		$issues = self::for_module( $module->get_slug() );
		if ( empty( $issues ) ) {
			return;
		}
		?>
		<div id="epc-section-pass-issues" class="epc-pass-issues">
			<h3><?php esc_html_e( 'Pass actions that need attention', 'epasscard' ); ?></h3>
			<p class="description"><?php esc_html_e( 'These records failed when EpassCard was asked to create, update or expire their pass. Fix the cause (usually the field mapping), then retry. Full request details are in API Logs.', 'epasscard' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Record', 'epasscard' ); ?></th>
						<th><?php esc_html_e( 'Operation', 'epasscard' ); ?></th>
						<th><?php esc_html_e( 'Error from EpassCard', 'epasscard' ); ?></th>
						<th><?php esc_html_e( 'Last attempt', 'epasscard' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'epasscard' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $issues as $issue ) : ?>
					<?php
					$source_id    = (string) ( $issue['source_id'] ?? '' );
					$retry_action = 'expire' === ( $issue['action'] ?? '' ) ? 'expire' : 'sync';
					?>
					<tr>
						<td><?php echo esc_html( $source_id ); ?></td>
						<td><?php echo esc_html( self::action_label( (string) ( $issue['action'] ?? '' ) ) ); ?><?php echo (int) ( $issue['count'] ?? 1 ) > 1 ? ' &times;' . (int) $issue['count'] : ''; ?></td>
						<td><?php echo esc_html( (string) ( $issue['message'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $issue['time'] ?? time() ) ) ); ?></td>
						<td>
							<?php
							echo wp_kses(
								sprintf(
									'<button type="button" class="button button-small epc-pass-action" data-module="%1$s" data-source-id="%2$s" data-pass-action="%3$s" data-pass-nonce="%4$s">%5$s</button>',
									esc_attr( $module->get_slug() ),
									esc_attr( $source_id ),
									esc_attr( $retry_action ),
									esc_attr( wp_create_nonce( 'epc_pass_action_' . $source_id ) ),
									esc_html__( 'Retry', 'epasscard' )
								),
								EPC_Module::get_pass_action_allowed_html()
							);
							?>
							<a class="button button-small button-link" href="<?php echo esc_url( self::dismiss_url( $module->get_slug(), $source_id ) ); ?>"><?php esc_html_e( 'Dismiss', 'epasscard' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
