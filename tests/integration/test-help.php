<?php
/**
 * Integration test for v40: "How this works" panels and the Tutorials page.
 *
 * Run: wp eval-file tests/integration/test-help.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Help' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-help.php (EpassCard active)\n" );
	exit( 1 );
}

/**
 * Pass/fail counter (a class: wp eval-file runs this file inside a function).
 */
final class EPC_Help_Test_Counter {
	/**
	 * Checks passed.
	 *
	 * @var int
	 */
	public static $passed = 0;

	/**
	 * Checks failed.
	 *
	 * @var int
	 */
	public static $failed = 0;
}

/**
 * Record one check.
 *
 * @param string $label Check label.
 * @param bool   $ok    Result.
 * @param string $info  Extra detail on failure.
 * @return void
 */
function epc_hp_check( $label, $ok, $info = '' ) {
	if ( $ok ) {
		++EPC_Help_Test_Counter::$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++EPC_Help_Test_Counter::$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
}

/**
 * A panel's HTML.
 *
 * @param string               $topic Topic.
 * @param array<string, mixed> $args  Args.
 * @return string
 */
function epc_hp_panel( $topic, array $args = array() ) {
	ob_start();
	EPC_Help::render( $topic, $args );
	return (string) ob_get_clean();
}

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
$admin  = (int) $admins[0];
wp_set_current_user( $admin );
$saved_state = get_user_meta( $admin, EPC_Help::META, true );
delete_user_meta( $admin, EPC_Help::META );

// The fourteen topics of the spec, in setup order, each complete.
$topics = EPC_Help::topics();
epc_hp_check( 'fourteen topics in setup order', array( 'home', 'connect', 'job', 'design', 'try', 'live', 'cards', 'design-change', 'customers', 'account', 'emails', 'push', 'integrations', 'advanced' ) === array_keys( $topics ), implode( ',', array_keys( $topics ) ) );
$incomplete = array();
foreach ( $topics as $id => $topic ) {
	foreach ( array( 'label', 'title', 'why', 'what', 'how' ) as $key ) {
		if ( '' === trim( (string) $topic[ $key ] ) ) {
			$incomplete[] = "$id.$key";
		}
	}
	if ( 0 !== strpos( $topic['doc'], 'https://www.epasscard.com/' ) ) {
		$incomplete[] = "$id.doc";
	}
	if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{11}$/', (string) $topic['video'] ) ) {
		$incomplete[] = "$id.video";
	}
}
epc_hp_check( 'every topic has its lines, an EpassCard guide link and a tutorial video', array() === $incomplete, implode( ',', $incomplete ) );
$missing = array_diff( array_keys( EPC_Onboarding::get_steps() ), array_keys( $topics ) );
epc_hp_check( 'every Home step has a topic', array() === $missing, implode( ',', $missing ) );

