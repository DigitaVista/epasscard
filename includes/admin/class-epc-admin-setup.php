<?php
/**
 * Setup: choose what to give customers, then confirm the rules.
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setup screen (Home → step 2).
 *
 * Nothing is created in EpassCard here. Confirming turns on the integration (and, for a new
 * loyalty program, the starter earning rules and tiers) and records the choice; the card
 * template is only created when the merchant saves a design.
 */
class EPC_Admin_Setup {

	/**
	 * Starter loyalty rules and tiers turned on for a program that has no active rule yet.
	 * The same ones the 1.1 setup wizard turned on.
	 */
	const STARTER_RULES = array( 'points-per-spend', 'welcome-first-order' );
	const STARTER_TIERS = array( 'bronze', 'silver', 'gold' );

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_epc_setup_confirm', array( __CLASS__, 'handle_confirm' ) );
	}

	/**
	 * Job requested in the URL.
	 *
	 * @return string
	 */
	private static function requested_job() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$job  = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( (string) $_GET['job'] ) ) : '';
		$jobs = EPC_Jobs::get_jobs();
		return isset( $jobs[ $job ] ) ? $job : '';
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'epasscard' ) );
		}

		$job = self::requested_job();

		EPC_Admin_Shell::render_open(
			array(
				'context' => 'app',
				'section' => 'home',
				'title'   => __( 'Set up EpassCard', 'epasscard' ),
				'subnav'  => array(),
			)
		);

		echo '<div class="epc-setup">';
		echo '<p class="epc-back"><a href="' . esc_url( '' !== $job ? EPC_Admin_App::url( 'setup' ) : EPC_Admin_App::url( 'home' ) ) . '">&larr; ' . esc_html( '' !== $job ? __( 'All card types', 'epasscard' ) : __( 'Home', 'epasscard' ) ) . '</a></p>';

		if ( '' === $job ) {
			self::render_jobs();
		} else {
			self::render_job( $job );
		}

		echo '</div>';

		EPC_Admin_Shell::render_close();
	}

	/**
	 * The job cards.
	 *
	 * @return void
	 */
	private static function render_jobs() {
		$state = EPC_Onboarding::get_state();
		?>
		<div class="epc-page-head">
			<h2 class="epc-page-head__title"><?php esc_html_e( 'What do you want to give your customers?', 'epasscard' ); ?></h2>
			<p class="epc-page-head__lead"><?php esc_html_e( 'Start with one. You can add another kind of card later.', 'epasscard' ); ?></p>
		</div>
		<div class="epc-jobcards">
			<?php foreach ( array_keys( EPC_Jobs::get_jobs() ) as $job ) : ?>
				<?php
				$copy      = EPC_Jobs::get_copy( $job );
				$status    = EPC_Jobs::get_status( $job, $state['job'] === $job ? $state['module'] : '' );
				$available = EPC_Jobs::NOT_INSTALLED !== $status['state'];
				$module    = $available ? EPC_Module_Loader::get_module_instance( $status['module'] ) : null;
				$chosen    = $state['job'] === $job && $state['rules_confirmed'];
				?>
				<section class="epc-jobcard<?php echo $available ? ' is-available' : ' is-missing'; ?><?php echo $chosen ? ' is-chosen' : ''; ?>" aria-labelledby="<?php echo esc_attr( 'epc-job-' . $job ); ?>">
					<h3 id="<?php echo esc_attr( 'epc-job-' . $job ); ?>" class="epc-jobcard__title"><?php echo esc_html( $copy['title'] ); ?></h3>
					<p class="epc-jobcard__pitch"><?php echo esc_html( $copy['pitch'] ); ?></p>
					<p class="epc-jobcard__status">
						<?php if ( $chosen ) : ?>
							<span class="epc-pill is-success"><?php esc_html_e( 'Your choice', 'epasscard' ); ?></span>
						<?php elseif ( $available && $module instanceof EPC_Module ) : ?>
							<span class="epc-pill is-success">
								<?php
								/* translators: %s: plugin name, e.g. "WooCommerce" */
								echo esc_html( sprintf( __( 'Ready · %s found', 'epasscard' ), $module->get_dependency_label() ) );
								?>
							</span>
						<?php else : ?>
							<span class="epc-pill"><?php echo esc_html( $copy['needs'] ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( $available ) : ?>
						<p class="epc-jobcard__actions">
							<a class="button <?php echo $chosen ? 'button-secondary' : 'button-primary'; ?>" href="<?php echo esc_url( EPC_Admin_App::url( 'setup', array( 'job' => $job ) ) ); ?>">
								<?php echo esc_html( $chosen ? __( 'Review the rules', 'epasscard' ) : __( 'Choose', 'epasscard' ) ); ?>
								<span class="screen-reader-text"><?php echo esc_html( $copy['title'] ); ?></span>
							</a>
						</p>
					<?php else : ?>
						<?php self::render_plugin_actions( $job ); ?>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Install / activate / get links for the plugins a job works with.
	 *
	 * Uses the setup wizard's allow-listed installer (WordPress.org plugins only).
	 *
	 * @param string $job Job slug.
	 * @return void
	 */
	private static function render_plugin_actions( $job ) {
		$actions = EPC_Setup_Wizard::required_plugin_actions_for_goal( $job );
		if ( empty( $actions ) ) {
			return;
		}
		?>
		<p class="epc-jobcard__works"><?php esc_html_e( 'Works with:', 'epasscard' ); ?></p>
		<ul class="epc-plugin-actions">
			<?php foreach ( $actions as $action ) : ?>
				<li>
					<span class="epc-plugin-actions__name"><?php echo esc_html( (string) $action['label'] ); ?></span>
					<?php if ( 'active' === $action['status'] ) : ?>
						<span class="epc-pill is-success"><?php esc_html_e( 'Active', 'epasscard' ); ?></span>
					<?php elseif ( ! empty( $action['can_install'] ) ) : ?>
						<button type="button" class="button button-small" data-epc-install="<?php echo esc_attr( (string) $action['key'] ); ?>">
							<?php echo esc_html( 'installed' === $action['status'] ? __( 'Activate', 'epasscard' ) : __( 'Install and activate', 'epasscard' ) ); ?>
							<span class="screen-reader-text"><?php echo esc_html( (string) $action['label'] ); ?></span>
						</button>
					<?php elseif ( '' !== (string) $action['url'] ) : ?>
						<a href="<?php echo esc_url( (string) $action['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get it', 'epasscard' ); ?><span class="screen-reader-text"> <?php echo esc_html( (string) $action['label'] ); ?> <?php esc_html_e( '(opens in a new tab)', 'epasscard' ); ?></span></a>
					<?php elseif ( 'installed' === $action['status'] ) : ?>
						<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Activate on the Plugins screen', 'epasscard' ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="epc-install-status" aria-live="polite"></p>
		<?php
	}

	/**
	 * One job: what we will set up.
	 *
	 * @param string $job Job slug.
	 * @return void
	 */
	private static function render_job( $job ) {
		$state     = EPC_Onboarding::get_state();
		$copy      = EPC_Jobs::get_copy( $job );
		$available = EPC_Jobs::available_modules( $job );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view selection.
		$asked     = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( (string) $_GET['module'] ) ) : '';
		$preferred = isset( $available[ $asked ] ) ? $asked : ( $state['job'] === $job ? $state['module'] : '' );
		$status    = EPC_Jobs::get_status( $job, $preferred );
		?>
		<div class="epc-page-head">
			<h2 class="epc-page-head__title"><?php echo esc_html( $copy['title'] ); ?></h2>
			<p class="epc-page-head__lead"><?php echo esc_html( $copy['pitch'] ); ?></p>
		</div>
		<?php
		if ( empty( $available ) ) {
			echo '<div class="epc-panel"><p>' . esc_html( $copy['needs'] ) . '</p>';
			self::render_plugin_actions( $job );
			echo '</div>';
			return;
		}

		$selected = '' !== $status['module'] && isset( $available[ $status['module'] ] ) ? $status['module'] : (string) array_keys( $available )[0];
		$module   = $available[ $selected ];
		$connected = EPC_Connection::is_connected();
		?>
		<div class="epc-home__grid">
			<form class="epc-home__main" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="epc_setup_confirm" />
				<input type="hidden" name="job" value="<?php echo esc_attr( $job ); ?>" />
				<?php wp_nonce_field( 'epc_setup_confirm' ); ?>

				<?php if ( count( $available ) > 1 ) : ?>
					<nav class="epc-panel" aria-labelledby="epc-setup-runs-on">
						<h3 id="epc-setup-runs-on" class="epc-panel__title"><?php esc_html_e( 'Which plugin runs it?', 'epasscard' ); ?></h3>
						<p class="description"><?php esc_html_e( 'More than one matching plugin is active. Pick the one your customers use.', 'epasscard' ); ?></p>
						<ul class="epc-choices">
							<?php foreach ( $available as $slug => $candidate ) : ?>
								<li>
									<a class="epc-choice<?php echo $selected === $slug ? ' is-selected' : ''; ?>" href="<?php echo esc_url( EPC_Admin_App::url( 'setup', array( 'job' => $job, 'module' => $slug ) ) ); ?>"<?php echo $selected === $slug ? ' aria-current="true"' : ''; ?>>
										<?php echo esc_html( $candidate->get_dependency_label() ); ?>
									</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</nav>
				<?php endif; ?>
				<input type="hidden" name="module" value="<?php echo esc_attr( $selected ); ?>" />

				<section class="epc-panel" aria-labelledby="epc-setup-rules">
					<h3 id="epc-setup-rules" class="epc-panel__title"><?php esc_html_e( 'Here is what we will set up', 'epasscard' ); ?></h3>
					<dl class="epc-summary">
						<dt><?php esc_html_e( 'Runs on', 'epasscard' ); ?></dt>
						<dd>
							<?php echo esc_html( $module->get_dependency_label() ); ?>
							<?php if ( 'woocommerce-loyalty' !== $selected && '' !== $copy['items'] ) : ?>
								·
								<?php
								/* translators: 1: number, 2: e.g. "membership plans" */
								echo esc_html( sprintf( __( 'we found %1$s %2$s', 'epasscard' ), number_format_i18n( (int) $status['items'] ), $copy['items'] ) );
								?>
							<?php endif; ?>
						</dd>

						<dt><?php esc_html_e( 'Rules', 'epasscard' ); ?></dt>
						<dd>
							<ul class="epc-summary__list">
								<?php foreach ( self::rule_lines( $module ) as $line ) : ?>
									<li><?php echo esc_html( $line ); ?></li>
								<?php endforeach; ?>
							</ul>
							<?php $rules_url = self::rules_url( $module ); ?>
							<?php if ( '' !== $rules_url ) : ?>
								<a href="<?php echo esc_url( $rules_url ); ?>"><?php esc_html_e( 'Change the rules', 'epasscard' ); ?></a>
							<?php endif; ?>
						</dd>

						<dt><?php esc_html_e( 'Card design', 'epasscard' ); ?></dt>
						<dd><?php echo esc_html( self::design_line( $module ) ); ?></dd>

						<dt><?php esc_html_e( 'Customers get it', 'epasscard' ); ?></dt>
						<dd>
							<ul class="epc-summary__list">
								<?php foreach ( self::delivery_lines( $module ) as $line ) : ?>
									<li><?php echo esc_html( $line ); ?></li>
								<?php endforeach; ?>
							</ul>
						</dd>
					</dl>
					<p class="description"><?php esc_html_e( 'Confirming turns on this integration. Nothing is created in your EpassCard account until you save a card design.', 'epasscard' ); ?></p>
					<p class="epc-step__actions">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm and continue', 'epasscard' ); ?></button>
						<?php if ( ! $connected ) : ?>
							<span class="description"><?php esc_html_e( 'You can confirm now and connect your EpassCard account on Home before designing the card.', 'epasscard' ); ?></span>
						<?php endif; ?>
					</p>
				</section>
			</form>

			<aside class="epc-home__aside" aria-label="<?php esc_attr_e( 'Card preview', 'epasscard' ); ?>">
				<div class="epc-panel">
					<div class="epc-panel__head">
						<h3 class="epc-panel__title"><?php esc_html_e( 'What the card looks like', 'epasscard' ); ?></h3>
						<span class="epc-panel__meta"><?php esc_html_e( 'Sample data', 'epasscard' ); ?></span>
					</div>
					<?php EPC_Card_Preview::render( $selected ); ?>
				</div>
			</aside>
		</div>
		<?php
	}

	/**
	 * Plain-words rules of an integration.
	 *
	 * @param EPC_Module $module Module.
	 * @return array<int, string>
	 */
	public static function rule_lines( $module ) {
		$slug  = $module->get_slug();
		$lines = array();

		if ( 'woocommerce-loyalty' === $slug ) {
			$rules  = EPC_Loyalty_Rule_Service::get_rules();
			$active = array_values(
				array_filter(
					$rules,
					static function ( $rule ) {
						return ! empty( $rule['active'] );
					}
				)
			);
			if ( empty( $active ) ) {
				// What confirming turns on.
				$active = array_values(
					array_filter(
						$rules,
						static function ( $rule ) {
							return in_array( $rule['id'], self::STARTER_RULES, true );
						}
					)
				);
			}
			foreach ( $active as $rule ) {
				$lines[] = (string) $rule['name'];
			}

			$tiers = array();
			$all   = EPC_Loyalty_Reward_Service::get_tiers();
			foreach ( $all as $tier ) {
				if ( ! empty( $tier['active'] ) ) {
					$tiers[] = (string) $tier['name'];
				}
			}
			if ( empty( $tiers ) && ! self::loyalty_has_active_rule() ) {
				foreach ( $all as $tier ) {
					if ( in_array( $tier['id'], self::STARTER_TIERS, true ) ) {
						$tiers[] = (string) $tier['name'];
					}
				}
			}
			if ( ! empty( $tiers ) ) {
				/* translators: %s: list of tier names */
				$lines[] = sprintf( __( 'Tiers: %s', 'epasscard' ), implode( ', ', $tiers ) );
			}

			$settings = EPC_Loyalty_Order_Service::get_settings();
			$labels   = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
			$statuses = array();
			foreach ( (array) $settings['qualifying_statuses'] as $status ) {
				$statuses[] = isset( $labels[ 'wc-' . $status ] ) ? (string) $labels[ 'wc-' . $status ] : (string) $status;
			}
			if ( ! empty( $statuses ) ) {
				/* translators: %s: list of order statuses, e.g. "Processing, Completed" */
				$lines[] = sprintf( __( 'Orders earn points when they are: %s', 'epasscard' ), implode( ', ', $statuses ) );
			}
			$lines[] = __( 'Points count the products after discounts; tax and shipping do not earn points. Refunds take points back.', 'epasscard' );
			$lines[] = __( 'Only customers with an account earn points; guest orders do not.', 'epasscard' );
			return $lines;
		}

		if ( $module->has_pass_behavior_settings() ) {
			$statuses = $module->get_pass_behavior_statuses();
			$actions  = $module->get_status_action_options();
			foreach ( $module->get_status_rules() as $status => $action ) {
				if ( ! isset( $statuses[ $status ], $actions[ $action ] ) ) {
					continue;
				}
				/* translators: 1: status, e.g. "Expired", 2: what happens to the card */
				$lines[] = sprintf( __( '%1$s: %2$s', 'epasscard' ), $statuses[ $status ], $actions[ $action ] );
			}
		}

		if ( 'the-events-calendar' === $slug ) {
			array_unshift( $lines, __( 'One ticket for each attendee.', 'epasscard' ) );
		} elseif ( 'events-manager' === $slug ) {
			array_unshift( $lines, __( 'One ticket for each booking.', 'epasscard' ) );
		}

		if ( empty( $lines ) ) {
			$lines[] = __( 'Each card is kept up to date when the record changes in the plugin.', 'epasscard' );
		}

		return $lines;
	}

	/**
	 * Where the merchant changes the rules today.
	 *
	 * @param EPC_Module $module Module.
	 * @return string
	 */
	private static function rules_url( $module ) {
		if ( 'woocommerce-loyalty' === $module->get_slug() ) {
			return admin_url( 'admin.php?page=epc-woocommerce-loyalty#epc-section-loyalty-program' );
		}
		if ( $module->has_pass_behavior_settings() ) {
			return EPC_Admin_Design::url_for_module( $module->get_slug() ) . '#epc-section-pass-behavior';
		}
		return '';
	}

	/**
	 * How the design step starts.
	 *
	 * @param EPC_Module $module Module.
	 * @return string
	 */
	private static function design_line( $module ) {
		if ( EPC_Jobs::has_design( $module ) ) {
			return __( 'You already have a design. You can change it next.', 'epasscard' );
		}
		if ( 'woocommerce-loyalty' === $module->get_slug() || EPC_Template_Starter::supports( $module ) ) {
			return __( 'Next you get a ready-made design with your site name. Add your logo and colours, or use a template you built in EpassCard.', 'epasscard' );
		}
		return __( 'Next you choose a template from your EpassCard account.', 'epasscard' );
	}

	/**
	 * Where customers find their card.
	 *
	 * @param EPC_Module $module Module.
	 * @return array<int, string>
	 */
	private static function delivery_lines( $module ) {
		$lines = array();
		$email = EPC_Pass_Email::get_settings();
		$lines[] = ! empty( $email['auto_on_create'] )
			? __( 'By email from your site when the card is created.', 'epasscard' )
			: __( 'Not by email: the automatic email is off (Settings → Emails).', 'epasscard' );
		if ( function_exists( 'epc_is_woocommerce_active' ) && epc_is_woocommerce_active() ) {
			$lines[] = __( 'In My Account on your store.', 'epasscard' );
		}
		$lines[] = __( 'On any page where you add the [epc_my_passes] shortcode.', 'epasscard' );
		return $lines;
	}

	/**
	 * Whether the loyalty program has an active earning rule.
	 *
	 * @return bool
	 */
	private static function loyalty_has_active_rule() {
		foreach ( EPC_Loyalty_Rule_Service::get_rules() as $rule ) {
			if ( ! empty( $rule['active'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Admin-post: confirm the job.
	 *
	 * @return void
	 */
	public static function handle_confirm() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'epasscard' ) );
		}
		check_admin_referer( 'epc_setup_confirm' );

		$job       = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( (string) $_POST['job'] ) ) : '';
		$slug      = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( (string) $_POST['module'] ) ) : '';
		$available = EPC_Jobs::available_modules( $job );
		if ( ! isset( $available[ $slug ] ) ) {
			wp_die( esc_html__( 'That integration is not available. Install and activate its plugin first.', 'epasscard' ), '', array( 'back_link' => true ) );
		}

		$enabled = EPC_Module_Settings::get_saved_slugs();
		if ( ! in_array( $slug, $enabled, true ) ) {
			$enabled[] = $slug;
			EPC_Module_Settings::save_enabled_slugs( $enabled );
		}

		// A new loyalty program starts with the starter rules; a program with rules is left as it is.
		if ( 'woocommerce-loyalty' === $slug && ! self::loyalty_has_active_rule() ) {
			$rules = EPC_Loyalty_Rule_Service::get_rules();
			foreach ( $rules as &$rule ) {
				if ( in_array( $rule['id'], self::STARTER_RULES, true ) ) {
					$rule['active'] = true;
				}
			}
			unset( $rule );
			EPC_Loyalty_Rule_Service::save_rules( $rules );

			$tiers      = EPC_Loyalty_Reward_Service::get_tiers();
			$any_active = false;
			foreach ( $tiers as $tier ) {
				$any_active = $any_active || ! empty( $tier['active'] );
			}
			if ( ! $any_active ) {
				foreach ( $tiers as &$tier ) {
					if ( in_array( $tier['id'], self::STARTER_TIERS, true ) ) {
						$tier['active'] = true;
					}
				}
				unset( $tier );
				EPC_Loyalty_Reward_Service::save_tiers( $tiers );
			}
		}

		EPC_Onboarding::update(
			array(
				'job'             => $job,
				'module'          => $slug,
				'rules_confirmed' => true,
			)
		);

		wp_safe_redirect( EPC_Admin_App::url( 'home' ) );
		exit;
	}
}
