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

mailblaze_assert_true(mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => false,
    'mailblaze_optin' => '',
)), 'no consent API records checkout events like the other commerce hooks');

mailblaze_assert_same(false, mailblaze_wc_checkout_consent_allowed(array(
    'consent_api' => false,
    'mailblaze_optin' => 'no',
)), 'explicit Mail Blaze opt-out is a known denial');

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
