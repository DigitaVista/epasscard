<?php
/**
 * Worker for the ledger concurrency test (started twice in parallel by run.sh).
 * Records EPC_LEDGER_N entries of +1 point for EPC_LEDGER_USER with unique keys.
 *
 * @package EpassCard
 */

$epc_user   = absint( getenv( 'EPC_LEDGER_USER' ) );
$epc_prefix = sanitize_key( (string) getenv( 'EPC_LEDGER_PREFIX' ) );
$epc_n      = absint( getenv( 'EPC_LEDGER_N' ) );
$epc_errors = 0;
for ( $epc_i = 0; $epc_i < $epc_n; $epc_i++ ) {
	$epc_r = EPC_Loyalty_Ledger_Service::record( $epc_user, $epc_prefix . ':' . $epc_i, 'manual_adjustment', 1, 1 );
	if ( is_wp_error( $epc_r ) ) {
		++$epc_errors;
	}
}
echo 'worker ', esc_html( $epc_prefix ), ' errors: ', (int) $epc_errors, "\n";
exit( $epc_errors > 0 ? 1 : 0 );
