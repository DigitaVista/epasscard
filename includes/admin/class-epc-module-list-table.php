<?php
/**
 * List table of an integration's pass records (its admin page).
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
 * List table for module pass records.
 */
class EPC_Module_List_Table extends WP_List_Table {

	/**
	 * Module instance.
	 *
	 * @var EPC_Module
	 */
	private $module;

	/**
	 * Search string.
	 *
	 * @var string
	 */
	private $search;

	/**
	 * List filters.
	 *
	 * @var array<string, mixed>
	 */
	private $filters;

	/**
	 * Redirect URL for row actions.
	 *
	 * @var string
	 */
	private $redirect_url;

	/**
	 * Constructor.
	 *
	 * @param EPC_Module         $module       Module.
	 * @param string             $search       Search query.
	 * @param array<string,mixed> $filters     Filters.
	 * @param string             $redirect_url Redirect after pass actions.
	 */
	public function __construct( EPC_Module $module, $search, array $filters = array(), $redirect_url = '' ) {
		parent::__construct(
			array(
				'plural'   => 'epc-passes',
				'singular' => 'epc-pass',
				'ajax'     => false,
			)
		);

		$this->module  = $module;
		$this->search  = $search;
		$this->filters = $filters;
		$this->redirect_url = '' !== $redirect_url
			? $redirect_url
			: admin_url( 'admin.php?page=epc-' . $module->get_slug() );
	}

	/**
	 * Build query args for DB layer.
	 *
	 * @param int  $page       Page number.
	 * @param int  $per_page   Per page.
	 * @param bool $count_only Count only flag.
	 * @return array<string, mixed>
	 */
	private function build_query_args( $page, $per_page, $count_only = false ) {
		$args = array(
			'module'     => $this->module->get_slug(),
			'search'     => $this->search,
			'page'       => $page,
			'per_page'   => $per_page,
			'count_only' => $count_only,
		);

		if ( ! empty( $this->filters['status'] ) ) {
			$args['status'] = sanitize_key( (string) $this->filters['status'] );
		}

		if ( ! empty( $this->filters['entity_id'] ) ) {
			$args['entity_id'] = absint( $this->filters['entity_id'] );
		}

		return $args;
	}

	/**
	 * Primary column for list table row markup.
	 *
	 * @return string
	 */
	protected function get_primary_column_name() {
		return 'source_id';
	}

	/**
	 * Column headers.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'source_id'  => __( 'Record ID', 'epasscard' ),
			'user'       => __( 'Member', 'epasscard' ),
			'entity'     => $this->module->get_entity_column_label(),
			'pass_uid'   => __( 'Pass ID', 'epasscard' ),
			'pass_link'  => __( 'Pass link', 'epasscard' ),
			'status'     => __( 'Status', 'epasscard' ),
			'updated_at' => __( 'Updated', 'epasscard' ),
			'actions'    => __( 'Actions', 'epasscard' ),
		);
	}

	/**
	 * Prepare items.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;

		$count_result = EPC_DB::query_passes(
			$this->build_query_args( 1, 1, true )
		);

		$total_items = (int) $count_result['total'];
		$total_pages = max( 1, (int) ceil( $total_items / $per_page ) );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
			)
		);

		$current_page = $this->get_pagenum();

		$result = EPC_DB::query_passes(
			$this->build_query_args( $current_page, $per_page )
		);

		$this->items = $result['items'];

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			array(),
		);
	}

	/**
	 * Output a table row with a stable source id for AJAX updates.
	 *
	 * @param object $item Pass row.
	 * @return void
	 */
	public function single_row( $item ) {
		echo '<tr data-epc-source-id="' . esc_attr( (string) $item->source_id ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Default column output.
	 *
	 * @param object $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'source_id':
				return esc_html( (string) $item->source_id );
			case 'user':
				$user = get_userdata( (int) $item->user_id );
				if ( $user ) {
					return esc_html( epc_user_name( $user ) ) . '<br /><small>' . esc_html( $user->user_email ) . '</small>';
				}
				return esc_html( (string) $item->user_id );
			case 'entity':
				return esc_html( $this->module->get_entity_label( (int) $item->entity_id ) );
			case 'pass_uid':
				return '<code>' . esc_html( (string) $item->pass_uid ) . '</code>';
			case 'pass_link':
				$link = (string) $item->pass_link;
				if ( '' === $link ) {
					return '—';
				}
				return '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View pass', 'epasscard' ) . '</a>';
			case 'status':
				return EPC_Pass_Status::render_status_html( $item );
			case 'updated_at':
				return esc_html( (string) $item->updated_at );
			case 'actions':
				$html = $this->module->render_pass_action_links( (string) $item->source_id, $this->redirect_url );
				if ( ! empty( $item->pass_uid ) ) {
					$html .= ' ' . $this->module->render_pass_lifecycle_button( (string) $item->source_id, (string) $item->status );
				}
				return $html;
			default:
				return '';
		}
	}

	/**
	 * Empty state message.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No passes issued yet.', 'epasscard' );
	}
}
