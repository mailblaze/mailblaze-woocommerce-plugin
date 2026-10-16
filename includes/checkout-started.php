<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * A checkbox value counts as ticked only for an explicit grant.
 *
 * @param mixed $value
 * @return bool
 */
function mailblaze_wc_checkout_optin_ticked( $value ) {
    if ( $value === true || $value === 1 ) {
        return true;
    }
    $value = strtolower( trim( (string) $value ) );
    return in_array( $value, array( '1', 'yes', 'true', 'on' ), true );
}

/**
 * Posted or saved checkout checkbox. Classic order review always includes the
 * form, so a missing checkbox is unticked. A page load uses the saved value.
 *
 * @param array $input source, field_present, field_value, saved
 * @return array{value:string,persist:?string}
 */
function mailblaze_wc_checkout_optin_signal( $input ) {
    $input = is_array( $input ) ? $input : array();
    $source = isset( $input['source'] ) ? (string) $input['source'] : '';
    $present = ! empty( $input['field_present'] );
    $saved = isset( $input['saved'] ) ? $input['saved'] : '';
    if ( $source === 'order_review' || $present ) {
        $ticked = $present && mailblaze_wc_checkout_optin_ticked( isset( $input['field_value'] ) ? $input['field_value'] : '' );
        return array(
            'value' => $ticked ? 'yes' : 'no',
            'persist' => $ticked ? 'yes' : 'no',
        );
    }
    if ( mailblaze_wc_checkout_optin_ticked( $saved ) ) {
        return array(
            'value' => 'yes',
            'persist' => null,
        );
    }
    return array(
        'value' => '',
        'persist' => null,
    );
}

/**
 * wp_remote_request args for checkout.started. Orders keep send_event.
 *
 * @return array
 */
function mailblaze_wc_checkout_started_http_args() {
    return array(
        'blocking' => false,
        'timeout' => 2,
    );
}

/**
 * Whether a checkout.started event may be recorded.
 *
 * The WP Consent API is the consent signal when a banner plugin has registered
 * a type. No registered type means consent is unknown, and unknown consent
 * records nothing. A known grant is marketing consent true.
 *
 * Without the Consent API, record only an explicit grant: a logged-in user
 * whose mailblaze_optin is yes, or a posted or saved mailblaze_checkout_optin
 * that is ticked. Guests without that, an empty opt-in, and the opt-in option
 * turned off record nothing.
 *
 * @param array $signals consent_api, consent_type, marketing_consent, logged_in, mailblaze_optin, mailblaze_checkout_optin, optin_enabled
 * @return bool
 */
function mailblaze_wc_checkout_consent_allowed( $signals ) {
    $signals = is_array( $signals ) ? $signals : array();
    if ( ! empty( $signals['consent_api'] ) ) {
        $type = isset( $signals['consent_type'] ) ? $signals['consent_type'] : false;
        if ( $type !== 'optin' && $type !== 'optout' ) {
            return false;
        }
        return isset( $signals['marketing_consent'] ) && $signals['marketing_consent'] === true;
    }

    if ( empty( $signals['optin_enabled'] ) ) {
        return false;
    }
    $optin = isset( $signals['mailblaze_optin'] ) ? (string) $signals['mailblaze_optin'] : '';
    if ( ! empty( $signals['logged_in'] ) && $optin === 'yes' ) {
        return true;
    }
    return mailblaze_wc_checkout_optin_ticked( isset( $signals['mailblaze_checkout_optin'] ) ? $signals['mailblaze_checkout_optin'] : '' );
}

/**
 * Read the consent signals available on this request.
 *
 * @return array
 */
function mailblaze_wc_read_checkout_consent_signals() {
    $signals = array(
        'consent_api' => function_exists( 'wp_get_consent_type' ) && function_exists( 'wp_has_consent' ),
        'consent_type' => null,
        'marketing_consent' => null,
        'logged_in' => function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
        'mailblaze_optin' => '',
        'mailblaze_checkout_optin' => '',
        'optin_enabled' => function_exists( 'get_option' ) && get_option( 'mailblaze_wc_enable_optin', '0' ) === '1',
    );
    if ( $signals['consent_api'] ) {
        $signals['consent_type'] = wp_get_consent_type();
        $signals['marketing_consent'] = wp_has_consent( 'marketing' );
    }
    if ( $signals['logged_in'] && function_exists( 'get_user_meta' ) ) {
        $signals['mailblaze_optin'] = (string) get_user_meta( get_current_user_id(), 'mailblaze_optin', true );
    }
    return $signals;
}