// Open the first time; "Hide" folds it for this user only; a failure opens it again.
$html = epc_hp_panel( 'emails' );
epc_hp_check( 'open the first time', false !== strpos( $html, 'class="epc-help__panel"' ) && false === strpos( $html, 'class="epc-help__panel" aria-labelledby="epc-help-emails-title" hidden' ) && false !== strpos( $html, 'class="epc-help__toggle" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden' ) );
epc_hp_check( 'the three lines and the guide link', false !== strpos( $html, esc_html( $topics['emails']['why'] ) ) && false !== strpos( $html, 'utm_source=wp-plugin' ) && false !== strpos( $html, '#email-settings' ) );
epc_hp_check( 'no video player or YouTube address in the page', false === strpos( $html, 'youtube' ) && false === strpos( $html, '<iframe' ) );
epc_hp_check( 'hide is saved', EPC_Help::update( $admin, 'emails', 'hide' ) && array( 'emails' => 1 ) === EPC_Help::state( $admin )['hidden'] );
$html = epc_hp_panel( 'emails' );
epc_hp_check( 'hidden: only the button shows', false !== strpos( $html, 'aria-labelledby="epc-help-emails-title" hidden' ) && false === strpos( $html, 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" hidden' ) );
epc_hp_check( 'hidden but the section shows a failure: open', false === strpos( epc_hp_panel( 'emails', array( 'failure' => true ) ), 'aria-labelledby="epc-help-emails-title" hidden' ) );
epc_hp_check( 'other topics stay open', false === strpos( epc_hp_panel( 'push' ), 'aria-labelledby="epc-help-push-title" hidden' ) );
$other_users = get_users(
	array(
		'role'    => 'administrator',
		'exclude' => array( $admin ),
		'number'  => 1,
		'fields'  => 'ID',
	)
);
if ( $other_users ) {
	epc_hp_check( 'remembered per user', array() === EPC_Help::state( (int) $other_users[0] )['hidden'] );
}
epc_hp_check( 'show is saved', EPC_Help::update( $admin, 'emails', 'show' ) && array() === EPC_Help::state( $admin )['hidden'] );
epc_hp_check( 'unknown topic or change is refused', ! EPC_Help::update( $admin, 'nope', 'hide' ) && ! EPC_Help::update( $admin, 'emails', 'delete' ) );
epc_hp_check( 'unknown topic renders nothing', '' === epc_hp_panel( 'nope' ) );

// A recorded video: a poster button only; the player is made in the browser after a click.
$video = static function ( $t ) {
	$t['cards']['video']  = 'abcDEF12345';
	$t['cards']['length'] = '1:05';
	$t['emails']['video'] = 'https://evil.example/x';
	return $t;
};
add_filter( 'epc_help_topics', $video );
$html = epc_hp_panel( 'cards' );
epc_hp_check( 'video: poster button with the id, no iframe', false !== strpos( $html, 'data-epc-help-play="abcDEF12345"' ) && false === strpos( $html, '<iframe' ) && false !== strpos( $html, 'Play: Reading the Cards list (1:05)' ) );
epc_hp_check( 'a video value that is not a YouTube id is dropped', '' === EPC_Help::topic( 'emails' )['video'] );
EPC_Help::update( $admin, 'cards', 'watched' );
$with_video = count( array_filter( wp_list_pluck( EPC_Help::topics(), 'video' ) ) );
ob_start();
EPC_Help::render_page();
$page = (string) ob_get_clean();
epc_hp_check( 'Tutorials page: watched count and tick', false !== strpos( $page, '1 of ' . $with_video . ' videos watched.' ) && 1 === substr_count( $page, 'epc-badge--ok' ), (string) $with_video );
remove_filter( 'epc_help_topics', $video );

ob_start();
EPC_Help::render_page();
$page   = (string) ob_get_clean();
$titles = 0;
foreach ( EPC_Help::topics() as $topic ) {
	$titles += false !== strpos( $page, esc_html( $topic['title'] ) ) ? 1 : 0;
}
$no_video = count( EPC_Help::topics() ) - count( array_filter( wp_list_pluck( EPC_Help::topics(), 'video' ) ) );
epc_hp_check( 'Tutorials page lists all fourteen; "coming soon" only without a video', 14 === $titles && $no_video === substr_count( $page, 'Video coming soon' ), $titles . ' titles' );
epc_hp_check( 'Tutorials page: two groups in setup order', strpos( $page, 'Getting started' ) < strpos( $page, 'Running it day to day' ) && strpos( $page, esc_html( $topics['live']['title'] ) ) < strpos( $page, esc_html( $topics['cards']['title'] ) ) );

// Clean up.
if ( '' === $saved_state || false === $saved_state ) {
	delete_user_meta( $admin, EPC_Help::META );
} else {
	update_user_meta( $admin, EPC_Help::META, $saved_state );
}

echo EPC_Help_Test_Counter::$passed, ' passed, ', EPC_Help_Test_Counter::$failed, " failed\n";
if ( EPC_Help_Test_Counter::$failed > 0 ) {
	exit( 1 );
}
