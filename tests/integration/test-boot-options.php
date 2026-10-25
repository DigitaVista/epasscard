<?php
/**
 * Integration test: the options EpassCard reads on every request cost at most one query.
 *
 * - EPC_Module_Loader loads the start-up options of the enabled modules in one query
 *   (EPC_Module::get_boot_option_keys()), and get_option() then reads them without a query.
 * - The loyalty wallet rewrite marker is autoloaded (an old, not autoloaded marker is replaced
 *   once).
 *
 * Run: wp eval-file tests/integration/test-boot-options.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Module_Loader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-boot-options.php (EpassCard active)\n" );
	exit( 1 );
}

$failed = 0;
$passed = 0;
$check  = static function ( $label, $ok, $info = '' ) use ( &$failed, &$passed ) {
	if ( $ok ) {
		++$passed;
		echo 'PASS ', $label, "\n";
		return;
	}
	++$failed;
	echo 'FAIL ', $label, ( '' !== $info ? ' -- ' . $info : '' ), "\n";
};

global $wpdb;

/**
 * Forget the cached value of options (and that they are missing), as at the start of a request.
 *
 * @param array<int, string> $keys Option names.
 * @return void
 */
$forget = static function ( array $keys ) {
	$notoptions = wp_cache_get( 'notoptions', 'options' );
	foreach ( $keys as $key ) {
		wp_cache_delete( $key, 'options' );
		if ( is_array( $notoptions ) ) {
			unset( $notoptions[ $key ] );
		}
	}
	wp_cache_set( 'notoptions', is_array( $notoptions ) ? $notoptions : array(), 'options' );
};

$slugs   = array( 'woocommerce-tickets', 'woocommerce-vouchers', 'woocommerce-product-cards', 'woocommerce-loyalty' );
$modules = array();
foreach ( $slugs as $slug ) {
	$module = EPC_Module_Loader::get_module_instance( $slug );
	if ( $module instanceof EPC_Module ) {
		$modules[ $slug ] = $module;
	}
}
$check( 'the WooCommerce modules load', 4 === count( $modules ), implode( ', ', array_keys( $modules ) ) );

$check(
	'product cards, tickets and vouchers name their status rules',
	array( 'epc_woocommerce_tickets_status_rules' ) === $modules['woocommerce-tickets']->get_boot_option_keys()
	&& array( 'epc_woocommerce_vouchers_status_rules' ) === $modules['woocommerce-vouchers']->get_boot_option_keys()
	&& array( 'epc_woocommerce_product_cards_status_rules' ) === $modules['woocommerce-product-cards']->get_boot_option_keys()
);
$check( 'loyalty names its starter pack version', array( EPC_Loyalty_Starter::OPTION_KEY ) === $modules['woocommerce-loyalty']->get_boot_option_keys() );
$others = EPC_Module_Loader::get_module_instance( 'tickera' );
$check( 'other integrations read none by default', ! $others instanceof EPC_Module || array() === $others->get_boot_option_keys() );

if ( ! function_exists( 'WC' ) ) {
	echo "SKIP query counts (WooCommerce is not active)\n";
} else {
	$keys = array();
	foreach ( $modules as $module ) {
		$keys = array_merge( $keys, $module->get_boot_option_keys() );
	}
	// One saved (not autoloaded), the others missing: both kinds must be covered.
	$saved_tickets = get_option( 'epc_woocommerce_tickets_status_rules', null );
	update_option( 'epc_woocommerce_tickets_status_rules', array( 'completed' => 'sync' ), false );
	foreach ( array( 'epc_woocommerce_vouchers_status_rules', 'epc_woocommerce_product_cards_status_rules' ) as $missing ) {
		delete_option( $missing );
	}
	$forget( $keys );

	$prime = new ReflectionMethod( 'EPC_Module_Loader', 'prime_boot_options' );
	if ( PHP_VERSION_ID < 80100 ) {
		$prime->setAccessible( true );
	}
	$before = (int) $wpdb->num_queries;
	$prime->invoke( null, $modules );
	$primed = (int) $wpdb->num_queries - $before;
	$check( 'the start-up options of all modules are read in one query', 1 === $primed, $primed . ' queries' );

	$before = (int) $wpdb->num_queries;
	$rules  = array(
		$modules['woocommerce-tickets']->get_status_rules(),
		$modules['woocommerce-vouchers']->get_status_rules(),
		$modules['woocommerce-product-cards']->get_status_rules(),
	);
	$starter = get_option( EPC_Loyalty_Starter::OPTION_KEY, 0 );
	$after   = (int) $wpdb->num_queries - $before;
	$check( 'the modules then read them without a query', 0 === $after, $after . ' queries' );
	$check(
		'with the same values as before (saved rule kept, missing rules = defaults)',
		'sync' === ( $rules[0]['completed'] ?? '' ) && 'revoke' === ( $rules[0]['refunded'] ?? '' )
		&& $modules['woocommerce-vouchers']->get_default_status_rules() === $rules[1]
		&& $modules['woocommerce-product-cards']->get_default_status_rules() === $rules[2]
		&& (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", EPC_Loyalty_Starter::OPTION_KEY ) ) === (string) $starter // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare with the stored value.
	);

	// No query when every module's options are autoloaded or already cached.
	$before = (int) $wpdb->num_queries;
	$prime->invoke( null, $modules );
	$check( 'nothing is read again once cached', (int) $wpdb->num_queries === $before );

	// No enabled module reads one: no query at all.
	$forget( $keys );
	$before = (int) $wpdb->num_queries;
	$prime->invoke( null, array() );
	$check( 'no query without modules', (int) $wpdb->num_queries === $before );

	if ( null === $saved_tickets ) {
		delete_option( 'epc_woocommerce_tickets_status_rules' );
	} else {
		update_option( 'epc_woocommerce_tickets_status_rules', $saved_tickets, false );
	}

	// Loyalty wallet rewrite marker: an old marker is replaced by an autoloaded one, once.
	$saved_marker = get_option( 'epc_loyalty_wallet_rewrite_flushed', null );
	delete_option( 'epc_loyalty_wallet_rewrite_flushed' );
	add_option( 'epc_loyalty_wallet_rewrite_flushed', '2', '', false );
	EPC_Loyalty_Frontend::maybe_flush_rewrites();
	$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'epc_loyalty_wallet_rewrite_flushed' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Check the stored autoload flag.
	$check( 'an old rewrite marker is replaced (one flush) by an autoloaded one', '3' === get_option( 'epc_loyalty_wallet_rewrite_flushed' ) && in_array( $autoload, array( 'yes', 'on', 'auto-on' ), true ), $autoload );
	$check( 'and it is in the autoloaded options', '3' === ( wp_load_alloptions()['epc_loyalty_wallet_rewrite_flushed'] ?? '' ) );
	$before = (int) $wpdb->num_queries;
	EPC_Loyalty_Frontend::maybe_flush_rewrites();
	$check( 'afterwards: no flush and no query', (int) $wpdb->num_queries === $before );
	if ( null !== $saved_marker && '2' !== $saved_marker ) {
		update_option( 'epc_loyalty_wallet_rewrite_flushed', $saved_marker, true );
	}
}

echo $passed, ' passed, ', $failed, " failed\n";
if ( $failed > 0 ) {
	exit( 1 );
}
