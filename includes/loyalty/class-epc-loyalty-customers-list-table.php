<?php
/**
 * Loyalty customers admin list table.
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
 * Customer-centric loyalty list table.
 */
class EPC_Loyalty_Customers_List_Table extends WP_List_Table {

	/**
	 * Search query.
	 *
	 * @var string
	 */
	private string $search;

	/**
	 * Module instance for pass actions.
	 *
	 * @var EPC_Module|null
	 */
	private $module;

	/**
	 * Constructor.
	 *
	 * @param string           $search Search string.
	 * @param EPC_Module|null  $module Loyalty module.
	 */
	public function __construct( $search = '', $module = null ) {
		parent::__construct(
			array(
				'plural'   => 'epc-loyalty-customers',
				'singular' => 'epc-loyalty-customer',
				'ajax'     => false,
			)
		);

		$this->search = (string) $search;
		$this->module = $module;
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'customer'          => __( 'Customer', 'epasscard' ),
			'member_id'         => __( 'Member ID', 'epasscard' ),
			'points_balance'    => __( 'Points', 'epasscard' ),
			'lifetime_points'   => __( 'Lifetime', 'epasscard' ),
			'tier'              => __( 'Tier', 'epasscard' ),
			'next_reward'       => __( 'Next reward', 'epasscard' ),
			'unclaimed_rewards' => __( 'Unclaimed', 'epasscard' ),
			'pass'              => __( 'Pass', 'epasscard' ),
			'last_activity'     => __( 'Last activity', 'epasscard' ),
			'actions'           => __( 'Actions', 'epasscard' ),
		);
	}

	/**
	 * Prepare items.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = 20;
		$page     = $this->get_pagenum();
		$result   = EPC_Loyalty_Customer_Service::query_accounts(
			array(
				'search'   => $this->search,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);

		$this->items = array();
		foreach ( $result['items'] as $row ) {
			$summary = EPC_Loyalty_Customer_Service::get_customer_summary( $row );
			if ( $summary ) {
				$this->items[] = $summary;
			}
		}

		$this->set_pagination_args(
			array(
				'total_items' => (int) $result['total'],
				'per_page'    => $per_page,
				'total_pages' => max( 1, (int) ceil( (int) $result['total'] / $per_page ) ),
			)
		);

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			array(),
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
	 * Default column renderer.
	 *
	 * @param array<string, mixed> $item Row.
	 * @param string               $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'customer':
				return esc_html( (string) $item['display_name'] ) . '<br /><small>' . esc_html( (string) $item['email'] ) . '</small>';
			case 'member_id':
				return '<code>' . esc_html( (string) $item['member_id'] ) . '</code>';
			case 'points_balance':
			case 'lifetime_points':
			case 'unclaimed_rewards':
				return esc_html( number_format_i18n( (int) $item[ $column_name ] ) );
			case 'tier':
				return '' !== (string) $item['tier'] ? esc_html( (string) $item['tier'] ) : '&mdash;';
			case 'next_reward':
				$label = (string) $item['next_reward'];
				$prog  = (string) $item['milestone_progress'];
				if ( '' === $label ) {
					return '&mdash;';
				}
				return esc_html( $label ) . ( '' !== $prog ? '<br /><small>' . esc_html( $prog ) . '</small>' : '' );
			case 'pass':
				$status = (string) $item['pass_status'];
				$link   = (string) $item['pass_link'];
				if ( '' !== $link ) {
					return '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( ucfirst( $status ) ) . '</a>';
				}
				return esc_html( 'none' === $status ? __( 'None', 'epasscard' ) : ucfirst( $status ) );
			case 'last_activity':
				return esc_html( (string) $item['last_activity'] );
			case 'actions':
				return $this->render_actions( $item );
			default:
				return '';
		}
	}

	/**
	 * Row action buttons.
	 *
	 * @param array<string, mixed> $item Customer summary.
	 * @return string
	 */
	private function render_actions( array $item ) {
		$user_id = absint( $item['user_id'] );
		$html    = '<div class="epc-loyalty-customer-actions" data-user-id="' . esc_attr( (string) $user_id ) . '">';

		if ( $this->module && method_exists( $this->module, 'render_pass_action_links' ) ) {
			$html .= $this->module->render_pass_action_links( $user_id );
		}

		$html .= ' <button type="button" class="button button-small epc-loyalty-adjust-open" data-user-id="' . esc_attr( (string) $user_id ) . '" data-name="' . esc_attr( (string) $item['display_name'] ) . '">' . esc_html__( 'Adjust', 'epasscard' ) . '</button>';

		if ( ! empty( $item['pass_link'] ) ) {
			$html .= ' <button type="button" class="button button-small epc-loyalty-email-pass" data-user-id="' . esc_attr( (string) $user_id ) . '">' . esc_html__( 'Email', 'epasscard' ) . '</button>';
		}

		$html .= '</div>';
		return $html;
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No loyalty customers found yet.', 'epasscard' );
	}
}
