#!/usr/bin/env bash
# Runs the EpassCard integration tests against a WordPress install with EpassCard active.
# Usage: tests/integration/run.sh <path-to-wordpress>   (needs wp-cli as "wp" or WP_CLI env)
set -euo pipefail
WP_PATH="${1:?path to WordPress}"
WP="${WP_CLI:-wp} --path=$WP_PATH"
HERE="$(cd "$(dirname "$0")" && pwd)"
status=0

echo "== Loyalty ledger"
$WP eval-file "$HERE/test-loyalty-ledger.php" || status=1

echo "== Loyalty ledger: two processes writing to one customer at once"
user=$($WP user create "epc_conc_$RANDOM$RANDOM" "conc$RANDOM$RANDOM@example.test" --role=subscriber --porcelain)
run="c$RANDOM"
EPC_LEDGER_USER=$user EPC_LEDGER_PREFIX="${run}a" EPC_LEDGER_N=50 $WP eval-file "$HERE/test-ledger-concurrency.php" &
p1=$!
EPC_LEDGER_USER=$user EPC_LEDGER_PREFIX="${run}b" EPC_LEDGER_N=50 $WP eval-file "$HERE/test-ledger-concurrency.php" &
p2=$!
wait $p1 || status=1
wait $p2 || status=1
got=$($WP eval "\$a = EPC_Loyalty_Account_Service::get( $user ); echo (int) \$a->points_balance, ' ', (int) \$a->lifetime_points;")
if [ "$got" = "100 100" ]; then echo "PASS 2 x 50 parallel writes: balance 100, lifetime 100"; else echo "FAIL parallel writes: expected '100 100', got '$got'"; status=1; fi

exit $status
