<?php
/**
 * Loyalty customers admin section: staff lookup, list, export, adjustments.
 *
 * @package EpassCard
 *
 * @var EPC_Module_WooCommerce_Loyalty $module
 * @var string $search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$module = isset( $module ) ? $module : null;
$search = isset( $search ) ? (string) $search : '';

$table = new EPC_Loyalty_Customers_List_Table( $search, $module );
$table->prepare_items();

$customers_page = ( $module && method_exists( $module, 'get_customers_page_slug' ) )
	? $module->get_customers_page_slug()
	: 'epc-woocommerce-loyalty';

$export_url = wp_nonce_url(
	add_query_arg(
		array_filter(
			array(
				'page'               => $customers_page,
				'epc_loyalty_export' => 'customers',
				'epc_customer_s'     => '' !== $search ? $search : null,
			)
		),
		admin_url( 'admin.php' )
	),
	'epc_loyalty_export_customers'
);
?>
<div id="epc-section-loyalty-customers" class="epc-section epc-loyalty-customers">
	<div class="epc-page-header">
		<h2 class="epc-page-title"><?php esc_html_e( 'Customers', 'epasscard' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Search loyalty members, look up a barcode membership ID for POS, adjust points, sync passes, and export CSV. The barcode itself is a non-secret membership ID and never reveals balances.', 'epasscard' ); ?>
		</p>
	</div>

	<div class="epc-card epc-loyalty-lookup">
		<h3><?php esc_html_e( 'Staff barcode lookup', 'epasscard' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Scan or type a membership ID, customer email, or WordPress user ID.', 'epasscard' ); ?></p>
		<div class="epc-loyalty-lookup__form">
			<label class="screen-reader-text" for="epc-loyalty-lookup-query"><?php esc_html_e( 'Membership ID', 'epasscard' ); ?></label>
			<input type="text" id="epc-loyalty-lookup-query" class="regular-text" autocomplete="off" autofocus placeholder="<?php esc_attr_e( 'EPC-0000000001', 'epasscard' ); ?>" />
			<button type="button" class="button button-primary" id="epc-loyalty-lookup-submit"><?php esc_html_e( 'Look up', 'epasscard' ); ?></button>
		</div>
		<p class="epc-loyalty-lookup__status" aria-live="polite"></p>
		<div class="epc-loyalty-lookup__result" hidden></div>
	</div>

	<div class="epc-loyalty-customers__toolbar">
		<form method="get" class="epc-loyalty-customers__search">
			<input type="hidden" name="page" value="<?php echo esc_attr( $customers_page ); ?>" />
			<p class="search-box">
				<label class="screen-reader-text" for="epc-loyalty-customer-search-input"><?php esc_html_e( 'Search customers', 'epasscard' ); ?></label>
				<input type="search" id="epc-loyalty-customer-search-input" name="epc_customer_s" value="<?php echo esc_attr( $search ); ?>" />
				<?php submit_button( __( 'Search customers', 'epasscard' ), '', '', false, array( 'id' => 'epc-loyalty-customer-search-submit' ) ); ?>
			</p>
		</form>
		<p class="epc-loyalty-customers__export">
			<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export CSV', 'epasscard' ); ?></a>
		</p>
	</div>

	<?php $table->display(); ?>
</div>

<div id="epc-loyalty-adjust-modal" class="epc-modal" hidden>
	<div class="epc-modal__backdrop" data-epc-close></div>
	<div class="epc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="epc-loyalty-adjust-title">
		<header class="epc-modal__header">
			<h2 id="epc-loyalty-adjust-title"><?php esc_html_e( 'Adjust loyalty points', 'epasscard' ); ?></h2>
			<button type="button" class="epc-modal__close" data-epc-close aria-label="<?php esc_attr_e( 'Close', 'epasscard' ); ?>">&times;</button>
		</header>
		<div class="epc-modal__body">
			<input type="hidden" id="epc-loyalty-adjust-user-id" value="" />
			<p class="epc-loyalty-adjust-customer"></p>
			<p>
				<label for="epc-loyalty-adjust-points"><strong><?php esc_html_e( 'Points delta', 'epasscard' ); ?></strong></label><br />
				<input type="number" id="epc-loyalty-adjust-points" class="regular-text" step="1" />
				<span class="description"><?php esc_html_e( 'Use a negative value to deduct points.', 'epasscard' ); ?></span>
			</p>
			<p>
				<label for="epc-loyalty-adjust-reason"><strong><?php esc_html_e( 'Reason', 'epasscard' ); ?></strong></label><br />
				<textarea id="epc-loyalty-adjust-reason" class="large-text" rows="3" required></textarea>
			</p>
			<p>
				<label>
					<input type="checkbox" id="epc-loyalty-adjust-lifetime" value="1" />
					<?php esc_html_e( 'Also increase lifetime earned points (positive awards only)', 'epasscard' ); ?>
				</label>
			</p>
			<p class="epc-loyalty-adjust-status" aria-live="polite"></p>
		</div>
		<footer class="epc-modal__footer">
			<button type="button" class="button button-primary" id="epc-loyalty-adjust-submit"><?php esc_html_e( 'Save adjustment', 'epasscard' ); ?></button>
			<button type="button" class="button" data-epc-close><?php esc_html_e( 'Cancel', 'epasscard' ); ?></button>
		</footer>
	</div>
</div>

<div id="epc-loyalty-history-modal" class="epc-modal" hidden>
	<div class="epc-modal__backdrop" data-epc-close></div>
	<div class="epc-modal__dialog epc-loyalty-history-dialog" role="dialog" aria-modal="true" aria-labelledby="epc-loyalty-history-title">
		<header class="epc-modal__header">
			<h2 id="epc-loyalty-history-title"><?php esc_html_e( 'Points history', 'epasscard' ); ?></h2>
			<button type="button" class="epc-modal__close" data-epc-close aria-label="<?php esc_attr_e( 'Close', 'epasscard' ); ?>">&times;</button>
		</header>
		<div class="epc-modal__body">
			<input type="hidden" id="epc-loyalty-history-user-id" value="" />
			<p class="epc-loyalty-history-customer"></p>
			<p class="epc-loyalty-history-status" aria-live="polite"></p>
			<div class="epc-loyalty-history-table-wrap">
				<table class="widefat striped epc-loyalty-history-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'epasscard' ); ?></th>
							<th><?php esc_html_e( 'Type', 'epasscard' ); ?></th>
							<th><?php esc_html_e( 'Points', 'epasscard' ); ?></th>
							<th><?php esc_html_e( 'Lifetime', 'epasscard' ); ?></th>
							<th><?php esc_html_e( 'Details', 'epasscard' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</div>
		<footer class="epc-modal__footer epc-loyalty-history-footer">
			<div class="epc-loyalty-history-pagination">
				<button type="button" class="button" id="epc-loyalty-history-prev"><?php esc_html_e( 'Previous', 'epasscard' ); ?></button>
				<span class="epc-loyalty-history-page"></span>
				<button type="button" class="button" id="epc-loyalty-history-next"><?php esc_html_e( 'Next', 'epasscard' ); ?></button>
			</div>
			<button type="button" class="button" data-epc-close><?php esc_html_e( 'Close', 'epasscard' ); ?></button>
		</footer>
	</div>
</div>