/**
 * Normalise checkout line rows. Variation lines keep the parent product id.
 *
 * @param array $rows
 * @return array
 */
function mailblaze_wc_checkout_line_items_from_rows( $rows ) {
    $detailed = array();
    $strings = array();
    if ( ! is_array( $rows ) ) {
        $rows = array();
    }
    foreach ( $rows as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $name = isset( $row['name'] ) ? (string) $row['name'] : '';
        if ( $name === '' ) {
            continue;
        }
        $qty = isset( $row['quantity'] ) ? (int) $row['quantity'] : 0;
        if ( $qty < 1 ) {
            $qty = 1;
        }
        $total = isset( $row['total'] ) ? (float) $row['total'] : 0;
        $price = array_key_exists( 'price', $row ) ? (float) $row['price'] : ( $qty > 0 ? $total / $qty : 0 );
        $parent_id = isset( $row['product_id'] ) ? (int) $row['product_id'] : 0;
        $variation_id = isset( $row['variation_id'] ) ? (int) $row['variation_id'] : 0;
        $parent_name = isset( $row['parent_name'] ) ? (string) $row['parent_name'] : '';
        if ( function_exists( 'mailblaze_wc_line_identity' ) ) {
            $identity = mailblaze_wc_line_identity( $parent_id, $variation_id, $name, $parent_name );
        } else {
            $identity = array(
                'product_id' => $parent_id,
                'variation_id' => $variation_id,
                'name' => $name,
                'parent_name' => $parent_name !== '' ? $parent_name : $name,
            );
        }
        $item = array(
            'product_id' => $identity['product_id'],
            'name' => $identity['name'],
            'parent_name' => $identity['parent_name'],
            'quantity' => $qty,
            'price' => $price,
            'total' => $total,
            'sku' => isset( $row['sku'] ) ? (string) $row['sku'] : '',
            'variation_id' => $identity['variation_id'],
        );
        if ( ! empty( $row['image'] ) ) {
            $item['image'] = (string) $row['image'];
        }
        $detailed[] = $item;
        $strings[] = $identity['name'] . ' x ' . $qty;
    }
    return array(
        'items_string' => implode( ', ', $strings ),
        'items_detailed' => $detailed,
    );
}

/**
 * Inner data payload for commerce checkout_started. Null when the checkout
 * cannot be stored (no id, no email, or no lines).
 *
 * @param array $input
 * @return array|null
 */
function mailblaze_wc_checkout_started_payload( $input ) {
    $input = is_array( $input ) ? $input : array();
    $email = isset( $input['email'] ) ? trim( (string) $input['email'] ) : '';
    $checkout_id = isset( $input['checkout_id'] ) ? trim( (string) $input['checkout_id'] ) : '';
    $items = mailblaze_wc_checkout_line_items_from_rows( isset( $input['items'] ) ? $input['items'] : array() );
    if ( $email === '' || $checkout_id === '' || empty( $items['items_detailed'] ) ) {
        return null;
    }
    return array(
        'FIELD_CHECKOUT_ID' => $checkout_id,
        'FIELD_CART_ID' => isset( $input['cart_id'] ) ? (string) $input['cart_id'] : '',
        'FIELD_CUSTOMER_EMAIL' => $email,
        'FIELD_CART_ITEMS' => $items,
        'FIELD_CART_TOTAL' => isset( $input['total'] ) ? $input['total'] : 0,
        'FIELD_CURRENCY' => isset( $input['currency'] ) ? (string) $input['currency'] : '',
        'FIELD_CREATED' => isset( $input['created'] ) ? (string) $input['created'] : '',
        'list_uid' => isset( $input['list_uid'] ) ? (string) $input['list_uid'] : '',
        'store_id' => isset( $input['store_id'] ) ? (string) $input['store_id'] : '',
        'FIELD_CHECKOUT_SOURCE' => isset( $input['source'] ) ? (string) $input['source'] : '',
    );
}
