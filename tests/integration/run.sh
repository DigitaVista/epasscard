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

echo "== Card statuses and test cards"
$WP eval-file "$HERE/test-pass-status.php" || status=1

echo "== First real card: four processes at once fire one event"
slug="epc-race-$RANDOM"
log="$(mktemp)"
EPC_TEST_MODULE=$slug $WP eval "require_once '$HERE/fixtures/test-integration.php'; epc_test_fixture_setup();"
at=$(( $(date +%s) + 3 ))
pids=""
for i in 1 2 3 4; do
	EPC_TEST_MODULE=$slug EPC_RACE_AT=$at EPC_RACE_LOG="$log" EPC_FAKE_DELAY_MS=300 $WP eval-file "$HERE/test-pass-status-race.php" &
	pids="$pids $!"
done
for p in $pids; do wait "$p" || status=1; done
cards=$(EPC_TEST_MODULE=$slug $WP eval "global \$wpdb; echo (int) \$wpdb->get_var( \$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE module = %s', EPC_DB::table_name(), '$slug' ) );")
events=$(wc -l < "$log" | tr -d ' ')
EPC_TEST_MODULE=$slug $WP eval "require_once '$HERE/fixtures/test-integration.php'; epc_test_fixture_teardown();"
rm -f "$log"
if [ "$cards" = "4" ] && [ "$events" = "1" ]; then echo "PASS 4 parallel first cards: 4 cards, 1 went-live event"; else echo "FAIL parallel first cards: expected 4 cards and 1 event, got $cards cards and $events events"; status=1; fi

echo "== Retry failed cards"
$WP eval-file "$HERE/test-retry-failed.php" || status=1

echo "== Retry failed cards: three runners at once process each record once"
slug="epc-retry-$RANDOM"
EPC_TEST_MODULE=$slug EPC_RETRY_STEP=setup $WP eval-file "$HERE/test-retry-failed-race.php" || status=1
at=$(( $(date +%s) + 3 ))
pids=""
for i in 1 2 3; do
	EPC_TEST_MODULE=$slug EPC_RETRY_STEP=work EPC_RACE_AT=$at EPC_FAKE_DELAY_MS=200 $WP eval-file "$HERE/test-retry-failed-race.php" &
	pids="$pids $!"
done
for p in $pids; do wait "$p" || status=1; done
got=$(EPC_TEST_MODULE=$slug EPC_RETRY_STEP=check $WP eval-file "$HERE/test-retry-failed-race.php")
EPC_TEST_MODULE=$slug $WP eval "require_once '$HERE/fixtures/test-integration.php'; EPC_Retry_Failed::cancel(); delete_option( EPC_Retry_Failed::OPTION ); epc_test_fixture_teardown();"
if [ "$got" = "10 10 10 done" ]; then echo "PASS 3 parallel runners: 10 cards, each record processed once"; else echo "FAIL parallel runners: expected '10 10 10 done', got '$got'"; status=1; fi

exit $status
