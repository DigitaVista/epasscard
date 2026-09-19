<?php
/**
 * My Account / shortcode wallet pass list.
 *
 * @package EpassCard
 *
 * @var array<int, array<string, string>> $pass_items
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pass_items = isset( $pass_items ) && is_array( $pass_items ) ? $pass_items : array();
?>
<div class="epc-pass-list-wrap">
	<?php if ( empty( $pass_items ) ) : ?>
		<div class="epc-pass-list-wrap--empty">
			<p><?php esc_html_e( 'You do not have any wallet passes yet.', 'epasscard' ); ?></p>
		</div>
	<?php else : ?>
		<p class="epc-pass-list-wrap__intro">
			<?php esc_html_e( 'Open a pass to add it to Apple Wallet or Google Wallet.', 'epasscard' ); ?>
		</p>
		<ul class="epc-pass-list">
			<?php foreach ( $pass_items as $item ) : ?>
				<li class="epc-pass-list__item">
					<div class="epc-pass-card">
						<div class="epc-pass-card__meta">
							<?php if ( ! empty( $item['type'] ) && (string) $item['type'] !== (string) ( $item['title'] ?? '' ) ) : ?>
								<span class="epc-pass-card__type"><?php echo esc_html( (string) $item['type'] ); ?></span>
							<?php endif; ?>
							<p class="epc-pass-card__title"><?php echo esc_html( (string) ( $item['title'] ?? '' ) ); ?></p>
						</div>
						<a
							class="epc-pass-card__link"
							href="<?php echo esc_url( (string) ( $item['url'] ?? '' ) ); ?>"
							target="_blank"
							rel="noopener noreferrer"
						>
							<?php echo esc_html( (string) ( $item['button'] ?? __( 'Add to wallet', 'epasscard' ) ) ); ?>
						</a>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
