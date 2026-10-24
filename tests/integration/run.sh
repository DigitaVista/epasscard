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

echo "== Loyalty past-order sync: a cancel from another request stops it"
$WP eval-file "$HERE/test-loyalty-sync-cancel.php" || status=1

echo "== Loyalty widget in the Cart and Checkout blocks"
$WP eval-file "$HERE/test-loyalty-blocks.php" || status=1

echo "== Loyalty: a customer without points gets the card from My Account"
$WP eval-file "$HERE/test-loyalty-get-card.php" || status=1

echo "== Gift cards: card email to the gift recipient"
$WP eval-file "$HERE/test-gift-recipient.php" || status=1

echo "== WooCommerce products with a wallet card (tickets, vouchers, cards)"
$WP eval-file "$HERE/test-product-cards.php" || status=1

echo "== Ultimate Member member cards"
$WP eval-file "$HERE/test-ultimate-member.php" || status=1

echo "== Connecting sends reference = wp-plugin"
$WP eval-file "$HERE/test-connect-reference.php" || status=1

echo "== WPLoyalty loyalty cards"
$WP eval-file "$HERE/test-wployalty.php" || status=1

echo "== WPForms form cards"
$WP eval-file "$HERE/test-wpforms.php" || status=1

echo "== Amelia appointment and event cards"
$WP eval-file "$HERE/test-amelia.php" || status=1

echo "== Tickera event tickets"
$WP eval-file "$HERE/test-tickera.php" || status=1

echo "== Account pages: Add to wallet (Paid Memberships Pro, Simple Membership)"
$WP eval-file "$HERE/test-account-links.php" || status=1

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

echo "== Home checklist and Try it"
$WP eval-file "$HERE/test-onboarding.php" || status=1

echo "== Review invitation"
$WP eval-file "$HERE/test-review-prompt.php" || status=1

echo "== Setup: edit the rules before confirming"
$WP eval-file "$HERE/test-setup-rules.php" || status=1

echo "== Default design and Design: Applies to"
$WP eval-file "$HERE/test-default-design.php" || status=1

echo "== Cards list"
$WP eval-file "$HERE/test-cards-list.php" || status=1

echo "== Give cards to existing customers"
$WP eval-file "$HERE/test-existing-customers.php" || status=1

echo "== Give cards to existing customers: three runners at once handle and email each record once"
slug="epc-existing-$RANDOM"
log="$(mktemp)"
EPC_TEST_MODULE=$slug EPC_EXISTING_STEP=setup $WP eval-file "$HERE/test-existing-customers-race.php" || status=1
at=$(( $(date +%s) + 3 ))
pids=""
for i in 1 2 3; do
	EPC_TEST_MODULE=$slug EPC_EXISTING_STEP=work EPC_RACE_AT=$at EPC_RACE_LOG="$log" EPC_FAKE_DELAY_MS=200 $WP eval-file "$HERE/test-existing-customers-race.php" &
	pids="$pids $!"
done
for p in $pids; do wait "$p" || status=1; done
got=$(EPC_TEST_MODULE=$slug EPC_EXISTING_STEP=check $WP eval-file "$HERE/test-existing-customers-race.php")
mails=$(sort "$log" | uniq | wc -l | tr -d ' ')
total=$(wc -l < "$log" | tr -d ' ')
EPC_TEST_MODULE=$slug EPC_EXISTING_STEP=teardown $WP eval-file "$HERE/test-existing-customers-race.php"
rm -f "$log"
if [ "$got" = "10 10 10 10 done" ] && [ "$mails" = "10" ] && [ "$total" = "10" ]; then echo "PASS 3 parallel runners: 10 cards, 10 emails to 10 customers"; else echo "FAIL parallel runners: expected '10 10 10 10 done' and 10 emails to 10 customers, got '$got', $total emails to $mails customers"; status=1; fi

echo "== Card API (PHP and REST)"
$WP eval-file "$HERE/test-developer-api.php" || status=1

echo "== Plan forecast (Settings, Account & plan)"
$WP eval-file "$HERE/test-plan-forecast.php" || status=1

echo "== Help panels and Tutorials"
$WP eval-file "$HERE/test-help.php" || status=1

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
