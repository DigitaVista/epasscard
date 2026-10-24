<?php
/**
 * The Cards list: every card from every integration.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Cards list table.
 *
 * Column names match the per-integration lists (status, updated_at, actions) so the shared
 * pass-action script updates a row in place after Update / Expire / Activate.
 */
class EPC_Cards_List_Table extends WP_List_Table {

	/**
	 * Integrations shown, keyed by slug.
	 *
	 * @var array<string, EPC_Module>
	 */
	private $modules;

	/**
	 * Filters: module, view, search.
	 *
	 * @var array<string, string>
	 */
	private $filters;

	/**
	 * Database clock (updated_at is written by the database, not by PHP).
	 *
	 * @var int
	 */
	private $db_now = 0;

	/**
	 * Constructor.
	 *
	 * @param array<string, EPC_Module> $modules Integrations the user can see.
	 * @param array<string, string>     $filters Filters.
	 */
	public function __construct( array $modules, array $filters ) {
		parent::__construct(
			array(
				'plural'   => 'epc-cards',
				'singular' => 'epc-card',
				'ajax'     => false,
			)
		);
		$this->modules = $modules;
		$this->filters = $filters;
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'customer'   => __( 'Customer', 'epasscard' ),
			'card_type'  => __( 'Card', 'epasscard' ),
			'status'     => __( 'Status', 'epasscard' ),
			'shows'      => __( 'Shows', 'epasscard' ),
			'updated_at' => __( 'Last update', 'epasscard' ),
			'actions'    => __( 'Actions', 'epasscard' ),
		);
	}

	/**
	 * Primary column.
	 *
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'customer';
	}

	/**
	 * Load the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;
		$modules  = array_keys( $this->modules );
		if ( '' !== $this->filters['module'] ) {
			$modules = isset( $this->modules[ $this->filters['module'] ] ) ? array( $this->filters['module'] ) : array();
		}

		$user_ids = null;
		if ( '' !== $this->filters['search'] ) {
			$user_ids = get_users(
				array(
					'search'         => '*' . $this->filters['search'] . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
					'fields'         => 'ID',
					'number'         => 200,
				)
			);
		}

		$result = EPC_DB::query_all_passes(
			array(
				'modules'  => $modules,
				'view'     => $this->filters['view'],
				'search'   => $this->filters['search'],
				'user_ids' => $user_ids,
				'page'     => $this->get_pagenum(),
				'per_page' => $per_page,
			)
		);

		global $wpdb;
		$this->db_now = (int) strtotime( (string) $wpdb->get_var( 'SELECT NOW()' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Database clock, compared with updated_at.
		$this->items  = $result['items'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => max( 1, (int) ceil( $result['total'] / $per_page ) ),
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Row with the record id, as on the per-integration lists.
	 *
	 * @param object $item Pass row.
	 * @return void
	 */
	public function single_row( $item ) {
		echo '<tr data-epc-source-id="' . esc_attr( (string) $item->source_id ) . '" data-epc-module="' . esc_attr( (string) $item->module ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * What the card shows now: points and tier for loyalty, else the plan, event or product.
	 *
	 * @param object     $item   Pass row.
	 * @param EPC_Module $module Module.
	 * @return string
	 */
	private function shows( $item, $module ) {
		if ( 'woocommerce-loyalty' === (string) $item->module && class_exists( 'EPC_Loyalty_Account_Service' ) ) {
			$account = EPC_Loyalty_Account_Service::get( (int) $item->user_id );
			if ( ! $account ) {
				return '';
			}
			$tier = EPC_Loyalty_Reward_Service::get_tier_progress( (int) $account->lifetime_points );
			/* translators: %s: number of points */
			$out = sprintf( _n( '%s point', '%s points', (int) $account->points_balance, 'epasscard' ), number_format_i18n( (int) $account->points_balance ) );
			if ( ! empty( $tier['current']['name'] ) ) {
				$out .= ' · ' . (string) $tier['current']['name'];
			}
			return $out;
		}
		return $module->get_entity_label( (int) $item->entity_id );
	}

	/**
	 * Column output.
	 *
	 * @param object $item        Pass row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		$slug   = (string) $item->module;
		$module = isset( $this->modules[ $slug ] ) ? $this->modules[ $slug ] : null;
		if ( null === $module ) {
			return '';
		}

		switch ( $column_name ) {
			case 'customer':
				$user = (int) $item->user_id > 0 ? get_userdata( (int) $item->user_id ) : false;
				if ( $user ) {
					return '<strong>' . esc_html( $user->display_name ) . '</strong><br /><span class="description">' . esc_html( $user->user_email ) . '</span>';
				}
				/* translators: %s: record id in the integration (booking, attendee, gift card) */
				return esc_html( sprintf( __( 'Record %s', 'epasscard' ), (string) $item->source_id ) );
			case 'card_type':
				$copy = EPC_Jobs::get_copy( EPC_Jobs::get_job_for_module( $slug ) );
				return esc_html( $copy['title'] ) . '<br /><span class="description">' . esc_html( $module->get_label() ) . '</span>';
			case 'status':
				return EPC_Pass_Status::render_status_html( $item );
			case 'shows':
				$shows = $this->shows( $item, $module );
				return '' !== $shows ? esc_html( $shows ) : '&mdash;';
			case 'updated_at':
				$time = strtotime( (string) $item->updated_at );
				if ( ! $time ) {
					return '';
				}
				/* translators: %s: time difference, e.g. "2 hours" */
				return '<span title="' . esc_attr( (string) $item->updated_at ) . '">' . esc_html( sprintf( __( '%s ago', 'epasscard' ), human_time_diff( $time, max( $time, $this->db_now ) ) ) ) . '</span>';
			case 'actions':
				if ( ! $module->current_user_can_manage_passes() ) {
					return '';
				}
				$html = $module->render_pass_action_links( (string) $item->source_id );
				if ( ! empty( $item->pass_uid ) ) {
					$html .= ' ' . $module->render_pass_lifecycle_button( (string) $item->source_id, (string) $item->status );
				}
				return $html;
		}
		return '';
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		if ( '' !== $this->filters['search'] || '' !== $this->filters['module'] || 'all' !== $this->filters['view'] ) {
			esc_html_e( 'No cards match these filters.', 'epasscard' );
			return;
		}
		esc_html_e( 'No cards yet. New customers get one automatically once a design is saved.', 'epasscard' );
	}
}
