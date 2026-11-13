<?php
/**
 * "How this works" panels and the Tutorials page.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Help where the question is asked: each section and each setup step has a panel with three
 * lines (why, what, how), a link to the guide and, once it is recorded, a short video.
 *
 * The panel is open the first time a user sees it. "Hide" folds it to a "How this works" button
 * (remembered per user); it opens again on its own while the section shows a failure. The
 * Tutorials page (Help in the top bar) lists every topic in setup order.
 *
 * Videos play from YouTube's no-cookie player only after a click; until a topic has a video
 * the panel shows the three lines and the guide link, and nothing loads from YouTube.
 */
class EPC_Help {

	/**
	 * Tutorials page slug.
	 */
	const PAGE = 'epc-help';

	/**
	 * User meta: array{hidden: array<string, int>, watched: array<string, int>}.
	 */
	const META = 'epc_help';

	/**
	 * Guide pages.
	 */
	const DOCS = 'https://www.epasscard.com/plugin-documentation/';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_epc_help', array( __CLASS__, 'handle_post' ) );
		add_action( 'wp_ajax_epc_help', array( __CLASS__, 'handle_ajax' ) );
	}

	/**
	 * Every topic, in setup order. Videos are added here once recorded (YouTube id + length).
	 *
	 * @return array<string, array{group: string, label: string, title: string, why: string, what: string, how: string, doc: string, video: string, length: string}>
	 */
	public static function topics() {
		$topics = array(
			'home'          => array(
				'group' => 'start',
				'label' => __( 'Home', 'epasscard' ),
				'title' => __( 'What EpassCard does for your site', 'epasscard' ),
				'why'   => __( 'Customers keep a card they can see on their phone, and it stays up to date.', 'epasscard' ),
				'what'  => __( 'A card in Apple Wallet and Google Wallet, filled in from WordPress.', 'epasscard' ),
				'how'   => __( 'Follow the steps on Home: connect, choose, design, try it, go live.', 'epasscard' ),
				'doc'   => self::DOCS . '#quick-setup',
				'video'  => 'BjdEhCPCmKI',
				'length' => '1:13',
			),
			'connect'       => array(
				'group' => 'start',
				'label' => __( 'Step 1: Connect', 'epasscard' ),
				'title' => __( 'Connect your site to EpassCard', 'epasscard' ),
				'why'   => __( 'EpassCard signs and delivers the wallet cards; your customer data stays in WordPress.', 'epasscard' ),
				'what'  => __( 'One API key per site. It is extended automatically.', 'epasscard' ),
				'how'   => __( 'Sign up, sign in, or paste a key from app.epasscard.com.', 'epasscard' ),
				'doc'   => self::DOCS . '#api-connection',
				'video'  => 'pBCxX-rOkP8',
				'length' => '0:59',
			),
			'job'           => array(
				'group' => 'start',
				'label' => __( 'Step 2: Job & rules', 'epasscard' ),
				'title' => __( 'Loyalty, member card, ticket or gift card?', 'epasscard' ),
				'why'   => __( 'Start with one kind of card; add another later.', 'epasscard' ),
				'what'  => __( 'We check the plugins on this site and turn on the matching integration.', 'epasscard' ),
				'how'   => __( 'Pick a card type and confirm the rules.', 'epasscard' ),
				'doc'   => self::DOCS . '#setup-wizard',
				'video'  => 'yxgq_kxuzns',
				'length' => '1:30',
			),
			'design'        => array(
				'group' => 'start',
				'label' => __( 'Step 3: Design', 'epasscard' ),
				'title' => __( 'Design your card', 'epasscard' ),
				'why'   => __( 'The card is your brand on your customers\' phones.', 'epasscard' ),
				'what'  => __( 'Logo, colours and the fields the card shows.', 'epasscard' ),
				'how'   => __( 'Edit, check the preview, save.', 'epasscard' ),
				'doc'   => self::DOCS . '#template-designer',
				'video'  => '3_6dnSEE38Y',
				'length' => '1:32',
			),
			'try'           => array(
				'group' => 'start',
				'label' => __( 'Step 4: Try it', 'epasscard' ),
				'title' => __( 'Put a test card on your own phone', 'epasscard' ),
				'why'   => __( 'See it work before your customers do.', 'epasscard' ),
				'what'  => __( 'A test card with your name and sample data.', 'epasscard' ),
				'how'   => __( 'Scan the code with your phone and add it to your wallet.', 'epasscard' ),
				'doc'   => self::DOCS . '#quick-setup',
				'video'  => 'bH3Ae-aQn-I',
				'length' => '1:12',
			),
			'live'          => array(
				'group' => 'start',
				'label' => __( 'Step 5: Go live', 'epasscard' ),
				'title' => __( 'Go live, and give cards to the customers you have', 'epasscard' ),
				'why'   => __( 'Cards only help once real customers have them.', 'epasscard' ),
				'what'  => __( 'New customers get a card automatically.', 'epasscard' ),
				'how'   => __( 'Wait for the next customer, or give cards to the ones you already have.', 'epasscard' ),
				'doc'   => self::DOCS . '#quick-setup',
				'video'  => 'cAN6AHXVrnY',
				'length' => '1:30',
			),
			'cards'         => array(
				'group' => 'daily',
				'label' => __( 'Cards', 'epasscard' ),
				'title' => __( 'Reading the Cards list', 'epasscard' ),
				'why'   => __( 'Know who has a card, and fix the ones that failed.', 'epasscard' ),
				'what'  => __( 'Every card this site issued, active or revoked, with test cards kept apart.', 'epasscard' ),
				'how'   => __( 'Search and filter, retry the cards under Need attention, export to CSV.', 'epasscard' ),
				'doc'   => self::DOCS,
				'video'  => 'cKVJpxI9uGk',
				'length' => '1:12',
			),
			'design-change' => array(
				'group' => 'daily',
				'label' => __( 'Design', 'epasscard' ),
				'title' => __( 'Your card designs', 'epasscard' ),
				'why'   => __( 'Customers get no card for a plan, event or product without a design.', 'epasscard' ),
				'what'  => __( 'One design for each kind of card you give.', 'epasscard' ),
				'how'   => __( 'Open a design, edit it, check the preview, save.', 'epasscard' ),
				'doc'   => self::DOCS . '#template-designer',
				'video'  => 'UIGrUO2Rlq0',
				'length' => '0:48',
			),
			'customers'     => array(
				'group' => 'daily',
				'label' => __( 'Loyalty customers', 'epasscard' ),
				'title' => __( 'Points, tiers and rewards', 'epasscard' ),
				'why'   => __( 'Points and rewards give customers a reason to come back.', 'epasscard' ),
				'what'  => __( 'Earning rules, tiers, rewards and paying with points at checkout.', 'epasscard' ),
				'how'   => __( 'Set the rules, save, then place a test order.', 'epasscard' ),
				'doc'   => self::DOCS . '#mod-wc-loyalty',
				'video'  => 'qOOEVORhTy8',
				'length' => '1:32',
			),
			'account'       => array(
				'group' => 'daily',
				'label' => __( 'Settings: Account & plan', 'epasscard' ),
				'title' => __( 'Plans and the card limit', 'epasscard' ),
				'why'   => __( 'Your plan sets how many active cards you can have.', 'epasscard' ),
				'what'  => __( 'This site\'s cards against the plan, and how long they last at the current rate.', 'epasscard' ),
				'how'   => __( 'Change the plan on EpassCard when you need more.', 'epasscard' ),
				'doc'   => 'https://www.epasscard.com/pricing/',
				'video'  => 'KdbpnnfYBfc',
				'length' => '0:58',
			),
			'emails'        => array(
				'group' => 'daily',
				'label' => __( 'Settings: Emails', 'epasscard' ),
				'title' => __( 'The email your customer gets', 'epasscard' ),
				'why'   => __( 'For most customers the email is how the card arrives.', 'epasscard' ),
				'what'  => __( 'Whether it goes out on its own, and its subject and text.', 'epasscard' ),
				'how'   => __( 'Edit the subject and text, keep {pass_link}, save.', 'epasscard' ),
				'doc'   => self::DOCS . '#email-settings',
				'video'  => '46YqGjU1woo',
				'length' => '1:00',
			),
			'push'          => array(
				'group' => 'daily',
				'label' => __( 'Settings: Reminders & push', 'epasscard' ),
				'title' => __( 'Push messages to the card', 'epasscard' ),
				'why'   => __( 'A message on the lock screen brings people back without an email.', 'epasscard' ),
				'what'  => __( 'Reminders before a card expires or an event starts.', 'epasscard' ),
				'how'   => __( 'Turn a reminder on, choose how many days before, save.', 'epasscard' ),
				'doc'   => self::DOCS . '#push-notifications',
				'video'  => 'N6m6YuzBAsE',
				'length' => '0:53',
			),
			'integrations'  => array(
				'group' => 'daily',
				'label' => __( 'Settings: Integrations', 'epasscard' ),
				'title' => __( 'Which plugins get cards', 'epasscard' ),
				'why'   => __( 'EpassCard only issues cards for the integrations you turn on.', 'epasscard' ),
				'what'  => __( 'The plugins on this site that EpassCard works with.', 'epasscard' ),
				'how'   => __( 'Turn one on, save, then give it a design.', 'epasscard' ),
				'doc'   => self::DOCS,
				'video'  => 'g81pei6yuGI',
				'length' => '1:03',
			),
			'advanced'      => array(
				'group' => 'daily',
				'label' => __( 'Settings: Advanced', 'epasscard' ),
				'title' => __( 'The API log, and fixing a failed card', 'epasscard' ),
				'why'   => __( 'When a card fails, the API log shows why.', 'epasscard' ),
				'what'  => __( 'Every call this site makes to EpassCard, with the answer.', 'epasscard' ),
				'how'   => __( 'Open the API log, find the failed call, send it to support if you need help.', 'epasscard' ),
				'doc'   => self::DOCS . '#api-logs',
				'video'  => 'EF-CqsOeM7o',
				'length' => '1:08',
			),
		);

		foreach ( $topics as $id => $topic ) {
			$topics[ $id ] += array(
				'video'  => '',
				'length' => '',
			);
		}

		/**
		 * Filter the help topics: add a recorded video (YouTube id in `video`, "1:25" in
		 * `length`) or change a topic's guide link.
		 *
		 * @param array<string, array<string, string>> $topics Topics by id, in setup order.
		 */
		$filtered = apply_filters( 'epc_help_topics', $topics );
		$topics   = is_array( $filtered ) ? $filtered : $topics;

		foreach ( $topics as $id => $topic ) {
			$topics[ $id ]['video'] = preg_match( '/^[A-Za-z0-9_-]{11}$/', (string) ( $topic['video'] ?? '' ) ) ? (string) $topic['video'] : '';
		}

		return $topics;
	}

	/**
	 * One topic, or null.
	 *
	 * @param string $id Topic id.
	 * @return array<string, string>|null
	 */
	public static function topic( $id ) {
		$topics = self::topics();
		return isset( $topics[ $id ] ) ? $topics[ $id ] : null;
	}

	/**
	 * The user's help state.
	 *
	 * @param int $user_id User.
	 * @return array{hidden: array<string, int>, watched: array<string, int>}
	 */
	public static function state( $user_id ) {
		$saved = get_user_meta( (int) $user_id, self::META, true );
		$saved = is_array( $saved ) ? $saved : array();
		return array(
			'hidden'  => isset( $saved['hidden'] ) && is_array( $saved['hidden'] ) ? $saved['hidden'] : array(),
			'watched' => isset( $saved['watched'] ) && is_array( $saved['watched'] ) ? $saved['watched'] : array(),
		);
	}

	/**
	 * Save a change for the user.
	 *
	 * @param int    $user_id User.
	 * @param string $topic   Topic id.
	 * @param string $change  hide|show|watched.
	 * @return bool False for an unknown topic or change.
	 */
	public static function update( $user_id, $topic, $change ) {
		if ( null === self::topic( $topic ) || ! in_array( $change, array( 'hide', 'show', 'watched' ), true ) ) {
			return false;
		}
		$state = self::state( $user_id );
		if ( 'hide' === $change ) {
			$state['hidden'][ $topic ] = 1;
		} elseif ( 'show' === $change ) {
			unset( $state['hidden'][ $topic ] );
		} else {
			$state['watched'][ $topic ] = 1;
		}
		update_user_meta( (int) $user_id, self::META, $state );
		return true;
	}

	/**
	 * Hide or show without JavaScript.
	 *
	 * @return void
	 */
	public static function handle_post() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_help' );
		$topic  = isset( $_POST['topic'] ) ? sanitize_key( wp_unslash( (string) $_POST['topic'] ) ) : '';
		$change = isset( $_POST['change'] ) ? sanitize_key( wp_unslash( (string) $_POST['change'] ) ) : '';
		self::update( get_current_user_id(), $topic, $change );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : EPC_Admin_App::url( 'home' ) );
		exit;
	}

	/**
	 * Hide, show or "watched" from the page.
	 *
	 * @return void
	 */
	public static function handle_ajax() {
		check_ajax_referer( 'epc_help' );
		$topic  = isset( $_POST['topic'] ) ? sanitize_key( wp_unslash( (string) $_POST['topic'] ) ) : '';
		$change = isset( $_POST['change'] ) ? sanitize_key( wp_unslash( (string) $_POST['change'] ) ) : '';
		if ( ! self::update( get_current_user_id(), $topic, $change ) ) {
			wp_send_json_error( null, 400 );
		}
		wp_send_json_success();
	}

	/**
	 * The "How this works" panel for a topic.
	 *
	 * @param string               $topic_id Topic id.
	 * @param array<string, mixed> $args {
	 *     @type bool   $failure Open even when hidden: the section shows a failure.
	 *     @type string $heading Panel heading (default "How this works").
	 *     @type string $layout  inline (above a screen) or aside (a side column).
	 *     @type int    $level   Heading level, 2 or 3 (default 3: under the screen's h2).
	 * }
	 * @return void
	 */
	public static function render( $topic_id, array $args = array() ) {
		$topic = self::topic( $topic_id );
		if ( null === $topic ) {
			return;
		}
		$args    = wp_parse_args(
			$args,
			array(
				'failure' => false,
				'heading' => __( 'How this works', 'epasscard' ),
				'layout'  => 'inline',
				'level'   => 3,
			)
		);
		$state   = self::state( get_current_user_id() );
		$open    = ! empty( $args['failure'] ) || empty( $state['hidden'][ $topic_id ] );
		$id      = 'epc-help-' . $topic_id;
		$heading = (string) $args['heading'];
		$layout  = 'aside' === $args['layout'] ? 'aside' : 'inline';
		$tag     = 2 === (int) $args['level'] ? 'h2' : 'h3';
		?>
		<div class="<?php echo esc_attr( 'epc-help epc-help--' . $layout ); ?>" data-epc-help="<?php echo esc_attr( $topic_id ); ?>">
			<form class="epc-help__toggle" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $open ? ' hidden' : ''; ?>>
				<input type="hidden" name="action" value="epc_help" />
				<input type="hidden" name="topic" value="<?php echo esc_attr( $topic_id ); ?>" />
				<input type="hidden" name="change" value="show" />
				<?php wp_nonce_field( 'epc_help', '_wpnonce', false ); ?>
				<button type="submit" class="epc-help__open" aria-controls="<?php echo esc_attr( $id ); ?>" aria-expanded="false">
					<span class="epc-help__icon" aria-hidden="true">?</span>
					<?php echo esc_html( $heading ); ?>
				</button>
			</form>
			<section id="<?php echo esc_attr( $id ); ?>" class="epc-help__panel" aria-labelledby="<?php echo esc_attr( $id . '-title' ); ?>"<?php echo $open ? '' : ' hidden'; ?>>
				<div class="epc-help__head">
					<<?php echo esc_attr( $tag ); ?> id="<?php echo esc_attr( $id . '-title' ); ?>" class="epc-help__title"><?php echo esc_html( $heading ); ?></<?php echo esc_attr( $tag ); ?>>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="epc_help" />
						<input type="hidden" name="topic" value="<?php echo esc_attr( $topic_id ); ?>" />
						<input type="hidden" name="change" value="hide" />
						<?php wp_nonce_field( 'epc_help', '_wpnonce', false ); ?>
						<button type="submit" class="epc-help__hide"><?php esc_html_e( 'Hide', 'epasscard' ); ?><span class="screen-reader-text"> <?php echo esc_html( $heading ); ?></span></button>
					</form>
				</div>
				<div class="epc-help__body">
					<?php if ( '' !== $topic['video'] ) : ?>
						<?php self::render_video( $topic_id, $topic ); ?>
					<?php endif; ?>
					<div class="epc-help__text">
						<dl class="epc-why">
							<dt><?php esc_html_e( 'Why', 'epasscard' ); ?></dt>
							<dd><?php echo esc_html( $topic['why'] ); ?></dd>
							<dt><?php esc_html_e( 'What', 'epasscard' ); ?></dt>
							<dd><?php echo esc_html( $topic['what'] ); ?></dd>
							<dt><?php esc_html_e( 'How', 'epasscard' ); ?></dt>
							<dd><?php echo esc_html( $topic['how'] ); ?></dd>
						</dl>
						<p class="epc-help__links">
							<a href="<?php echo esc_url( epc_utm_url( $topic['doc'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read the guide', 'epasscard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'epasscard' ); ?></span></a>
							<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'All tutorials', 'epasscard' ); ?></a>
						</p>
					</div>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Click-to-play video: a poster button that becomes YouTube's no-cookie player.
	 *
	 * @param string               $topic_id Topic id.
	 * @param array<string, string> $topic    Topic.
	 * @return void
	 */
	private static function render_video( $topic_id, array $topic ) {
		$poster = 'admin/images/tutorials/' . $topic_id . '.webp';
		$label  = '' !== $topic['length']
			/* translators: 1: video title, 2: length such as 1:25 */
			? sprintf( __( 'Play: %1$s (%2$s)', 'epasscard' ), $topic['title'], $topic['length'] )
			/* translators: %s: video title */
			: sprintf( __( 'Play: %s', 'epasscard' ), $topic['title'] );
		?>
		<div class="epc-help__video">
			<button type="button" class="epc-help__poster" data-epc-help-play="<?php echo esc_attr( $topic['video'] ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
				<?php if ( is_readable( EPC_PLUGIN_DIR . $poster ) ) : ?>
					<img src="<?php echo esc_url( EPC_PLUGIN_URL . $poster ); ?>" alt="" />
				<?php endif; ?>
				<span class="epc-help__poster-title"><?php echo esc_html( $topic['title'] ); ?></span>
				<?php if ( '' !== $topic['length'] ) : ?>
					<span class="epc-help__poster-length"><?php echo esc_html( $topic['length'] ); ?></span>
				<?php endif; ?>
				<span class="epc-help__play" aria-hidden="true"></span>
			</button>
			<p class="epc-help__note"><?php esc_html_e( 'Plays from YouTube (privacy-enhanced mode) when you click.', 'epasscard' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Tutorials page URL.
	 *
	 * @return string
	 */
	public static function url() {
		return add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
	}

	/**
	 * The Tutorials page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}
		$topics  = self::topics();
		$state   = self::state( get_current_user_id() );
		$videos  = 0;
		$watched = 0;
		foreach ( $topics as $id => $topic ) {
			if ( '' !== $topic['video'] ) {
				++$videos;
				$watched += empty( $state['watched'][ $id ] ) ? 0 : 1;
			}
		}
		$groups = array(
			'start' => __( 'Getting started', 'epasscard' ),
			'daily' => __( 'Running it day to day', 'epasscard' ),
		);

		EPC_Admin_Shell::render_open(
			array(
				'context' => 'app',
				'section' => 'help',
				'title'   => __( 'Tutorials', 'epasscard' ),
				'subnav'  => array(),
			)
		);
		?>
		<div class="epc-tutorials">
			<div class="epc-page-head epc-tutorials__head">
				<div>
					<h2 class="epc-page-head__title"><?php esc_html_e( 'Tutorials', 'epasscard' ); ?></h2>
					<p class="epc-page-head__lead">
						<?php esc_html_e( 'One short guide for each part of EpassCard, in the order you set things up.', 'epasscard' ); ?>
						<?php
						if ( $videos > 0 ) {
							/* translators: 1: videos watched, 2: videos available */
							echo esc_html( sprintf( __( '%1$s of %2$s videos watched.', 'epasscard' ), number_format_i18n( $watched ), number_format_i18n( $videos ) ) );
						} else {
							esc_html_e( 'Videos are on the way; each guide links to the full documentation.', 'epasscard' );
						}
						?>
					</p>
				</div>
				<a class="button button-secondary" href="<?php echo esc_url( epc_utm_url( self::DOCS ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read the docs', 'epasscard' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'epasscard' ); ?></span></a>
			</div>

			<?php foreach ( $groups as $group => $group_label ) : ?>
				<section class="epc-tutorials__group" aria-labelledby="<?php echo esc_attr( 'epc-tutorials-' . $group ); ?>">
					<h3 id="<?php echo esc_attr( 'epc-tutorials-' . $group ); ?>" class="epc-tutorials__group-title"><?php echo esc_html( $group_label ); ?></h3>
					<ol class="epc-tutorials__list">
						<?php foreach ( $topics as $id => $topic ) : ?>
							<?php
							if ( $group !== $topic['group'] ) {
								continue;
							}
							?>
							<li class="epc-tutorial" data-epc-help="<?php echo esc_attr( $id ); ?>">
								<div class="epc-tutorial__main">
									<span class="epc-tutorial__label"><?php echo esc_html( $topic['label'] ); ?></span>
									<h4 class="epc-tutorial__title"><?php echo esc_html( $topic['title'] ); ?></h4>
									<p class="epc-tutorial__text"><?php echo esc_html( $topic['why'] . ' ' . $topic['how'] ); ?></p>
									<p class="epc-tutorial__links">
										<a href="<?php echo esc_url( epc_utm_url( $topic['doc'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read the guide', 'epasscard' ); ?><span class="screen-reader-text"> <?php echo esc_html( $topic['title'] ); ?> <?php esc_html_e( '(opens in a new tab)', 'epasscard' ); ?></span></a>
									</p>
								</div>
								<div class="epc-tutorial__side">
									<?php if ( '' !== $topic['video'] ) : ?>
										<?php self::render_video( $id, $topic ); ?>
										<?php if ( ! empty( $state['watched'][ $id ] ) ) : ?>
											<span class="epc-badge epc-badge--ok"><?php esc_html_e( 'Watched', 'epasscard' ); ?></span>
										<?php endif; ?>
									<?php else : ?>
										<span class="epc-badge epc-badge--muted"><?php esc_html_e( 'Video coming soon', 'epasscard' ); ?></span>
									<?php endif; ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ol>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
		EPC_Admin_Shell::render_close();
	}
}
