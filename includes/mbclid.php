<?php
/**
 * Mail Blaze conversion click ids.
 *
 * Shared with mailblaze-tracking and mailblaze-commerce:
 *   v1.{campaignUid}.{subscriberUid}.{urlHash}.{unix}.{hmac16}
 *
 * Format is checked here. When MBCLID_SECRET (or MAILBLAZE_MBCLID_SECRET)
 * is set, the HMAC is checked the same way as commerce verifyMbclid.
 * Commerce still applies the 30-day window on the order.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @param mixed $raw
 * @return string|null Token unchanged, or null when it is not a v1 mbclid.
 */
function mailblaze_wc_normalize_mbclid($raw) {
    if (!is_string($raw)) {
        return null;
    }

    $token = trim($raw);
    $pattern = '/^v1\.([A-Za-z0-9]+)\.([A-Za-z0-9]+)\.([A-Za-z0-9]*)\.([0-9]+)\.([0-9a-f]{16})$/';
    if (!preg_match($pattern, $token)) {
        return null;
    }

    return $token;
}

/**
 * @return string Empty when this store has no copy of the signing secret.
 */
function mailblaze_wc_mbclid_secret() {
    if (defined('MAILBLAZE_MBCLID_SECRET') && is_string(MAILBLAZE_MBCLID_SECRET)) {
        return trim(MAILBLAZE_MBCLID_SECRET);
    }
    $env = getenv('MBCLID_SECRET');
    if (is_string($env)) {
        return trim($env);
    }
    return '';
}

/**
 * @param string $token Already normalized.
 * @param string $secret
 * @return bool
 */
function mailblaze_wc_mbclid_signature_valid($token, $secret) {
    $token = mailblaze_wc_normalize_mbclid($token);
    $secret = is_string($secret) ? trim($secret) : '';
    if ($token === null || $secret === '') {
        return false;
    }

    $parts = explode('.', $token);
    $message = implode('|', array('v1', $parts[1], $parts[2], $parts[3], $parts[4]));
    $expected = substr(hash_hmac('sha256', $message, $secret), 0, 16);
    if (strlen($expected) !== strlen($parts[5])) {
        return false;
    }
    return hash_equals($expected, $parts[5]);
}

/**
 * A token this plugin will keep. Format is required. HMAC is required when a secret is configured.
 *
 * @param mixed $raw
 * @param string $secret
 * @return string|null
 */
function mailblaze_wc_acceptable_mbclid($raw, $secret) {
    $token = mailblaze_wc_normalize_mbclid($raw);
    if ($token === null) {
        return null;
    }
    $secret = is_string($secret) ? trim($secret) : '';
    if ($secret !== '' && !mailblaze_wc_mbclid_signature_valid($token, $secret)) {
        return null;
    }
    return $token;
}

/**
 * Query-string capture. An invalid format or signature does not replace a valid stored token.
 * A new acceptable click does replace it.
 *
 * @param mixed $incoming_raw
 * @param mixed $stored_raw
 * @param string $secret
 * @return string|null Token the session should hold, or null when neither value is acceptable.
 */
function mailblaze_wc_resolve_captured_mbclid($incoming_raw, $stored_raw, $secret) {
    $incoming = mailblaze_wc_acceptable_mbclid($incoming_raw, $secret);
    if ($incoming !== null) {
        return $incoming;
    }
    return mailblaze_wc_acceptable_mbclid($stored_raw, $secret);
}

/**
 * Click id for a status event on an order that already exists.
 * Order meta is the attribution for that order. The session token is ignored.
 *
 * @param mixed $order_meta
 * @param mixed $session_token
 * @return string|null
 */
function mailblaze_wc_mbclid_for_existing_order($order_meta, $session_token) {
    unset($session_token);
    return mailblaze_wc_normalize_mbclid($order_meta);
}

/**
 * Copy the session click id onto an order that does not have one yet.
 *
 * @param mixed $existing_meta
 * @param mixed $session_token
 * @param string $secret
 * @return array{meta: ?string, clear_session: bool}
 */
function mailblaze_wc_attach_session_mbclid($existing_meta, $session_token, $secret = '') {
    $meta = mailblaze_wc_acceptable_mbclid($existing_meta, $secret);
    if ($meta !== null) {
        return array(
            'meta' => $meta,
            'clear_session' => false,
        );
    }

    $session = mailblaze_wc_acceptable_mbclid($session_token, $secret);
    if ($session === null) {
        return array(
            'meta' => null,
            'clear_session' => false,
        );
    }

    return array(
        'meta' => $session,
        'clear_session' => true,
    );
}

