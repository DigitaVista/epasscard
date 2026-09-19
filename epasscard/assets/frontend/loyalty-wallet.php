<?php
/**
 * My Account loyalty wallet template.
 *
 * @package EpassCard
 *
 * @var array<string, mixed> $summary
 * @var array{items: array<int, object>, total: int, page?: int, per_page?: int, total_pages?: int} $ledger
 * @var array<string, mixed> $redemption
 * @var array<string, mixed> $design
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$summary    = isset( $summary ) && is_array( $summary ) ? $summary : array();
$ledger     = isset( $ledger ) && is_array( $ledger ) ? $ledger : array( 'items' => array(), 'total' => 0 );
$redemption = isset( $redemption ) && is_array( $redemption ) ? $redemption : array();
$design     = isset( $design ) && is_array( $design ) ? $design : array();
$claims     = isset( $summary['claims'] ) && is_array( $summary['claims'] ) ? $summary['claims'] : array();
$colors     = isset( $design['colors'] ) && is_array( $design['colors'] ) ? $design['colors'] : array();

$card_bg = sanitize_hex_color( (string) ( $colors['background'] ?? '' ) );
$card_fg = sanitize_hex_color( (string) ( $colors['text'] ?? '' ) );
if ( ! $card_bg ) {
	$card_bg = '#1E1B4B';
}
if ( ! $card_fg ) {
	$card_fg = '#FFFFFF';
}

$points_label = sanitize_text_field( (string) ( $design['points_label'] ?? __( 'Points', 'epasscard' ) ) );
if ( '' === $points_label ) {
	$points_label = __( 'Points', 'epasscard' );
}

$org_name = sanitize_text_field( (string) ( $design['organization_name'] ?? get_bloginfo( 'name' ) ) );
$logo_url = esc_url_raw( (string) ( $design['logo_url'] ?? '' ) );
$tier     = ! empty( $summary['tier'] ) ? (string) $summary['tier'] : __( 'Member', 'epasscard' );

$milestone_current = 0;
$milestone_target  = 0;
if ( ! empty( $summary['milestone_progress'] ) && preg_match( '/^(\d+)\s*\/\s*(\d+)$/', (string) $summary['milestone_progress'], $progress_parts ) ) {
	$milestone_current = (int) $progress_parts[1];
	$milestone_target  = (int) $progress_parts[2];
}

$next_tier_pct = 0;
if ( ! empty( $summary['next_tier_threshold'] ) ) {
	$next_tier_pct = min( 100, (int) round( ( (int) ( $summary['lifetime_points'] ?? 0 ) / max( 1, (int) $summary['next_tier_threshold'] ) ) * 100 ) );
}

$milestone_pct = $milestone_target > 0
	? min( 100, (int) round( ( $milestone_current / $milestone_target ) * 100 ) )
	: 0;

$entry_types = class_exists( 'EPC_Loyalty_Ledger_Service' )
	? EPC_Loyalty_Ledger_Service::get_entry_type_labels()
	: array(
		'manual_adjustment' => __( 'Manual adjustment', 'epasscard' ),
	);

$card_style = sprintf(
	'--epc-lw-card-bg:%1$s;--epc-lw-card-fg:%2$s;',
	$card_bg,
	$card_fg
);
?>
<div class="epc-loyalty-wallet">
	<section class="epc-loyalty-wallet__hero" style="<?php echo esc_attr( $card_style ); ?>">
		<div class="epc-loyalty-wallet__card">
			<div class="epc-loyalty-wallet__card-top">
				<div class="epc-loyalty-wallet__brand">
					<?php if ( '' !== $logo_url ) : ?>
						<img class="epc-loyalty-wallet__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="" />
					<?php endif; ?>
					<span><?php echo esc_html( $org_name ); ?></span>
				</div>
				<span class="epc-loyalty-wallet__tier-pill"><?php echo esc_html( $tier ); ?></span>
			</div>
			<p class="epc-loyalty-wallet__card-name">
				<?php echo esc_html( (string) ( $summary['display_name'] ?? '' ) ); ?>
			</p>
			<p class="epc-loyalty-wallet__card-points">
				<strong><?php echo esc_html( number_format_i18n( (int) ( $summary['points_balance'] ?? 0 ) ) ); ?></strong>
				<span><?php echo esc_html( $points_label ); ?></span>
			</p>
			<div class="epc-loyalty-wallet__card-foot">
				<?php if ( ! empty( $summary['member_id'] ) ) : ?>
					<span>
						<?php
						printf(
							/* translators: %s: membership ID */
							esc_html__( 'ID %s', 'epasscard' ),
							esc_html( (string) $summary['member_id'] )
						);
						?>
					</span>
				<?php endif; ?>
				<span>
					<?php
					printf(
						/* translators: %s: lifetime points */
						esc_html__( '%s lifetime', 'epasscard' ),
						esc_html( number_format_i18n( (int) ( $summary['lifetime_points'] ?? 0 ) ) )
					);
					?>
				</span>
			</div>
		</div>

		<?php if ( ! empty( $summary['pass_link'] ) ) : ?>
			<p class="epc-loyalty-wallet__pass">
				<a class="epc-loyalty-wallet__pass-btn" href="<?php echo esc_url( (string) $summary['pass_link'] ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Add loyalty pass to wallet', 'epasscard' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</section>

	<div class="epc-loyalty-wallet__metrics">
		<div class="epc-loyalty-wallet__metric">
			<span class="epc-loyalty-wallet__label"><?php esc_html_e( 'Spendable points', 'epasscard' ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( (int) ( $summary['points_balance'] ?? 0 ) ) ); ?></strong>
		</div>
		<div class="epc-loyalty-wallet__metric">
			<span class="epc-loyalty-wallet__label"><?php esc_html_e( 'Lifetime earned', 'epasscard' ); ?></span>
			<strong><?php echo esc_html( number_format_i18n( (int) ( $summary['lifetime_points'] ?? 0 ) ) ); ?></strong>
		</div>
		<div class="epc-loyalty-wallet__metric">
			<span class="epc-loyalty-wallet__label"><?php esc_html_e( 'Tier', 'epasscard' ); ?></span>
			<strong><?php echo esc_html( ! empty( $summary['tier'] ) ? (string) $summary['tier'] : __( 'None yet', 'epasscard' ) ); ?></strong>
		</div>
	</div>

	<?php if ( ! empty( $summary['next_tier'] ) || ! empty( $summary['next_reward'] ) ) : ?>
		<section class="epc-loyalty-wallet__panel">
			<h3><?php esc_html_e( 'Progress', 'epasscard' ); ?></h3>
			<?php if ( ! empty( $summary['next_tier'] ) ) : ?>
				<div class="epc-loyalty-wallet__progress-row">
					<div class="epc-loyalty-wallet__progress-copy">
						<strong><?php echo esc_html( (string) $summary['next_tier'] ); ?></strong>
						<span>
							<?php
							printf(
								/* translators: 1: current lifetime points, 2: next tier threshold */
								esc_html__( '%1$s / %2$s lifetime points', 'epasscard' ),
								esc_html( number_format_i18n( (int) ( $summary['lifetime_points'] ?? 0 ) ) ),
								esc_html( number_format_i18n( (int) ( $summary['next_tier_threshold'] ?? 0 ) ) )
							);
							?>
						</span>
					</div>
					<div class="epc-loyalty-wallet__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $next_tier_pct ); ?>">
						<span style="width: <?php echo esc_attr( (string) $next_tier_pct ); ?>%;"></span>
					</div>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $summary['next_reward'] ) ) : ?>
				<div class="epc-loyalty-wallet__progress-row">
					<div class="epc-loyalty-wallet__progress-copy">
						<strong><?php echo esc_html( (string) $summary['next_reward'] ); ?></strong>
						<span>
							<?php
							if ( $milestone_target > 0 ) {
								printf(
									/* translators: 1: current progress, 2: target */
									esc_html__( '%1$s / %2$s lifetime points', 'epasscard' ),
									esc_html( number_format_i18n( $milestone_current ) ),
									esc_html( number_format_i18n( $milestone_target ) )
								);
							} else {
								echo esc_html( (string) ( $summary['milestone_progress'] ?? '' ) );
							}
							?>
						</span>
					</div>
					<?php if ( $milestone_target > 0 ) : ?>
						<div class="epc-loyalty-wallet__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $milestone_pct ); ?>">
							<span style="width: <?php echo esc_attr( (string) $milestone_pct ); ?>%;"></span>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $redemption['enabled'] ) ) : ?>
		<section class="epc-loyalty-wallet__panel">
			<h3><?php esc_html_e( 'Redeeming at checkout', 'epasscard' ); ?></h3>
			<p>
				<?php
				$rate = isset( $redemption['points_per_currency'] ) ? (float) $redemption['points_per_currency'] : 0;
				if ( $rate > 0 && function_exists( 'wc_price' ) ) {
					$one_unit = html_entity_decode( wp_strip_all_tags( wc_price( 1 ) ), ENT_QUOTES, get_bloginfo( 'charset' ) );
					printf(
						/* translators: 1: points required, 2: formatted currency amount */
						esc_html__( 'Use points at checkout. %1$s points equal %2$s in store credit.', 'epasscard' ),
						esc_html( (string) $rate ),
						esc_html( $one_unit )
					);
				} elseif ( $rate > 0 ) {
					printf(
						/* translators: %s: points per currency unit */
						esc_html__( 'You can redeem points at checkout. Conversion rate: %s points per currency unit.', 'epasscard' ),
						esc_html( (string) $rate )
					);
				} else {
					esc_html_e( 'You can redeem available points during checkout when your cart qualifies.', 'epasscard' );
				}
				?>
			</p>
		</section>
	<?php endif; ?>

	<section class="epc-loyalty-wallet__panel epc-loyalty-wallet__rewards">
		<h3><?php esc_html_e( 'Available rewards', 'epasscard' ); ?></h3>
		<?php if ( empty( $claims ) ) : ?>
			<p class="epc-loyalty-wallet__empty"><?php esc_html_e( 'You have no unclaimed rewards right now.', 'epasscard' ); ?></p>
		<?php else : ?>
			<ul class="epc-loyalty-wallet__claim-list">
				<?php foreach ( $claims as $claim ) : ?>
					<?php
					$meta      = ! empty( $claim->meta ) ? json_decode( (string) $claim->meta, true ) : array();
					$milestone = is_array( $meta ) && isset( $meta['milestone'] ) && is_array( $meta['milestone'] ) ? $meta['milestone'] : array();
					$name      = sanitize_text_field( (string) ( $milestone['name'] ?? $claim->reward_type ) );
					?>
					<li class="epc-loyalty-wallet__claim" data-claim-id="<?php echo esc_attr( (string) $claim->id ); ?>">
						<div>
							<strong><?php echo esc_html( $name ); ?></strong>
							<?php if ( ! empty( $claim->expires_at ) ) : ?>
								<small>
									<?php
									printf(
										/* translators: %s: expiry date */
										esc_html__( 'Expires %s', 'epasscard' ),
										esc_html( get_date_from_gmt( (string) $claim->expires_at, get_option( 'date_format' ) ) )
									);
									?>
								</small>
							<?php endif; ?>
						</div>
						<button type="button" class="epc-loyalty-wallet__claim-btn">
							<?php esc_html_e( 'Claim', 'epasscard' ); ?>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="epc-loyalty-wallet__claim-status" aria-live="polite"></p>
		<?php endif; ?>
	</section>

	<section class="epc-loyalty-wallet__panel epc-loyalty-wallet__history" id="epc-loyalty-history">
		<h3><?php esc_html_e( 'Points history', 'epasscard' ); ?></h3>
		<?php if ( empty( $ledger['items'] ) ) : ?>
			<p class="epc-loyalty-wallet__empty"><?php esc_html_e( 'No loyalty activity yet.', 'epasscard' ); ?></p>
		<?php else : ?>
			<div class="epc-loyalty-wallet__table-wrap">
				<table class="epc-loyalty-wallet__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'epasscard' ); ?></th>
							<th><?php esc_html_e( 'Type', 'epasscard' ); ?></th>
							<th><?php esc_html_e( 'Points', 'epasscard' ); ?></th>
							<th><?php esc_html_e( 'Details', 'epasscard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $ledger['items'] as $entry ) : ?>
							<?php
							$type_key   = (string) $entry->entry_type;
							$type_label = $entry_types[ $type_key ] ?? ucwords( str_replace( '_', ' ', $type_key ) );
							$delta      = (int) $entry->points_delta;
							?>
							<tr>
								<td data-title="<?php esc_attr_e( 'Date', 'epasscard' ); ?>">
									<?php echo esc_html( get_date_from_gmt( (string) $entry->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>
								</td>
								<td data-title="<?php esc_attr_e( 'Type', 'epasscard' ); ?>">
									<?php echo esc_html( $type_label ); ?>
								</td>
								<td data-title="<?php esc_attr_e( 'Points', 'epasscard' ); ?>" class="<?php echo $delta >= 0 ? 'is-positive' : 'is-negative'; ?>">
									<?php echo esc_html( ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta ) ); ?>
								</td>
								<td data-title="<?php esc_attr_e( 'Details', 'epasscard' ); ?>">
									<?php echo esc_html( (string) ( $entry->description ?? '' ) ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php
			$history_page  = max( 1, absint( $ledger['page'] ?? 1 ) );
			$history_pages = max( 1, absint( $ledger['total_pages'] ?? 1 ) );
			$history_total = absint( $ledger['total'] ?? 0 );
			$history_size  = max( 1, absint( $ledger['per_page'] ?? 20 ) );
			$history_from  = ( ( $history_page - 1 ) * $history_size ) + 1;
			$history_to    = $history_from + count( $ledger['items'] ) - 1;
			$history_links = '';
			if ( $history_pages > 1 && function_exists( 'wc_get_endpoint_url' ) ) {
				$history_base  = str_replace(
					999999999,
					'%#%',
					esc_url_raw( wc_get_endpoint_url( 'loyalty-wallet', 999999999 ) )
				);
				$history_links = paginate_links(
					array(
						'base'         => $history_base,
						'format'       => '',
						'add_args'     => false,
						'current'      => $history_page,
						'total'        => $history_pages,
						'prev_text'    => is_rtl() ? '&rarr;' : '&larr;',
						'next_text'    => is_rtl() ? '&larr;' : '&rarr;',
						'type'         => 'list',
						'end_size'     => 1,
						'mid_size'     => 2,
						'add_fragment' => '#epc-loyalty-history',
					)
				);
			}
			?>
			<?php if ( $history_total > 0 ) : ?>
				<p class="epc-loyalty-wallet__history-meta">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: first visible row, 2: last visible row, 3: total entries */
							__( 'Showing %1$s–%2$s of %3$s', 'epasscard' ),
							number_format_i18n( $history_from ),
							number_format_i18n( $history_to ),
							number_format_i18n( $history_total )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( is_string( $history_links ) && '' !== $history_links ) : ?>
				<nav class="epc-loyalty-wallet__pagination woocommerce-pagination" aria-label="<?php esc_attr_e( 'Points history pagination', 'epasscard' ); ?>">
					<?php echo wp_kses_post( $history_links ); ?>
				</nav>
			<?php endif; ?>
		<?php endif; ?>
	</section>
</div>
