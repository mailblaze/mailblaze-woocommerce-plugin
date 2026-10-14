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
 * Write _mailblaze_mbclid during checkout (and thank-you, if checkout did not).
 * Clears the session only when this call is what attached the token.
 *
 * @param object|null $order
 */
function mailblaze_wc_persist_order_mbclid($order) {
    if (!is_object($order) || !method_exists($order, 'get_meta') || !method_exists($order, 'update_meta_data')) {
        return;
    }

    $session = null;
    $session_token = null;
    if (function_exists('WC')) {
        $wc = WC();
        if (is_object($wc) && isset($wc->session) && is_object($wc->session) && method_exists($wc->session, 'get')) {
            $session = $wc->session;
            $session_token = $session->get('mailblaze_mbclid');
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
    if (!empty($decision['clear_session']) && $session && method_exists($session, 'set')) {
        $session->set('mailblaze_mbclid', null);
    }
}
