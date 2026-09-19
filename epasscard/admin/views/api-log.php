<?php
/**
 * API request log admin view.
 *
 * @package EpassCard
 *
 * @var array{items: array<int, object>, total: int} $result
 * @var int    $page
 * @var int    $per_page
 * @var int    $total_pages
 * @var string $search
 * @var string $success
 * @var int    $retention
 * @var string $notice
 * @var int    $deleted
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$base_url = admin_url( 'admin.php?page=epc-api-log' );

EPC_Admin_Shell::render_open(
	array(
		'context'        => 'api-log',
		'title'          => __( 'API Log', 'epasscard' ),
		'active_section' => 'api-log',
	)
);
?>
<div class="wrap epc-wrap epc-api-log-wrap">
	<div class="epc-page-header">
		<h1 class="epc-page-title"><?php esc_html_e( 'API Log', 'epasscard' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Review API requests sent to EpassCard. Logs are retained based on your settings below.', 'epasscard' ); ?></p>
	</div>

	<?php if ( 'settings_saved' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log settings saved.', 'epasscard' ); ?></p></div>
	<?php elseif ( 'purged' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %d: number of deleted rows */ __( 'Removed %d expired log entries.', 'epasscard' ), $deleted ) ); ?></p></div>
	<?php elseif ( 'cleared' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %d: number of deleted rows */ __( 'Removed %d log entries.', 'epasscard' ), $deleted ) ); ?></p></div>
	<?php endif; ?>

	<div class="epc-api-log-toolbar">
		<form method="get" class="epc-api-log-search">
			<input type="hidden" name="page" value="epc-api-log" />
			<label class="screen-reader-text" for="epc-api-log-search"><?php esc_html_e( 'Search logs', 'epasscard' ); ?></label>
			<input type="search" id="epc-api-log-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search URL, body, context…', 'epasscard' ); ?>" />
			<select name="success">
				<option value=""><?php esc_html_e( 'All results', 'epasscard' ); ?></option>
				<option value="1" <?php selected( $success, '1' ); ?>><?php esc_html_e( 'Success only', 'epasscard' ); ?></option>
				<option value="0" <?php selected( $success, '0' ); ?>><?php esc_html_e( 'Failed only', 'epasscard' ); ?></option>
			</select>
			<?php submit_button( __( 'Filter', 'epasscard' ), 'secondary', '', false ); ?>
		</form>

		<div class="epc-api-log-actions">
			<button type="button" class="button epc-ajax-action" data-epc-action="epc_purge_api_logs" value="1">
				<?php echo esc_html( sprintf( /* translators: %d: retention days */ __( 'Purge older than %d days', 'epasscard' ), $retention ) ); ?>
			</button>
			<button type="button" class="button button-secondary epc-ajax-action" data-epc-action="epc_clear_api_logs" data-epc-confirm="<?php echo esc_attr( __( 'Delete all API log entries?', 'epasscard' ) ); ?>" value="1">
				<?php esc_html_e( 'Clear all logs', 'epasscard' ); ?>
			</button>
		</div>
	</div>

	<p class="description">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: total entries, 2: retention days */
				__( '%1$d entries stored. Logs older than %2$d days are removed automatically each day.', 'epasscard' ),
				(int) $result['total'],
				$retention
			)
		);
		?>
	</p>

	<table class="widefat striped epc-api-log-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Date (UTC)', 'epasscard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Method', 'epasscard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Endpoint', 'epasscard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'HTTP', 'epasscard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'epasscard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Context', 'epasscard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Duration', 'epasscard' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'epasscard' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $result['items'] ) ) : ?>
				<tr>
					<td colspan="8"><?php esc_html_e( 'No API requests logged yet.', 'epasscard' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $result['items'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $row->created_at ); ?></td>
						<td><code><?php echo esc_html( (string) $row->method ); ?></code></td>
						<td class="epc-api-log-url"><code><?php echo esc_html( (string) $row->endpoint_url ); ?></code></td>
						<td><?php echo esc_html( (string) $row->http_status ); ?></td>
						<td>
							<?php if ( ! empty( $row->is_success ) ) : ?>
								<span class="epc-log-badge epc-log-badge--success"><?php esc_html_e( 'OK', 'epasscard' ); ?></span>
							<?php else : ?>
								<span class="epc-log-badge epc-log-badge--error"><?php esc_html_e( 'Failed', 'epasscard' ); ?></span>
								<?php if ( ! empty( $row->error_code ) ) : ?>
									<br /><code><?php echo esc_html( (string) $row->error_code ); ?></code>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( (string) $row->context ); ?></code></td>
						<td><?php echo esc_html( (string) $row->duration_ms ); ?> ms</td>
						<td>
							<details>
								<summary><?php esc_html_e( 'View', 'epasscard' ); ?></summary>
								<div class="epc-api-log-details">
									<?php
									$request_body = isset( $row->request_body ) ? trim( (string) $row->request_body ) : '';
									$response_body = isset( $row->response_body ) ? trim( (string) $row->response_body ) : '';
									?>
									<p><strong><?php esc_html_e( 'Request body', 'epasscard' ); ?></strong></p>
									<pre><?php echo esc_html( '' !== $request_body ? $request_body : __( '(empty)', 'epasscard' ) ); ?></pre>
									<p><strong><?php esc_html_e( 'Response body', 'epasscard' ); ?></strong></p>
									<pre><?php echo esc_html( '' !== $response_body ? $response_body : __( '(empty)', 'epasscard' ) ); ?></pre>
								</div>
							</details>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: total items */
							_n( '%s item', '%s items', (int) $result['total'], 'epasscard' ),
							number_format_i18n( (int) $result['total'] )
						)
					);
					?>
				</span>
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%', $base_url ),
							'format'    => '',
							'current'   => $page,
							'total'     => $total_pages,
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'add_args'  => array_filter(
								array(
									's'       => $search,
									'success' => $success,
								)
							),
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<hr />

	<h2><?php esc_html_e( 'Retention settings', 'epasscard' ); ?></h2>
	<form class="epc-ajax-form" data-epc-action="epc_save_api_log_settings" method="post">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="epc_api_log_retention_days"><?php esc_html_e( 'Keep logs for (days)', 'epasscard' ); ?></label>
				</th>
				<td>
					<input type="number" min="1" max="365" step="1" class="small-text" id="epc_api_log_retention_days" name="epc_api_log_retention_days" value="<?php echo esc_attr( (string) $retention ); ?>" />
					<p class="description"><?php esc_html_e( 'Older entries are deleted automatically once per day. You can also purge manually above.', 'epasscard' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save settings', 'epasscard' ), 'secondary', 'epc_save_api_log_settings', false ); ?>
	</form>
</div>
<?php
EPC_Admin_Shell::render_close();