/**
 * @param array $data
 * @param mixed $mbclid
 * @return array
 */
function mailblaze_wc_with_mbclid_field($data, $mbclid) {
    unset($data['FIELD_MBCLID']);
    $token = mailblaze_wc_normalize_mbclid($mbclid);
    if ($token !== null) {
        $data['FIELD_MBCLID'] = $token;
    }
    return $data;
}

/**
 * Hand a newly stored click id to the visitor module. No-op until that class is loaded.
 *
 * @param string|null $token
 */
function mailblaze_wc_link_captured_click($token) {
    if (!is_string($token) || $token === '' || !class_exists('MailBlaze_Visitor', false)) {
        return;
    }
    MailBlaze_Visitor::link_click($token);
}

/**
 * Hand the checkout click id to the visitor module while the session still holds it.
 *
 * @param object|null $order
 * @param string|null $token
 */
function mailblaze_wc_link_checkout_click($order, $token) {
    if (!is_string($token) || $token === '' || !class_exists('MailBlaze_Visitor', false)) {
        return;
    }
    $email = '';
    if (is_object($order) && method_exists($order, 'get_billing_email')) {
        $billing = $order->get_billing_email();
        $email = is_string($billing) ? $billing : '';
    }
    MailBlaze_Visitor::link_email($email, 'checkout', $token);
}

/**
 * True while checkout is still inside the order-processed action, before priority 20.
 *
 * @return bool
 */
function mailblaze_wc_mbclid_visitor_link_pending() {
    if (!function_exists('doing_action')) {
        return false;
    }
    return doing_action('woocommerce_checkout_order_processed')
        || doing_action('woocommerce_store_api_checkout_order_processed');
}

/**
 * Drop the session click id after the priority 20 checkout visitor link has run.
 * Does nothing unless this request attached a token and asked for the clear.
 */
function mailblaze_wc_clear_mbclid_session_after_visitor() {
    if (empty($GLOBALS['mailblaze_wc_clear_mbclid_after_visitor'])) {
        return;
    }
    unset($GLOBALS['mailblaze_wc_clear_mbclid_after_visitor']);
    if (!function_exists('WC')) {
        return;
    }
    $wc = WC();
    if (!is_object($wc) || !isset($wc->session) || !is_object($wc->session) || !method_exists($wc->session, 'set')) {
        return;
    }
    $wc->session->set('mailblaze_mbclid', null);
}

/**
 * Write _mailblaze_mbclid during checkout (and thank-you, if checkout did not).
 * When this call attaches the token, the visitor module receives it before the
 * session is cleared. During checkout the clear waits until priority 30 so the
 * priority 20 visitor link still sees the click id.
 *
 * @param object|null $order
 */
function mailblaze_wc_persist_order_mbclid($order) {
    if (!is_object($order) || !method_exists($order, 'get_meta') || !method_exists($order, 'update_meta_data')) {
        return;
    }

    $session_token = null;
    if (function_exists('WC')) {
        $wc = WC();
        if (is_object($wc) && isset($wc->session) && is_object($wc->session) && method_exists($wc->session, 'get')) {
            $session_token = $wc->session->get('mailblaze_mbclid');
        }
    }

    $decision = mailblaze_wc_attach_session_mbclid(
        $order->get_meta('_mailblaze_mbclid'),
        $session_token,
        mailblaze_wc_mbclid_secret()
    );
    $existing = mailblaze_wc_normalize_mbclid($order->get_meta('_mailblaze_mbclid'));
    if ($decision['meta'] !== null && $decision['meta'] !== $existing) {
        $order->update_meta_data('_mailblaze_mbclid', $decision['meta']);
        if (method_exists($order, 'save')) {
            $order->save();
        }
    }
    if (empty($decision['clear_session'])) {
        return;
    }

    mailblaze_wc_link_checkout_click($order, $decision['meta']);
    $GLOBALS['mailblaze_wc_clear_mbclid_after_visitor'] = true;
    if (!mailblaze_wc_mbclid_visitor_link_pending()) {
        mailblaze_wc_clear_mbclid_session_after_visitor();
    }
}
