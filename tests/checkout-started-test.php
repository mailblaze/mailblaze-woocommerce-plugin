<?php
require dirname(__DIR__) . '/includes/order-line.php';

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname(__DIR__) . '/' );
}
require dirname(__DIR__) . '/includes/checkout-started.php';

function mailblaze_assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . " expected " . var_export($expected, true) . " got " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function mailblaze_assert_true($actual, $message) {
    if ($actual !== true) {
        fwrite(STDERR, $message . " expected true got " . var_export($actual, true) . "\n");
        exit(1);
    }
}

mailblaze_assert_same(false, mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => false,
    'optin_enabled' => true,
    'logged_in' => false,
    'mailblaze_optin' => '',
    'mailblaze_checkout_optin' => '',
)), 'a guest with an empty opt-in records nothing');

mailblaze_assert_same(false, mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => false,
    'optin_enabled' => true,
    'logged_in' => true,
    'mailblaze_optin' => '',
)), 'an empty account opt-in records nothing');

mailblaze_assert_same(false, mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => false,
    'optin_enabled' => true,
    'logged_in' => true,
    'mailblaze_optin' => 'no',
)), 'an explicit Mail Blaze opt-out records nothing');

mailblaze_assert_same(false, mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => false,
    'optin_enabled' => false,
    'logged_in' => true,
    'mailblaze_optin' => 'yes',
    'mailblaze_checkout_optin' => 'yes',
)), 'the opt-in option turned off records nothing');

mailblaze_assert_true(mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => false,
    'optin_enabled' => true,
    'logged_in' => true,
    'mailblaze_optin' => 'yes',
)), 'a logged-in user with mailblaze_optin yes is an explicit grant');

mailblaze_assert_same(false, mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => false,
    'optin_enabled' => true,
    'logged_in' => false,
    'mailblaze_optin' => 'yes',
)), 'account opt-in on a guest does not grant checkout tracking');

mailblaze_assert_true(mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => false,
    'optin_enabled' => true,
    'logged_in' => false,
    'mailblaze_checkout_optin' => '1',
)), 'a ticked checkout opt-in is an explicit grant');

mailblaze_assert_true(mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => false,
    'optin_enabled' => true,
    'mailblaze_checkout_optin' => 'yes',
)), 'a saved checkout opt-in of yes is an explicit grant');

mailblaze_assert_same(false, mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => false,
    'optin_enabled' => true,
    'mailblaze_checkout_optin' => 'no',
)), 'an unticked checkout opt-in records nothing');

$review_unticked = mailblaze_wc_checkout_optin_signal(array(
    'source' => 'order_review',
    'field_present' => false,
    'saved' => 'yes',
));
mailblaze_assert_same('no', $review_unticked['value'], 'a posted checkout form without the checkbox is unticked');
mailblaze_assert_same('no', $review_unticked['persist'], 'an unticked post replaces a saved yes');

$review_ticked = mailblaze_wc_checkout_optin_signal(array(
    'source' => 'order_review',
    'field_present' => true,
    'field_value' => '1',
));
mailblaze_assert_same('yes', $review_ticked['value'], 'a posted checkout checkbox is a grant');

$page_saved = mailblaze_wc_checkout_optin_signal(array(
    'source' => 'page_load',
    'field_present' => false,
    'saved' => 'yes',
));
mailblaze_assert_same('yes', $page_saved['value'], 'a page load uses the saved checkout opt-in');
mailblaze_assert_same(null, $page_saved['persist'], 'a page load does not rewrite the saved opt-in');

$http = mailblaze_wc_checkout_started_http_args();
mailblaze_assert_same(false, $http['blocking'], 'checkout.started does not block the checkout request');
mailblaze_assert_same(2, $http['timeout'], 'checkout.started uses a two second timeout');

mailblaze_assert_same(false, mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => true,
    'consent_type' => false,
    'marketing_consent' => null,
)), 'consent API with no registered type is unknown and records nothing');

mailblaze_assert_true(mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => true,
    'consent_type' => 'optin',
    'marketing_consent' => true,
)), 'known marketing grant records the checkout');

mailblaze_assert_same(false, mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => true,
    'consent_type' => 'optin',
    'marketing_consent' => false,
)), 'known marketing denial records nothing');

mailblaze_assert_same(false, mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => true,
    'consent_type' => 'optout',
    'marketing_consent' => null,
)), 'opt-out mode still needs a known marketing grant');

$payload = mailblaze_wc_checkout_started_payload(array(
    'checkout_id' => 'session-1',
    'cart_id' => 'cart-1',
    'email' => 'guest@example.com',
    'currency' => 'ZAR',
    'total' => '42.50',
    'created' => '2026-10-16T07:00:00+00:00',
    'list_uid' => 'list',
    'store_id' => 'store',
    'source' => 'order_review',
    'items' => array(
        array(
            'product_id' => 42,
            'variation_id' => 99,
            'name' => 'Serum - Large',
            'parent_name' => 'Serum',
            'quantity' => 1,
            'total' => 42.5,
            'sku' => 'SERUM-L',
        ),
    ),
));

mailblaze_assert_same('session-1', $payload['FIELD_CHECKOUT_ID'], 'checkout id is sent');
mailblaze_assert_same('cart-1', $payload['FIELD_CART_ID'], 'cart id is sent');
mailblaze_assert_same('guest@example.com', $payload['FIELD_CUSTOMER_EMAIL'], 'email is sent for subscriber match');
mailblaze_assert_same('42.50', $payload['FIELD_CART_TOTAL'], 'cart value is sent');
mailblaze_assert_same('ZAR', $payload['FIELD_CURRENCY'], 'currency is sent');
mailblaze_assert_same('2026-10-16T07:00:00+00:00', $payload['FIELD_CREATED'], 'created time is sent');
mailblaze_assert_same('list', $payload['list_uid'], 'list is sent');
mailblaze_assert_same('store', $payload['store_id'], 'store is sent');
mailblaze_assert_same(42, $payload['FIELD_CART_ITEMS']['items_detailed'][0]['product_id'], 'variation line uses the parent product id');
mailblaze_assert_same('Serum', $payload['FIELD_CART_ITEMS']['items_detailed'][0]['parent_name'], 'parent name is kept for matching');
mailblaze_assert_same('Serum - Large x 1', $payload['FIELD_CART_ITEMS']['items_string'], 'line summary is sent');

mailblaze_assert_same(null, mailblaze_wc_checkout_started_payload(array(
    'checkout_id' => 'session-1',
    'email' => '',
    'items' => array(array('name' => 'Serum', 'product_id' => 1, 'quantity' => 1, 'total' => 10)),
)), 'missing email is not sent');

mailblaze_assert_same(null, mailblaze_wc_checkout_started_payload(array(
    'checkout_id' => 'session-1',
    'email' => 'guest@example.com',
    'items' => array(),
)), 'empty cart is not sent');

echo "ok\n";
