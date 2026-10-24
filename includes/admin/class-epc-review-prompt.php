<?php
/**
 * Ask for a WordPress.org review once EpassCard is working for the site.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A review invitation on Home, shown only after success.
 *
 * Shown to an administrator on Home after setup is complete, when the site has a real
 * (non-test) card that is at least 7 days old, or 10 real cards. Never during setup and never
 * on activation. Each administrator can say "Maybe later" (asked again after 30 days),
 * "Don't ask again", or write the review; the answer is kept per user. Nothing is offered in
 * return for a review.
 */
class EPC_Review_Prompt {

	/**
	 * User meta with the answer: {state: later|never|done, until: unix time}.
	 */
	const META = 'epc_review_prompt';

	/**
	 * Days a real card must have been live.
	 */
	const DAYS_LIVE = 7;

	/**
	 * Real cards that are enough on their own.
	 */
	const CARDS = 10;

	/**
	 * Days "Maybe later" waits.
	 */
	const LATER_DAYS = 30;

	/**
	 * Where reviews are written.
	 */
	const REVIEW_URL = 'https://wordpress.org/support/plugin/epasscard/reviews/#new-post';

	/**
	 * Where problems are reported.
	 */
	const SUPPORT_URL = 'https://wordpress.org/support/plugin/epasscard/';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_epc_review_prompt', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Whether the current user should see the invitation now.
	 *
	 * @return bool
	 */
	public static function should_show() {
		if ( ! current_user_can( 'manage_options' ) || ! EPC_Onboarding::is_complete() ) {
			return false;
		}

		$answer = get_user_meta( get_current_user_id(), self::META, true );
		if ( is_array( $answer ) ) {
			$state = (string) ( $answer['state'] ?? '' );
			if ( in_array( $state, array( 'never', 'done' ), true ) ) {
				return false;
			}
			if ( 'later' === $state && (int) ( $answer['until'] ?? 0 ) > time() ) {
				return false;
			}
		}

		return EPC_DB::has_real_pass_older_than_days( self::DAYS_LIVE ) || EPC_DB::count_all_real_passes() >= self::CARDS;
	}

	/**
	 * The invitation.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::should_show() ) {
			return;
		}
		$cards = EPC_DB::count_all_real_passes();
		$url   = admin_url( 'admin-post.php' );
		?>
		<section class="epc-panel epc-review" aria-labelledby="epc-review-title" data-epc-review>
			<h3 id="epc-review-title" class="epc-panel__title"><?php esc_html_e( 'Is EpassCard working well for you?', 'epasscard' ); ?></h3>
			<p>
				<?php
				/* translators: %s: number of cards */
				echo esc_html( sprintf( _n( 'Your customers have %s card from this site.', 'Your customers have %s cards from this site.', $cards, 'epasscard' ), number_format_i18n( $cards ) ) );
				?>
				<?php esc_html_e( 'If EpassCard helps, a short review on WordPress.org helps other site owners find it. If something is not right, tell us in the support forum instead and we will look at it.', 'epasscard' ); ?>
			</p>
			<div class="epc-review__actions">
				<form method="post" action="<?php echo esc_url( $url ); ?>" target="_blank" class="epc-inline-form" data-epc-review-write>
					<input type="hidden" name="action" value="epc_review_prompt" />
					<input type="hidden" name="answer" value="done" />
					<?php wp_nonce_field( 'epc_review_prompt' ); ?>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Write a review', 'epasscard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'epasscard' ); ?></span></button>
				</form>
				<a class="button" href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Report a problem', 'epasscard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'epasscard' ); ?></span></a>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="epc-inline-form">
					<input type="hidden" name="action" value="epc_review_prompt" />
					<input type="hidden" name="answer" value="later" />
					<?php wp_nonce_field( 'epc_review_prompt' ); ?>
					<button type="submit" class="button-link"><?php esc_html_e( 'Maybe later', 'epasscard' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( $url ); ?>" class="epc-inline-form">
					<input type="hidden" name="action" value="epc_review_prompt" />
					<input type="hidden" name="answer" value="never" />
					<?php wp_nonce_field( 'epc_review_prompt' ); ?>
					<button type="submit" class="button-link"><?php esc_html_e( 'Don\'t ask again', 'epasscard' ); ?></button>
				</form>
			</div>
		</section>
		<?php
	}

	/**
	 * Save an answer.
	 *
	 * @param int    $user_id User id.
	 * @param string $answer  done|later|never.
	 * @return void
	 */
	public static function answer( $user_id, $answer ) {
		$answer = in_array( $answer, array( 'done', 'later', 'never' ), true ) ? $answer : 'later';
		update_user_meta(
			absint( $user_id ),
			self::META,
			array(
				'state' => $answer,
				'until' => 'later' === $answer ? time() + self::LATER_DAYS * DAY_IN_SECONDS : 0,
			)
		);
	}

	/**
	 * Admin-post: the answer. "Write a review" goes on to the WordPress.org review form.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_review_prompt' );
		$answer = isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( (string) $_POST['answer'] ) ) : '';
		self::answer( get_current_user_id(), $answer );

		if ( 'done' === $answer ) {
			// A fixed WordPress.org address (wp_safe_redirect() allows this site only).
			wp_redirect( self::REVIEW_URL ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Fixed external URL.
			exit;
		}
		wp_safe_redirect( EPC_Admin_App::url( 'home' ) );
		exit;
	}
}
