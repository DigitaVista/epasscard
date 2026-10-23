<?php
/**
 * PHPStan bootstrap: constants that WordPress and EpassCard define at runtime.
 * Values are copied from WordPress 6.5 (wp-includes) and epasscard.php.
 *
 * @package EpassCard
 */

define( 'ABSPATH', '/' );
define( 'WP_CONTENT_DIR', '/wp-content' );
define( 'WP_PLUGIN_DIR', '/wp-content/plugins' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
define( 'MONTH_IN_SECONDS', 30 * DAY_IN_SECONDS );
define( 'YEAR_IN_SECONDS', 365 * DAY_IN_SECONDS );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );
define( 'OBJECT_K', 'OBJECT_K' );
define( 'EP_ROOT', 64 );
define( 'EP_PAGES', 4096 );

define( 'EPC_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'EPC_PLUGIN_FILE', EPC_PLUGIN_DIR . 'epasscard.php' );
define( 'EPC_PLUGIN_URL', 'https://example.test/wp-content/plugins/epasscard/' );
define( 'EPC_PLUGIN_BASENAME', 'epasscard/epasscard.php' );
define( 'EPC_VERSION', '0.0.0' );
define( 'EPC_DB_VERSION', '0.0.0' );
