<?php
/**
 * Setup wizard step markup (full page and AJAX refresh).
 *
 * @package EpassCard
 *
 * @var array<string, mixed> $state
 * @var string $step
 * @var array<string, array<string, mixed>> $goals
 * @var object|null $pass
 * @var array<string, mixed> $design
 * @var string $restart_url
 * @var array<int, array<string, mixed>> $entities
 * @var EPC_Module|null $module_obj
 * @var string $module_slug
 * @var array<string, string> $steps
 * @var array<int, string> $order
 * @var int $step_index
 * @var string $pass_link
 * @var string $blog
 * @var bool $is_loyalty
 * @var string $goal
 * @var array{template_name:string,preview_kicker:string,preview_title:string,header_label:string,header_value:string,secondary_label:string,secondary_value:string,barcode_alt:string} $starter
 * @var array{notice:string,checklist:array<int,string>,fields:array<int,string>} $builder
 * @var bool $can_go_back
 * @var bool $is_connected
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_loyalty = ! empty( $is_loyalty );
$goal       = isset( $goal ) ? sanitize_key( (string) $goal ) : '';
$starter    = isset( $starter ) && is_array( $starter ) ? $starter : array();
$builder    = isset( $builder ) && is_array( $builder ) ? $builder : EPC_Setup_Wizard::builder_guidance_for_goal( $is_loyalty ? 'loyalty' : ( $goal ? $goal : 'membership' ) );
?>
<ol class="epc-wizard__steps" aria-label="<?php esc_attr_e( 'Setup steps', 'epasscard' ); ?>">
	<?php foreach ( $steps as $id => $label ) : ?>
		<?php
		$index   = array_search( $id, $order, true );
		$classes = 'epc-wizard__step';
		if ( $id === $step ) {
			$classes .= ' is-current';
		} elseif ( false !== $index && $index < $step_index ) {
			$classes .= ' is-done';
		}
		?>
		<li class="<?php echo esc_attr( $classes ); ?>">
			<span class="epc-wizard__step-num"><?php echo esc_html( (string) ( (int) $index + 1 ) ); ?></span>
			<span class="epc-wizard__step-label"><?php echo esc_html( $label ); ?></span>
		</li>
	<?php endforeach; ?>
</ol>

<p class="epc-wizard__status" id="epc-wizard-status" aria-live="polite"></p>

<section class="epc-wizard__panel" data-panel="welcome" <?php echo 'welcome' === $step ? '' : 'hidden'; ?>>
	<div class="epc-wizard__hero">
		<div class="epc-wizard__hero-copy">
			<p class="epc-wizard__eyebrow"><?php esc_html_e( 'Apple Wallet & Google Wallet', 'epasscard' ); ?></p>
			<h1 class="epc-wizard__title"><?php esc_html_e( 'Get your first wallet pass live', 'epasscard' ); ?></h1>
			<p class="epc-wizard__lead"><?php esc_html_e( 'Connect EpassCard, pick a use case, and finish a short setup. You will open a real pass on your phone — no coding required.', 'epasscard' ); ?></p>
			<ul class="epc-wizard__pills">
				<li><span class="epc-icon" aria-hidden="true">schedule</span><?php esc_html_e( 'A few minutes', 'epasscard' ); ?></li>
				<li><span class="epc-icon" aria-hidden="true">code_off</span><?php esc_html_e( 'No coding', 'epasscard' ); ?></li>
				<li><span class="epc-icon" aria-hidden="true">undo</span><?php esc_html_e( 'Skip anytime', 'epasscard' ); ?></li>
			</ul>
			<p class="epc-wizard__actions">
				<button type="button" class="button button-primary epc-wizard__btn" data-epc-wizard-welcome><?php esc_html_e( 'Start setup', 'epasscard' ); ?></button>
				<button type="button" class="button-link" data-epc-wizard-skip><?php esc_html_e( 'Skip for now', 'epasscard' ); ?></button>
			</p>
		</div>
		<div class="epc-wizard-pass epc-wizard-pass--hero" aria-hidden="true">
			<div class="epc-aw__header">
				<div class="epc-aw__brand">
					<span class="epc-aw__logo"><?php echo esc_html( strtoupper( substr( $blog ? $blog : 'E', 0, 1 ) ) ); ?></span>
					<span class="epc-aw__org"><?php echo esc_html( $blog ? $blog : __( 'Your store', 'epasscard' ) ); ?></span>
				</div>
				<div class="epc-aw__hfield">
					<span class="epc-aw__label"><?php esc_html_e( 'Points', 'epasscard' ); ?></span>
					<span class="epc-aw__hvalue">1,240</span>
				</div>
			</div>
			<div class="epc-aw__strip">
				<span class="epc-aw__strip-kicker"><?php esc_html_e( 'Loyalty', 'epasscard' ); ?></span>
				<span class="epc-aw__strip-title"><?php esc_html_e( 'Gold member', 'epasscard' ); ?></span>
			</div>
			<div class="epc-aw__fields epc-aw__fields--aux">
				<div class="epc-aw__field">
					<span class="epc-aw__label"><?php esc_html_e( 'Name', 'epasscard' ); ?></span>
					<span class="epc-aw__value"><?php esc_html_e( 'Alex Rivera', 'epasscard' ); ?></span>
				</div>
				<div class="epc-aw__field epc-aw__field--end">
					<span class="epc-aw__label"><?php esc_html_e( 'Tier', 'epasscard' ); ?></span>
					<span class="epc-aw__value"><?php esc_html_e( 'Gold', 'epasscard' ); ?></span>
				</div>
			</div>
			<div class="epc-aw__barcode">
				<span class="epc-aw__qr" aria-hidden="true">
					<img src="<?php echo esc_url( EPC_PLUGIN_URL . 'admin/images/wizard-dummy-qr.png' ); ?>" alt="" width="78" height="78" />
				</span>
				<span class="epc-aw__alt">LYL-004821</span>
			</div>
		</div>
	</div>
</section>

<section class="epc-wizard__panel" data-panel="connect" <?php echo 'connect' === $step ? '' : 'hidden'; ?>>
	<header class="epc-wizard__header">
		<h2 class="epc-wizard__title"><?php esc_html_e( 'Connect your EpassCard account', 'epasscard' ); ?></h2>
		<p class="epc-wizard__lead"><?php esc_html_e( 'Passes are designed and stored on EpassCard. Create a free account, or sign in if you already have one.', 'epasscard' ); ?></p>
	</header>
	<?php if ( ! empty( $is_connected ) ) : ?>
		<div class="epc-wizard__notice epc-wizard__notice--ok">
			<p><?php esc_html_e( 'Your EpassCard account is connected. Continue to choose what customers should receive.', 'epasscard' ); ?></p>
		</div>
		<p class="epc-wizard__actions">
			<button type="button" class="button" data-epc-wizard-back><?php esc_html_e( 'Back', 'epasscard' ); ?></button>
			<button type="button" class="button button-primary epc-wizard__btn" data-epc-wizard-continue-connected><?php esc_html_e( 'Continue', 'epasscard' ); ?></button>
			<button type="button" class="button-link" data-epc-wizard-skip><?php esc_html_e( 'Skip for now', 'epasscard' ); ?></button>
		</p>
	<?php else : ?>
	<div class="epc-wizard__connect-grid">
		<div class="epc-wizard__tile">
			<div class="epc-wizard__tile-head">
				<span class="epc-wizard__tile-icon"><span class="epc-icon" aria-hidden="true">person_add</span></span>
				<h3><?php esc_html_e( 'New here', 'epasscard' ); ?></h3>
			</div>
			<form id="epc-wizard-signup" class="epc-wizard__form">
				<label class="epc-wizard__field" for="epc-wizard-signup-name">
					<span><?php esc_html_e( 'Name / business name', 'epasscard' ); ?></span>
					<input type="text" id="epc-wizard-signup-name" required minlength="2" maxlength="255" autocomplete="organization" />
				</label>
				<label class="epc-wizard__field" for="epc-wizard-signup-email">
					<span><?php esc_html_e( 'Email', 'epasscard' ); ?></span>
					<input type="email" id="epc-wizard-signup-email" required maxlength="254" autocomplete="email" />
				</label>
				<button type="submit" class="button button-primary epc-wizard__btn"><?php esc_html_e( 'Create account and connect', 'epasscard' ); ?></button>
				<p class="epc-wizard__hint"><?php esc_html_e( 'A password will be emailed to you.', 'epasscard' ); ?></p>
			</form>
		</div>
		<div class="epc-wizard__tile">
			<div class="epc-wizard__tile-head">
				<span class="epc-wizard__tile-icon"><span class="epc-icon" aria-hidden="true">login</span></span>
				<h3><?php esc_html_e( 'Already have an account', 'epasscard' ); ?></h3>
			</div>
			<div class="epc-wizard__form">
				<label class="epc-wizard__field" for="epc-wizard-email">
					<span><?php esc_html_e( 'Email', 'epasscard' ); ?></span>
					<input type="email" id="epc-wizard-email" autocomplete="username" />
				</label>
				<label class="epc-wizard__field" for="epc-wizard-password">
					<span><?php esc_html_e( 'Password', 'epasscard' ); ?></span>
					<input type="password" id="epc-wizard-password" autocomplete="current-password" />
				</label>
				<button type="button" class="button button-primary epc-wizard__btn" id="epc-wizard-signin"><?php esc_html_e( 'Sign in and connect', 'epasscard' ); ?></button>
				<div class="epc-wizard__divider"><span><?php esc_html_e( 'or', 'epasscard' ); ?></span></div>
				<label class="epc-wizard__field" for="epc-wizard-api-key">
					<span><?php esc_html_e( 'Paste an API key', 'epasscard' ); ?></span>
					<input type="password" id="epc-wizard-api-key" autocomplete="off" />
				</label>
				<div class="epc-wizard__inline-actions">
					<button type="button" class="button epc-wizard__btn" id="epc-wizard-connect-key"><?php esc_html_e( 'Connect with API key', 'epasscard' ); ?></button>
					<a class="button-link" href="https://app.epasscard.com/api-keys" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get an API key', 'epasscard' ); ?></a>
				</div>
			</div>
		</div>
	</div>
	<p class="epc-wizard__actions">
		<button type="button" class="button" data-epc-wizard-back><?php esc_html_e( 'Back', 'epasscard' ); ?></button>
		<button type="button" class="button-link" data-epc-wizard-skip><?php esc_html_e( 'Skip for now', 'epasscard' ); ?></button>
	</p>
	<?php endif; ?>
</section>

<section class="epc-wizard__panel" data-panel="choose" <?php echo 'choose' === $step ? '' : 'hidden'; ?>>
	<header class="epc-wizard__header">
		<h2 class="epc-wizard__title"><?php esc_html_e( 'What should customers receive?', 'epasscard' ); ?></h2>
		<p class="epc-wizard__lead"><?php esc_html_e( 'Pick one to finish first. You can enable more integrations later from Connection.', 'epasscard' ); ?></p>
	</header>
	<form id="epc-wizard-goal-form">
		<div class="epc-wizard__goals">
			<?php foreach ( $goals as $goal_id => $goal ) : ?>
				<?php
				$available = EPC_Setup_Wizard::available_modules_for_goal( $goal_id );
				$plugins   = EPC_Setup_Wizard::required_plugin_actions_for_goal( $goal_id );
				$ready     = ! empty( $available );
				$icon      = sanitize_key( (string) ( $goal['icon'] ?? 'wallet' ) );
				?>
				<label class="epc-wizard__goal<?php echo $ready ? '' : ' is-disabled'; ?>">
					<input type="radio" name="epc_wizard_goal" value="<?php echo esc_attr( $goal_id ); ?>" <?php checked( ( $state['goal'] ?? '' ) === $goal_id ); ?> <?php disabled( ! $ready ); ?> />
					<span class="epc-wizard__goal-icon" aria-hidden="true"><span class="epc-icon"><?php echo esc_html( $icon ); ?></span></span>
					<span class="epc-wizard__goal-body">
						<strong><?php echo esc_html( (string) $goal['label'] ); ?></strong>
						<span class="epc-wizard__goal-desc"><?php echo esc_html( (string) $goal['description'] ); ?></span>
						<?php if ( ! $ready && ! empty( $plugins ) ) : ?>
							<span class="epc-wizard__requires">
								<span class="epc-wizard__requires-label"><?php echo 1 === count( $plugins ) ? esc_html__( 'Requires', 'epasscard' ) : esc_html__( 'Requires one of', 'epasscard' ); ?></span>
								<?php foreach ( $plugins as $plugin ) : ?>
									<?php
									if ( is_string( $plugin ) ) {
										$plugin = array(
											'key'         => sanitize_title( $plugin ),
											'label'       => $plugin,
											'mode'        => 'external',
											'status'      => 'missing',
											'can_install' => false,
											'url'         => '',
										);
									}
									$pkey    = (string) ( $plugin['key'] ?? '' );
									$plabel  = (string) ( $plugin['label'] ?? '' );
									$pmode   = (string) ( $plugin['mode'] ?? 'external' );
									$pstatus = (string) ( $plugin['status'] ?? 'missing' );
									$purl    = (string) ( $plugin['url'] ?? '' );
									$can     = ! empty( $plugin['can_install'] );
									?>
									<span class="epc-wizard__plugin" data-plugin-key="<?php echo esc_attr( $pkey ); ?>" data-plugin-status="<?php echo esc_attr( $pstatus ); ?>">
										<span class="epc-wizard__plugin-name"><?php echo esc_html( $plabel ); ?></span>
										<?php if ( 'active' === $pstatus ) : ?>
											<span class="epc-wizard__plugin-ready"><?php esc_html_e( 'Ready', 'epasscard' ); ?></span>
										<?php elseif ( 'wporg' === $pmode && $can ) : ?>
											<button
												type="button"
												class="epc-wizard__plugin-action"
												data-epc-install="<?php echo esc_attr( $pkey ); ?>"
												data-epc-install-status="<?php echo esc_attr( $pstatus ); ?>"
											>
												<?php echo 'installed' === $pstatus ? esc_html__( 'Activate', 'epasscard' ) : esc_html__( 'Install', 'epasscard' ); ?>
											</button>
										<?php elseif ( 'wporg' === $pmode ) : ?>
											<a
												class="epc-wizard__plugin-action"
												href="<?php echo esc_url( admin_url( 'plugin-install.php?s=' . rawurlencode( (string) ( $plugin['slug'] ?? $pkey ) ) . '&tab=search&type=term' ) ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												data-epc-stop-label
											>
												<?php esc_html_e( 'Plugins', 'epasscard' ); ?>
											</a>
										<?php elseif ( '' !== $purl ) : ?>
											<a
												class="epc-wizard__plugin-action"
												href="<?php echo esc_url( $purl ); ?>"
												target="_blank"
												rel="noopener noreferrer"
												data-epc-stop-label
											>
												<?php esc_html_e( 'Get plugin', 'epasscard' ); ?>
											</a>
										<?php endif; ?>
									</span>
								<?php endforeach; ?>
							</span>
						<?php endif; ?>
						<?php if ( $ready ) : ?>
							<span class="epc-wizard__module-pick">
								<span class="epc-wizard__module-pick-icon" aria-hidden="true"><span class="epc-icon">extension</span></span>
								<select class="epc-wizard__module-select" data-goal="<?php echo esc_attr( $goal_id ); ?>">
									<?php foreach ( $available as $mod ) : ?>
										<option value="<?php echo esc_attr( $mod['slug'] ); ?>" <?php selected( ( $state['module'] ?? '' ) === $mod['slug'] ); ?>>
											<?php echo esc_html( $mod['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</span>
						<?php else : ?>
							<span class="epc-wizard__missing"><?php esc_html_e( 'Not installed yet.', 'epasscard' ); ?></span>
						<?php endif; ?>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="epc-wizard__actions">
			<button type="button" class="button" data-epc-wizard-back><?php esc_html_e( 'Back', 'epasscard' ); ?></button>
			<button type="submit" class="button button-primary epc-wizard__btn"><?php esc_html_e( 'Continue', 'epasscard' ); ?></button>
			<button type="button" class="button-link" data-epc-wizard-skip><?php esc_html_e( 'Skip for now', 'epasscard' ); ?></button>
		</p>
	</form>
</section>

<section class="epc-wizard__panel" data-panel="setup" <?php echo 'setup' === $step ? '' : 'hidden'; ?>>
	<header class="epc-wizard__header">
		<h2 class="epc-wizard__title"><?php esc_html_e( 'Finish setup for this use case', 'epasscard' ); ?></h2>
		<?php if ( $is_loyalty ) : ?>
			<p class="epc-wizard__lead"><?php esc_html_e( 'We will turn on starter earning rules and create a loyalty card template. You can change colors and rules later.', 'epasscard' ); ?></p>
		<?php else : ?>
			<p class="epc-wizard__lead"><?php esc_html_e( 'Create a starter pass template here, or pick one you already designed in EpassCard. Then choose the WordPress item it should fill — common fields map automatically.', 'epasscard' ); ?></p>
		<?php endif; ?>
	</header>

	<?php if ( $is_loyalty ) : ?>
		<form id="epc-wizard-setup-form" class="epc-wizard__setup" data-kind="loyalty">
			<div class="epc-wizard__setup-intro">
				<fieldset class="epc-wizard__source epc-wizard__source--cards">
					<legend class="epc-wizard__source-legend"><?php esc_html_e( 'How do you want to start?', 'epasscard' ); ?></legend>
					<label class="epc-wizard__source-option">
						<input type="radio" name="design_source" value="form" checked />
						<span class="epc-wizard__source-copy">
							<strong><?php esc_html_e( 'Create a starter card', 'epasscard' ); ?></strong>
							<span><?php esc_html_e( 'Pick colors and a name here. We create the template for you.', 'epasscard' ); ?></span>
						</span>
					</label>
					<label class="epc-wizard__source-option">
						<input type="radio" name="design_source" value="builder" />
						<span class="epc-wizard__source-copy">
							<strong><?php esc_html_e( 'Use template builder', 'epasscard' ); ?></strong>
							<span><?php esc_html_e( 'Choose a template you already designed in EpassCard.', 'epasscard' ); ?></span>
						</span>
					</label>
				</fieldset>
			</div>
			<div class="epc-wizard__setup-grid" data-epc-wizard-design="form">
				<div class="epc-wizard__form">
					<label class="epc-wizard__field" for="epc-wizard-template-name">
						<span><?php esc_html_e( 'Card name', 'epasscard' ); ?></span>
						<input type="text" id="epc-wizard-template-name" value="<?php echo esc_attr( $blog ? $blog . ' Loyalty' : 'Loyalty' ); ?>" />
					</label>
					<div class="epc-wizard__colors">
						<label class="epc-wizard__color" for="epc-wizard-bg">
							<span><?php esc_html_e( 'Background', 'epasscard' ); ?></span>
							<input type="color" id="epc-wizard-bg" value="#1E1B4B" />
						</label>
						<label class="epc-wizard__color" for="epc-wizard-text">
							<span><?php esc_html_e( 'Text', 'epasscard' ); ?></span>
							<input type="color" id="epc-wizard-text" value="#FFFFFF" />
						</label>
					</div>
					<label class="epc-wizard__field" for="epc-wizard-logo">
						<span><?php esc_html_e( 'Logo URL (optional)', 'epasscard' ); ?></span>
						<span class="epc-wizard__field-row">
							<input type="url" id="epc-wizard-logo" value="" placeholder="<?php esc_attr_e( 'https://example.com/logo.png', 'epasscard' ); ?>" />
							<button type="button" class="button" data-epc-wizard-media="logo"><?php esc_html_e( 'Select logo', 'epasscard' ); ?></button>
						</span>
					</label>
					<p class="epc-wizard__hint"><?php esc_html_e( 'Leave blank to use default images. Recommended logo size: 200 × 200 px. The URL must be public https — localhost uploads cannot be downloaded by EpassCard.', 'epasscard' ); ?></p>
				</div>
				<?php
				$preview_name = $blog ? $blog . ' Loyalty' : __( 'Loyalty', 'epasscard' );
				$preview_mark = strtoupper( substr( $preview_name, 0, 1 ) );
				?>
				<div class="epc-wizard__preview">
					<p class="epc-wizard__preview-label"><?php esc_html_e( 'Preview', 'epasscard' ); ?></p>
					<div class="epc-wizard-pass epc-wizard-pass--hero epc-wizard-pass--live" id="epc-wizard-pass-preview" aria-hidden="true">
						<div class="epc-aw__header">
							<div class="epc-aw__brand">
								<span class="epc-aw__logo" id="epc-wizard-pass-logo-wrap">
									<img class="epc-aw__logo-img" id="epc-wizard-pass-logo" alt="" hidden />
									<span id="epc-wizard-pass-mark"><?php echo esc_html( $preview_mark ? $preview_mark : 'L' ); ?></span>
								</span>
								<span class="epc-aw__org" id="epc-wizard-pass-name"><?php echo esc_html( $preview_name ); ?></span>
							</div>
							<div class="epc-aw__hfield">
								<span class="epc-aw__label"><?php esc_html_e( 'Points', 'epasscard' ); ?></span>
								<span class="epc-aw__hvalue">1,240</span>
							</div>
						</div>
						<div class="epc-aw__strip">
							<span class="epc-aw__strip-kicker"><?php esc_html_e( 'Loyalty', 'epasscard' ); ?></span>
							<span class="epc-aw__strip-title"><?php esc_html_e( 'Gold member', 'epasscard' ); ?></span>
						</div>
						<div class="epc-aw__fields epc-aw__fields--aux">
							<div class="epc-aw__field">
								<span class="epc-aw__label"><?php esc_html_e( 'Name', 'epasscard' ); ?></span>
								<span class="epc-aw__value"><?php esc_html_e( 'Alex Rivera', 'epasscard' ); ?></span>
							</div>
							<div class="epc-aw__field epc-aw__field--end">
								<span class="epc-aw__label"><?php esc_html_e( 'Tier', 'epasscard' ); ?></span>
								<span class="epc-aw__value"><?php esc_html_e( 'Gold', 'epasscard' ); ?></span>
							</div>
						</div>
						<div class="epc-aw__barcode">
							<span class="epc-aw__qr" aria-hidden="true">
								<img src="<?php echo esc_url( EPC_PLUGIN_URL . 'admin/images/wizard-dummy-qr.png' ); ?>" alt="" width="78" height="78" />
							</span>
							<span class="epc-aw__alt">LYL-004821</span>
						</div>
					</div>
				</div>
			</div>
			<?php
			$builder_select_id    = 'epc-wizard-template-select';
			$builder_preview_id   = 'loyalty';
			$builder_preview_name = $blog ? $blog . ' Loyalty' : __( 'Loyalty', 'epasscard' );
			$builder_disabled     = false;
			include EPC_PLUGIN_DIR . 'admin/views/setup-wizard-builder-panel.php';
			?>
			<p class="epc-wizard__actions">
				<button type="button" class="button" data-epc-wizard-back><?php esc_html_e( 'Back', 'epasscard' ); ?></button>
				<button type="submit" class="button button-primary epc-wizard__btn"><?php esc_html_e( 'Save and continue', 'epasscard' ); ?></button>
			</p>
		</form>
	<?php else : ?>
		<?php if ( empty( $entities ) ) : ?>
			<div class="epc-wizard__notice">
				<p><?php esc_html_e( 'No mappable items were found. Create a membership, event, or gift card product first, then return here.', 'epasscard' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
		$starter_name = isset( $starter['template_name'] ) ? (string) $starter['template_name'] : ( $blog ? $blog : __( 'Pass', 'epasscard' ) );
		$preview_mark = strtoupper( substr( $starter_name, 0, 1 ) );
		$entity_label = $module_obj ? $module_obj->get_entity_column_label() : __( 'Item', 'epasscard' );
		?>
		<form id="epc-wizard-setup-form" class="epc-wizard__setup" data-kind="mapping">
			<div class="epc-wizard__setup-intro">
				<label class="epc-wizard__field epc-wizard__field--compact" for="epc-wizard-entity">
					<span><?php echo esc_html( $entity_label ); ?></span>
					<select id="epc-wizard-entity" <?php disabled( empty( $entities ) ); ?>>
						<?php if ( empty( $entities ) ) : ?>
							<option value=""><?php esc_html_e( 'No items available yet', 'epasscard' ); ?></option>
						<?php else : ?>
							<?php foreach ( $entities as $entity ) : ?>
								<option value="<?php echo esc_attr( (string) (int) $entity['id'] ); ?>"><?php echo esc_html( (string) $entity['label'] ); ?></option>
							<?php endforeach; ?>
						<?php endif; ?>
					</select>
				</label>
				<fieldset class="epc-wizard__source epc-wizard__source--cards">
					<legend class="epc-wizard__source-legend"><?php esc_html_e( 'How do you want to start?', 'epasscard' ); ?></legend>
					<label class="epc-wizard__source-option">
						<input type="radio" name="design_source" value="form" checked <?php disabled( empty( $entities ) ); ?> />
						<span class="epc-wizard__source-copy">
							<strong><?php esc_html_e( 'Create a starter template', 'epasscard' ); ?></strong>
							<span><?php esc_html_e( 'Design a simple pass here, then we map it automatically.', 'epasscard' ); ?></span>
						</span>
					</label>
					<label class="epc-wizard__source-option">
						<input type="radio" name="design_source" value="builder" <?php disabled( empty( $entities ) ); ?> />
						<span class="epc-wizard__source-copy">
							<strong><?php esc_html_e( 'Use template builder', 'epasscard' ); ?></strong>
							<span><?php esc_html_e( 'Pick a template you already designed in EpassCard.', 'epasscard' ); ?></span>
						</span>
					</label>
				</fieldset>
			</div>
			<div class="epc-wizard__setup-grid" data-epc-wizard-design="form">
				<div class="epc-wizard__form">
					<label class="epc-wizard__field" for="epc-wizard-template-name">
						<span><?php esc_html_e( 'Card name', 'epasscard' ); ?></span>
						<input type="text" id="epc-wizard-template-name" value="<?php echo esc_attr( $starter_name ); ?>" <?php disabled( empty( $entities ) ); ?> />
					</label>
					<div class="epc-wizard__colors">
						<label class="epc-wizard__color" for="epc-wizard-bg">
							<span><?php esc_html_e( 'Background', 'epasscard' ); ?></span>
							<input type="color" id="epc-wizard-bg" value="#1E1B4B" <?php disabled( empty( $entities ) ); ?> />
						</label>
						<label class="epc-wizard__color" for="epc-wizard-text">
							<span><?php esc_html_e( 'Text', 'epasscard' ); ?></span>
							<input type="color" id="epc-wizard-text" value="#FFFFFF" <?php disabled( empty( $entities ) ); ?> />
						</label>
					</div>
					<label class="epc-wizard__field" for="epc-wizard-logo">
						<span><?php esc_html_e( 'Logo URL (optional)', 'epasscard' ); ?></span>
						<span class="epc-wizard__field-row">
							<input type="url" id="epc-wizard-logo" value="" placeholder="<?php esc_attr_e( 'https://example.com/logo.png', 'epasscard' ); ?>" <?php disabled( empty( $entities ) ); ?> />
							<button type="button" class="button" data-epc-wizard-media="logo" <?php disabled( empty( $entities ) ); ?>><?php esc_html_e( 'Select logo', 'epasscard' ); ?></button>
						</span>
					</label>
					<p class="epc-wizard__hint"><?php esc_html_e( 'Leave blank to use default images. Recommended logo size: 200 × 200 px. The URL must be public https — localhost uploads cannot be downloaded by EpassCard.', 'epasscard' ); ?></p>
				</div>
				<div class="epc-wizard__preview">
					<p class="epc-wizard__preview-label"><?php esc_html_e( 'Preview', 'epasscard' ); ?></p>
					<div class="epc-wizard-pass epc-wizard-pass--hero epc-wizard-pass--live" id="epc-wizard-pass-preview" aria-hidden="true">
						<div class="epc-aw__header">
							<div class="epc-aw__brand">
								<span class="epc-aw__logo" id="epc-wizard-pass-logo-wrap">
									<img class="epc-aw__logo-img" id="epc-wizard-pass-logo" alt="" hidden />
									<span id="epc-wizard-pass-mark"><?php echo esc_html( $preview_mark ? $preview_mark : 'P' ); ?></span>
								</span>
								<span class="epc-aw__org" id="epc-wizard-pass-name"><?php echo esc_html( $starter_name ); ?></span>
							</div>
							<div class="epc-aw__hfield">
								<span class="epc-aw__label"><?php echo esc_html( (string) ( $starter['header_label'] ?? __( 'Status', 'epasscard' ) ) ); ?></span>
								<span class="epc-aw__hvalue"><?php echo esc_html( (string) ( $starter['header_value'] ?? '' ) ); ?></span>
							</div>
						</div>
						<div class="epc-aw__strip">
							<span class="epc-aw__strip-kicker"><?php echo esc_html( (string) ( $starter['preview_kicker'] ?? '' ) ); ?></span>
							<span class="epc-aw__strip-title"><?php echo esc_html( (string) ( $starter['preview_title'] ?? '' ) ); ?></span>
						</div>
						<div class="epc-aw__fields epc-aw__fields--aux">
							<div class="epc-aw__field">
								<span class="epc-aw__label"><?php esc_html_e( 'Name', 'epasscard' ); ?></span>
								<span class="epc-aw__value"><?php esc_html_e( 'Alex Rivera', 'epasscard' ); ?></span>
							</div>
							<div class="epc-aw__field epc-aw__field--end">
								<span class="epc-aw__label"><?php echo esc_html( (string) ( $starter['secondary_label'] ?? '' ) ); ?></span>
								<span class="epc-aw__value"><?php echo esc_html( (string) ( $starter['secondary_value'] ?? '' ) ); ?></span>
							</div>
						</div>
						<div class="epc-aw__barcode">
							<span class="epc-aw__qr" aria-hidden="true">
								<img src="<?php echo esc_url( EPC_PLUGIN_URL . 'admin/images/wizard-dummy-qr.png' ); ?>" alt="" width="78" height="78" />
							</span>
							<span class="epc-aw__alt"><?php echo esc_html( (string) ( $starter['barcode_alt'] ?? 'PASS-0001' ) ); ?></span>
						</div>
					</div>
				</div>
			</div>
			<?php
			$builder_select_id    = 'epc-wizard-map-template';
			$builder_preview_id   = 'map';
			$builder_preview_name = $starter_name;
			$builder_disabled     = empty( $entities );
			include EPC_PLUGIN_DIR . 'admin/views/setup-wizard-builder-panel.php';
			?>
			<p class="epc-wizard__actions">
				<button type="button" class="button" data-epc-wizard-back><?php esc_html_e( 'Back', 'epasscard' ); ?></button>
				<button type="submit" class="button button-primary epc-wizard__btn" <?php disabled( empty( $entities ) ); ?>><?php esc_html_e( 'Save mapping and continue', 'epasscard' ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</section>

<section class="epc-wizard__panel" data-panel="result" <?php echo 'result' === $step ? '' : 'hidden'; ?>>
	<div class="epc-wizard__result-hero">
		<span class="epc-wizard__result-icon" aria-hidden="true"><span class="epc-icon">wallet</span></span>
		<h2 class="epc-wizard__title"><?php esc_html_e( 'See your first pass', 'epasscard' ); ?></h2>
		<?php if ( $is_loyalty ) : ?>
			<p class="epc-wizard__lead"><?php esc_html_e( 'Create a test loyalty pass for your WordPress admin account, then open the link on your phone and tap Add to Apple Wallet or Add to Google Wallet.', 'epasscard' ); ?></p>
			<p class="epc-wizard__actions">
				<button type="button" class="button button-primary epc-wizard__btn" data-epc-wizard-issue><?php echo $pass_link ? esc_html__( 'Refresh test pass', 'epasscard' ) : esc_html__( 'Create my test pass', 'epasscard' ); ?></button>
			</p>
		<?php else : ?>
			<p class="epc-wizard__lead"><?php esc_html_e( 'Template mapping is in place. For memberships, tickets, and gift cards, create a real record then click Create pass on that integration.', 'epasscard' ); ?></p>
			<?php if ( $module_slug ) : ?>
				<p><a class="button epc-wizard__btn" href="<?php echo esc_url( admin_url( 'admin.php?page=epc-' . $module_slug ) ); ?>"><?php esc_html_e( 'Open this integration', 'epasscard' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>

		<ul class="epc-wizard__wallets" aria-label="<?php esc_attr_e( 'Passes work with these wallets', 'epasscard' ); ?>">
			<li class="epc-wizard__wallet">
				<img
					class="epc-wizard__wallet-icon"
					src="<?php echo esc_url( EPC_PLUGIN_URL . 'admin/images/apple-wallet-icon.png' ); ?>"
					alt=""
					width="40"
					height="40"
					decoding="async"
				/>
				<span class="epc-wizard__wallet-label"><?php esc_html_e( 'Apple Wallet', 'epasscard' ); ?></span>
			</li>
			<li class="epc-wizard__wallet">
				<img
					class="epc-wizard__wallet-icon"
					src="<?php echo esc_url( EPC_PLUGIN_URL . 'admin/images/google-wallet.webp' ); ?>"
					alt=""
					width="40"
					height="40"
					decoding="async"
				/>
				<span class="epc-wizard__wallet-label"><?php esc_html_e( 'Google Wallet', 'epasscard' ); ?></span>
			</li>
			<li class="epc-wizard__wallet">
				<img
					class="epc-wizard__wallet-icon epc-wizard__wallet-icon--epass"
					src="<?php echo esc_url( EPC_PLUGIN_URL . 'admin/images/epass-card-wallet-icon.png' ); ?>"
					alt=""
					width="40"
					height="40"
					decoding="async"
				/>
				<span class="epc-wizard__wallet-label"><?php esc_html_e( 'Epass Wallet', 'epasscard' ); ?></span>
			</li>
		</ul>

		<div class="epc-wizard__result" id="epc-wizard-result" <?php echo $pass_link ? '' : 'hidden'; ?>>
			<p class="epc-wizard__result-label"><?php esc_html_e( 'Wallet link', 'epasscard' ); ?></p>
			<p>
				<a class="button button-primary epc-wizard__btn" id="epc-wizard-pass-link" href="<?php echo esc_url( $pass_link ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Open pass', 'epasscard' ); ?>
				</a>
			</p>
			<p class="epc-wizard__hint"><?php esc_html_e( 'On iPhone use Add to Apple Wallet. On Android use Add to Google Wallet or Epass Wallet.', 'epasscard' ); ?></p>
		</div>

		<p class="epc-wizard__actions">
			<button type="button" class="button" data-epc-wizard-back><?php esc_html_e( 'Back', 'epasscard' ); ?></button>
			<button type="button" class="button button-primary epc-wizard__btn" data-epc-wizard-complete><?php esc_html_e( 'Finish setup', 'epasscard' ); ?></button>
		</p>
	</div>
</section>

<section class="epc-wizard__panel epc-wizard__panel--celebrate" data-panel="done" <?php echo 'done' === $step ? '' : 'hidden'; ?>>
	<div class="epc-wizard__celebrate" aria-live="polite">
		<div class="epc-wizard__fireworks" aria-hidden="true">
			<span class="epc-wizard__burst epc-wizard__burst--1"></span>
			<span class="epc-wizard__burst epc-wizard__burst--2"></span>
			<span class="epc-wizard__burst epc-wizard__burst--3"></span>
			<span class="epc-wizard__spark"></span>
			<span class="epc-wizard__spark"></span>
			<span class="epc-wizard__spark"></span>
			<span class="epc-wizard__spark"></span>
			<span class="epc-wizard__spark"></span>
			<span class="epc-wizard__spark"></span>
			<span class="epc-wizard__spark"></span>
			<span class="epc-wizard__spark"></span>
			<span class="epc-wizard__spark"></span>
			<span class="epc-wizard__spark"></span>
			<span class="epc-wizard__spark"></span>
			<span class="epc-wizard__spark"></span>
		</div>
		<span class="epc-wizard__result-icon epc-wizard__result-icon--success" aria-hidden="true"><span class="epc-icon">celebration</span></span>
		<p class="epc-wizard__eyebrow"><?php esc_html_e( 'All set', 'epasscard' ); ?></p>
		<h2 class="epc-wizard__title epc-wizard__title--celebrate"><?php esc_html_e( 'Congratulations!', 'epasscard' ); ?></h2>
		<p class="epc-wizard__lead"><?php esc_html_e( 'Your EpassCard setup is complete. You can manage passes, mappings, and notifications from the dashboard anytime.', 'epasscard' ); ?></p>
		<p class="epc-wizard__actions">
			<a class="button button-primary epc-wizard__btn" href="<?php echo esc_url( admin_url( 'admin.php?page=epasscard' ) ); ?>">
				<?php esc_html_e( 'Go to dashboard', 'epasscard' ); ?>
			</a>
		</p>
	</div>
</section>
