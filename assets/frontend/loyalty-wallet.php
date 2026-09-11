<?php
/**
 * My Account loyalty wallet template.
 *
 * @package EpassCard
 *
 * @var array<string, mixed> $summary
 * @var array{items: array<int, object>, total: int} $ledger
 * @var array<string, mixed> $redemption
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$summary    = isset( $summary ) && is_array( $summary ) ? $summary : array();
$ledger     = isset( $ledger ) && is_array( $ledger ) ? $ledger : array( 'items' => array(), 'total' => 0 );
$redemption = isset( $redemption ) && is_array( $redemption ) ? $redemption : array();
$claims     = isset( $summary['claims'] ) && is_array( $summary['claims'] ) ? $summary['claims'] : array();
?>
<div class="epc-loyalty-wallet">
	<div class="epc-loyalty-wallet__header">
		<h2><?php esc_html_e( 'Loyalty wallet', 'epasscard' ); ?></h2>
		<?php if ( ! empty( $summary['member_id'] ) ) : ?>
			<p class="epc-loyalty-wallet__member">
				<?php
				printf(
					/* translators: %s: membership ID */
					esc_html__( 'Membership ID: %s', 'epasscard' ),
					esc_html( (string) $summary['member_id'] )
				);
				?>
			</p>
		<?php endif; ?>
	</div>

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
		<div class="epc-loyalty-wallet__progress">
			<?php if ( ! empty( $summary['next_tier'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: next tier name, 2: threshold */
						esc_html__( 'Next tier: %1$s at %2$s lifetime points', 'epasscard' ),
						esc_html( (string) $summary['next_tier'] ),
						esc_html( number_format_i18n( (int) ( $summary['next_tier_threshold'] ?? 0 ) ) )
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $summary['next_reward'] ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: 1: reward name, 2: progress */
						esc_html__( 'Next reward: %1$s (%2$s)', 'epasscard' ),
						esc_html( (string) $summary['next_reward'] ),
						esc_html( (string) ( $summary['milestone_progress'] ?? '' ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $summary['pass_link'] ) ) : ?>
		<p class="epc-loyalty-wallet__pass">
			<a class="button" href="<?php echo esc_url( (string) $summary['pass_link'] ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Add loyalty pass to wallet', 'epasscard' ); ?>
			</a>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $redemption['enabled'] ) ) : ?>
		<div class="epc-loyalty-wallet__redemption">
			<h3><?php esc_html_e( 'Redeeming at checkout', 'epasscard' ); ?></h3>
			<p>
				<?php
				$rate = isset( $redemption['points_per_currency'] ) ? (float) $redemption['points_per_currency'] : 0;
				if ( $rate > 0 ) {
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
		</div>
	<?php endif; ?>

	<div class="epc-loyalty-wallet__rewards">
		<h3><?php esc_html_e( 'Available rewards', 'epasscard' ); ?></h3>
		<?php if ( empty( $claims ) ) : ?>
			<p><?php esc_html_e( 'You have no unclaimed rewards right now.', 'epasscard' ); ?></p>
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
								<br /><small>
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
						<button type="button" class="button epc-loyalty-wallet__claim-btn">
							<?php esc_html_e( 'Claim', 'epasscard' ); ?>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="epc-loyalty-wallet__claim-status" aria-live="polite"></p>
		<?php endif; ?>
	</div>

	<div class="epc-loyalty-wallet__history">
		<h3><?php esc_html_e( 'Points history', 'epasscard' ); ?></h3>
		<?php if ( empty( $ledger['items'] ) ) : ?>
			<p><?php esc_html_e( 'No loyalty activity yet.', 'epasscard' ); ?></p>
		<?php else : ?>
			<table class="shop_table shop_table_responsive epc-loyalty-wallet__table">
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
						<tr>
							<td data-title="<?php esc_attr_e( 'Date', 'epasscard' ); ?>">
								<?php echo esc_html( get_date_from_gmt( (string) $entry->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ); ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Type', 'epasscard' ); ?>">
								<?php echo esc_html( str_replace( '_', ' ', (string) $entry->entry_type ) ); ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Points', 'epasscard' ); ?>">
								<?php
								$delta = (int) $entry->points_delta;
								echo esc_html( ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta ) );
								?>
							</td>
							<td data-title="<?php esc_attr_e( 'Details', 'epasscard' ); ?>">
								<?php echo esc_html( (string) ( $entry->description ?? '' ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
