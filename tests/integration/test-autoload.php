<?php
/**
 * Integration test: the class autoloader map matches the code (every EpassCard class is
 * mapped to the file that declares it), so loading classes on first use never misses one.
 *
 * Run: wp eval-file tests/integration/test-autoload.php (tests/integration/run.sh does this).
 *
 * @package EpassCard
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'EPC_Autoloader' ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/test-autoload.php (EpassCard active)\n" );
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

// Every class declared under includes/ and in modules/abstract-*.php (module-*.php files are
// loaded by EPC_Module_Loader).
$files = array();
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( EPC_PLUGIN_DIR . 'includes', FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $file ) {
	if ( '.php' === substr( (string) $file, -4 ) ) {
		$files[] = (string) $file;
	}
}
$files = array_merge( $files, (array) glob( EPC_PLUGIN_DIR . 'modules/abstract-*.php' ) );

$declared = array();
foreach ( $files as $file ) {
	$tokens = token_get_all( (string) file_get_contents( $file ) );
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
			$prev = $i - 1;
			while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
				--$prev;
			}
			if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_DOUBLE_COLON === $tokens[ $prev ][0] ) {
				continue; // ::class.
			}
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$declared[ $tokens[ $j ][1] ] = str_replace( EPC_PLUGIN_DIR, '', $file );
					break;
				}
				if ( '(' === $tokens[ $j ] || '{' === $tokens[ $j ] ) {
					break; // Anonymous class.
				}
			}
		}
	}
}
unset( $declared['EPC_Plugin'], $declared['EPC_Autoloader'] ); // Loaded up front by epasscard.php.

$map     = EPC_Autoloader::CLASSES;
$missing = array_diff_key( $declared, $map );
$wrong   = array();
foreach ( $declared as $class => $file ) {
	if ( isset( $map[ $class ] ) && $map[ $class ] !== $file ) {
		$wrong[] = $class . ' in ' . $file . ', mapped to ' . $map[ $class ];
	}
}
$stale = array_diff_key( $map, $declared );

$check( 'every EpassCard class is in the autoloader map', empty( $missing ), implode( ', ', array_keys( $missing ) ) );
$check( 'each one points at the file that declares it', empty( $wrong ), implode( '; ', $wrong ) );
$check( 'the map lists no class that no longer exists', empty( $stale ), implode( ', ', array_keys( $stale ) ) );

$not_loadable = array();
foreach ( array_keys( $map ) as $class ) {
	if ( ! class_exists( $class ) && ! interface_exists( $class ) && ! trait_exists( $class ) ) {
		$not_loadable[] = $class;
	}
}
$check( 'every mapped class loads', empty( $not_loadable ), implode( ', ', $not_loadable ) );

// The list table needs WordPress's admin list table class, so it lives in its own file and the
// integration base class no longer loads that admin class on every request.
$base = (string) file_get_contents( EPC_PLUGIN_DIR . 'includes/abstract-class-epc-module.php' );
$check( 'the integration base class does not load WP_List_Table', false === strpos( $base, 'class-wp-list-table.php' ) );

echo $passed, ' passed, ', $failed, " failed\n";
if ( $failed > 0 ) {
	exit( 1 );
}
