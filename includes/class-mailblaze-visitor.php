<?php
/**
 * Consent-gated visitor id for WooCommerce browse tracking.
 *
 * MBDI-1006 calls this module when it accepts an mbclid, before it clears
 * the Woo session:
 *   MailBlaze_Visitor::get_anon_id()
 *   MailBlaze_Visitor::link_email($email, $source, $mbclid = null)
 *   MailBlaze_Visitor::link_click($mbclid)
 *   MailBlaze_Visitor::consent_state()
 * Checkout visitor.linked stays on the order-processed hooks at priority 20.
 * The session clear is priority 30, so link_order still reads the click id.
 *
 * This file does not parse or sign mbclid. includes/mbclid.php owns that token.
 * A click id is forwarded as an opaque string on the link event.
 *
 * Phase 3B (mailblaze-commerce) ingests, on the same hooks host the plugin
 * already uses for POST /trigger (MAILBLAZE_HOOKS_API_BASE, default
 * https://control.mailblaze.com/api/hooks):
 *
 *   POST /ecommerce/product-viewed
 *   POST /ecommerce/visitor-linked
 *
 * Auth matches /trigger: Authorization: Bearer <api key>, X-Store-Id:
 * <mailblaze_wc_foreign_store_id>. customer_uid is not sent; commerce maps
 * the key to the account. subscriber_id is null until commerce stitches.
 *
 * Product view body: { "views": [ { event: ecommerce.product.viewed, ... } ] }
 * Ticket fields value and created_at are repeated as price and time.
 * page is "product" or "category". Category views leave product_id null.
 *
 * Link body: { event: ecommerce.visitor.linked, anon_id, email|null,
 *   mbclid|null, source: login|checkout|newsletter|mbclid|email, ... }
 *
 * Unknown consent records nothing and sets no cookie. A previous mb_anon_id
 * is not read until consent is granted, and is cleared only when consent is
 * explicitly denied. The cookie is first-party, HttpOnly, SameSite=Lax.
 */

if (!defined('ABSPATH')) {
    exit;
}

function mailblaze_wc_anon_cookie_name() {
    return 'mb_anon_id';
}

function mailblaze_wc_anon_cookie_ttl() {
    return 31536000;
}

function mailblaze_wc_view_ttl() {
    return 1800;
}

function mailblaze_wc_link_ttl() {
    return 86400;
}

function mailblaze_wc_view_batch_limit() {
    return 10;
}

/**
 * @param mixed $raw
 * @param string $granted_value
 * @param string $denied_value
 * @return string|null granted|denied
 */
function mailblaze_wc_choice_cookie($raw, $granted_value, $denied_value) {
    if (!is_string($raw)) {
        return null;
    }
    $value = strtolower(trim(urldecode($raw)));
    if ($value === $granted_value) {
        return 'granted';
    }
    if ($value === $denied_value) {
        return 'denied';
    }
    return null;
}

/**
 * @param mixed $raw
 * @return string|null
 */
function mailblaze_wc_cookieyes_marketing($raw) {
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $map = array();
    foreach (explode(',', urldecode($raw)) as $part) {
        $pair = explode(':', $part, 2);
        if (count($pair) !== 2) {
            continue;
        }
        $map[strtolower(trim($pair[0]))] = strtolower(trim($pair[1]));
    }
    if (!isset($map['advertisement'])) {
        return null;
    }
    if ($map['advertisement'] === 'yes') {
        return 'granted';
    }
    if ($map['advertisement'] === 'no') {
        return 'denied';
    }
    return null;
}

/**
 * @param mixed $raw
 * @return string|null
 */
function mailblaze_wc_cookiebot_marketing($raw) {
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $json = json_decode(urldecode($raw), true);
    if (!is_array($json) || !array_key_exists('marketing', $json)) {
        return null;
    }
    if ($json['marketing'] === true || $json['marketing'] === 1 || $json['marketing'] === 'true') {
        return 'granted';
    }
    return 'denied';
}

/**
 * @param mixed $raw
 * @return string|null
 */
function mailblaze_wc_borlabs_marketing($raw) {
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $json = json_decode(urldecode($raw), true);
    if (!is_array($json)) {
        $json = json_decode(stripslashes(urldecode($raw)), true);
    }
    if (!is_array($json) || !isset($json['consents']) || !is_array($json['consents'])) {
        return null;
    }
    if (!empty($json['consents']['marketing']) && is_array($json['consents']['marketing'])) {
        return 'granted';
    }
    return 'denied';
}

/**
 * @param mixed $raw
 * @return string|null
 */
function mailblaze_wc_moove_marketing($raw) {
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $json = json_decode(stripslashes(urldecode($raw)), true);
    if (!is_array($json) || !array_key_exists('thirdparty', $json)) {
        return null;
    }
    return (string) $json['thirdparty'] === '1' ? 'granted' : 'denied';
}

/**
 * @param array $cookies
 * @return string|null
 */
function mailblaze_wc_cli_marketing($cookies) {
    $viewed = isset($cookies['viewed_cookie_policy']) ? strtolower((string) $cookies['viewed_cookie_policy']) : '';
    if ($viewed !== 'yes') {
        return null;
    }
    if (isset($cookies['cookielawinfo-checkbox-advertisement'])) {
        return strtolower((string) $cookies['cookielawinfo-checkbox-advertisement']) === 'yes' ? 'granted' : 'denied';
    }
    if (isset($cookies['cookielawinfo-checkbox-non-necessary'])) {
        return strtolower((string) $cookies['cookielawinfo-checkbox-non-necessary']) === 'yes' ? 'granted' : 'denied';
    }
    return null;
}

/**
 * Marketing consent only. Statistics consent is not enough.
 * Any explicit deny wins. No signal means unknown, which records nothing.
 *
 * @param array $cookies
 * @return string granted|denied|unknown
 */
function mailblaze_wc_consent_from_cookies($cookies) {
    if (!is_array($cookies)) {
        $cookies = array();
    }
    $signals = array();
    $add = function ($signal) use (&$signals) {
        if ($signal === 'granted' || $signal === 'denied') {
            $signals[] = $signal;
        }
    };

    $add(mailblaze_wc_choice_cookie(
        isset($cookies['wp_consent_marketing']) ? $cookies['wp_consent_marketing'] : null,
        'allow',
        'deny'
    ));
    $add(mailblaze_wc_choice_cookie(
        isset($cookies['cmplz_marketing']) ? $cookies['cmplz_marketing'] : null,
        'allow',
        'deny'
    ));
    $add(mailblaze_wc_cookieyes_marketing(isset($cookies['cookieyes-consent']) ? $cookies['cookieyes-consent'] : null));
    $add(mailblaze_wc_cookiebot_marketing(isset($cookies['CookieConsent']) ? $cookies['CookieConsent'] : null));
    $add(mailblaze_wc_cli_marketing($cookies));
    $add(mailblaze_wc_borlabs_marketing(isset($cookies['borlabs-cookie']) ? $cookies['borlabs-cookie'] : null));
    $add(mailblaze_wc_moove_marketing(isset($cookies['moove_gdpr_popup']) ? $cookies['moove_gdpr_popup'] : null));

    if (in_array('denied', $signals, true)) {
        return 'denied';
    }
    if (in_array('granted', $signals, true)) {
        return 'granted';
    }
    return 'unknown';
}

/**
 * @param mixed $value
 * @return bool
 */
function mailblaze_wc_is_anon_id($value) {
    return is_string($value) && preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        $value
    ) === 1;
}

function mailblaze_wc_generate_anon_id() {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

/**
 * @param mixed $existing
 * @param string $consent
 * @param string $generated
 * @return array{anon_id: ?string, set_cookie: bool, clear_cookie: bool}
 */
function mailblaze_wc_decide_anon_id($existing, $consent, $generated) {
    $current = is_string($existing) ? strtolower(trim($existing)) : '';
    if ($consent !== 'granted') {
        return array(
            'anon_id' => null,
            'set_cookie' => false,
            'clear_cookie' => $consent === 'denied' && mailblaze_wc_is_anon_id($current),
        );
    }
    if (mailblaze_wc_is_anon_id($current)) {
        return array(
            'anon_id' => $current,
            'set_cookie' => false,
            'clear_cookie' => false,
        );
    }
    $fresh = is_string($generated) ? strtolower(trim($generated)) : '';
    if (!mailblaze_wc_is_anon_id($fresh)) {
        return array(
            'anon_id' => null,
            'set_cookie' => false,
            'clear_cookie' => false,
        );
    }
    return array(
        'anon_id' => $fresh,
        'set_cookie' => true,
        'clear_cookie' => false,
    );
}

/**
 * @param string $value
 * @param int $now
 * @param bool $secure
 * @param string $path
 * @param string $domain
 * @param bool $clear
 * @return array{name: string, value: string, options: array}
 */
function mailblaze_wc_anon_cookie_options($value, $now, $secure, $path, $domain, $clear) {
    $ttl = mailblaze_wc_anon_cookie_ttl();
    $options = array(
        'expires' => $clear ? $now - 3600 : $now + $ttl,
        'path' => (is_string($path) && $path !== '') ? $path : '/',
        'secure' => (bool) $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    );
    if (is_string($domain) && $domain !== '') {
        $options['domain'] = $domain;
    }
    return array(
        'name' => mailblaze_wc_anon_cookie_name(),
        'value' => $clear ? '' : $value,
        'options' => $options,
    );
}

/**
 * @param mixed $email
 * @return string|null
 */
function mailblaze_wc_normalize_email($email) {
    if (!is_string($email)) {
        return null;
    }
    $email = strtolower(trim($email));
    if ($email === '' || strlen($email) > 254) {
        return null;
    }
    if (strpos($email, "\n") !== false || strpos($email, "\r") !== false) {
        return null;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    return $email;
}

/**
 * @param mixed $user_email
 * @param mixed $billing_email
 * @return string|null
 */
function mailblaze_wc_known_email($user_email, $billing_email) {
    $user = mailblaze_wc_normalize_email($user_email);
    if ($user !== null) {
        return $user;
    }
    return mailblaze_wc_normalize_email($billing_email);
}

/**
 * Opaque click id. Format and HMAC stay in the mbclid module / commerce.
 *
 * @param mixed $raw
 * @return string|null
 */
function mailblaze_wc_passthrough_mbclid($raw) {
    if (!is_string($raw)) {
        return null;
    }
    $token = trim($raw);
    if ($token === '' || strlen($token) > 512) {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9._\-]+$/', $token)) {
        return null;
    }
    return $token;
}

/**
 * @param mixed $value
 * @return string
 */
function mailblaze_wc_public_id($value) {
    if (is_int($value)) {
        $value = (string) $value;
    }
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    if (!preg_match('/^[0-9]{1,20}$/', $value) || $value === '0') {
        return '';
    }
    return $value;
}

/**
 * @param mixed $ids
 * @return string[]
 */
function mailblaze_wc_id_list($ids) {
    $out = array();
    if (!is_array($ids)) {
        return $out;
    }
    foreach ($ids as $id) {
        $public = mailblaze_wc_public_id($id);
        if ($public === '' || in_array($public, $out, true)) {
            continue;
        }
        $out[] = $public;
        if (count($out) >= 50) {
            break;
        }
    }
    return $out;
}

/**
 * @param mixed $value
 * @param int $max
 * @return string|null
 */
function mailblaze_wc_optional_token($value, $max) {
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }
    $text = trim((string) $value);
    if ($text === '' || strlen($text) > $max) {
        return null;
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
        return null;
    }
    return $text;
}

/**
 * @param mixed $value
 * @return string|null
 */
function mailblaze_wc_price($value) {
    if ($value === null || $value === '') {
        return null;
    }
    if (is_string($value)) {
        $value = trim($value);
    }
    if (!is_numeric($value)) {
        return null;
    }
    $number = (float) $value;
    if ($number < 0 || $number > 100000000) {
        return null;
    }
    return number_format($number, 2, '.', '');
}

/**
 * @param mixed $value
 * @return string|null
 */
function mailblaze_wc_currency($value) {
    if (!is_string($value)) {
        return null;
    }
    $currency = strtoupper(trim($value));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        return null;
    }
    return $currency;
}

/**
 * @param mixed $value
 * @return string
 */
function mailblaze_wc_created_at($value) {
    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+\-]\d{2}:\d{2})$/', $value)) {
        return $value;
    }
    return gmdate('c');
}

/**
 * @param string $source
 * @return string
 */
function mailblaze_wc_link_source($source) {
    $allowed = array('login', 'checkout', 'newsletter', 'mbclid', 'email');
    return in_array($source, $allowed, true) ? $source : 'email';
}

/**
 * @param mixed $raw
 * @return array|null
 */
function mailblaze_wc_sanitize_client_view($raw) {
    if (!is_array($raw)) {
        return null;
    }
    $page = (isset($raw['page']) && $raw['page'] === 'category') ? 'category' : 'product';
    $product_id = mailblaze_wc_public_id(isset($raw['product_id']) ? $raw['product_id'] : '');
    $variant_id = mailblaze_wc_public_id(isset($raw['variant_id']) ? $raw['variant_id'] : '');
    $category_ids = mailblaze_wc_id_list(isset($raw['category_ids']) ? $raw['category_ids'] : array());
    if ($page === 'category') {
        if (!$category_ids) {
            return null;
        }
        return array(
            'page' => 'category',
            'product_id' => null,
            'variant_id' => null,
            'category_ids' => $category_ids,
        );
    }
    if ($product_id === '') {
        return null;
    }
    return array(
        'page' => 'product',
        'product_id' => $product_id,
        'variant_id' => $variant_id === '' ? null : $variant_id,
        'category_ids' => $category_ids,
    );
}

/**
 * @param array $input
 * @return array|null
 */
function mailblaze_wc_product_view_event($input) {
    if (!is_array($input)) {
        return null;
    }
    $anon = isset($input['anon_id']) ? strtolower(trim((string) $input['anon_id'])) : '';
    if (!mailblaze_wc_is_anon_id($anon)) {
        return null;
    }
    $page = (isset($input['page']) && $input['page'] === 'category') ? 'category' : 'product';
    $category_ids = mailblaze_wc_id_list(isset($input['category_ids']) ? $input['category_ids'] : array());
    $product_id = mailblaze_wc_public_id(isset($input['product_id']) ? $input['product_id'] : '');
    $variant_id = mailblaze_wc_public_id(isset($input['variant_id']) ? $input['variant_id'] : '');
    if ($page === 'category') {
        if (!$category_ids) {
            return null;
        }
        $product_id = '';
        $variant_id = '';
    } elseif ($product_id === '') {
        return null;
    }
    $created = mailblaze_wc_created_at(isset($input['created_at']) ? $input['created_at'] : null);
    $price = mailblaze_wc_price(isset($input['price']) ? $input['price'] : null);
    return array(
        'event' => 'ecommerce.product.viewed',
        'platform' => 'woocommerce',
        'store_id' => mailblaze_wc_optional_token(isset($input['store_id']) ? $input['store_id'] : null, 64),
        'list_uid' => mailblaze_wc_optional_token(isset($input['list_uid']) ? $input['list_uid'] : null, 32),
        'subscriber_id' => null,
        'anon_id' => $anon,
        'email' => mailblaze_wc_normalize_email(isset($input['email']) ? $input['email'] : null),
        'product_id' => $product_id === '' ? null : $product_id,
        'variant_id' => $variant_id === '' ? null : $variant_id,
        'category_ids' => $category_ids,
        'value' => $price,
        'price' => $price,
        'currency' => mailblaze_wc_currency(isset($input['currency']) ? $input['currency'] : null),
        'created_at' => $created,
        'time' => $created,
        'page' => $page,
    );
}

/**
 * @param string $anon_id
 * @param string $page
 * @param mixed $product_id
 * @param mixed $variant_id
 * @param array $category_ids
 * @return string
 */
function mailblaze_wc_view_throttle_key($anon_id, $page, $product_id, $variant_id, $category_ids) {
    $cats = array();
    foreach ((array) $category_ids as $id) {
        $cats[] = (string) $id;
    }
    sort($cats, SORT_STRING);
    $material = $anon_id . '|' . $page . '|' . (string) $product_id . '|' . (string) $variant_id . '|' . implode(',', $cats);
    return 'mb_view_' . substr(hash('sha256', $material), 0, 40);
}

/**
 * @param array $events
 * @param array|callable $throttled
 * @param int $now
 * @return array{views: array, keys: array, until: int}
 */
function mailblaze_wc_select_views($events, $throttled, $now) {
    $window = mailblaze_wc_view_ttl();
    // An array callable such as array($tracker, 'is_view_throttled') is also an
    // array. Treat callables first so that method is invoked.
    if (!is_callable($throttled) && is_array($throttled)) {
        $snapshot = $throttled;
        $throttled = function ($key) use ($snapshot, $now) {
            return isset($snapshot[$key]) && $snapshot[$key] > $now;
        };
    }
    if (!is_callable($throttled)) {
        $throttled = function ($key) {
            return false;
        };
    }
    $views = array();
    $keys = array();
    $seen = array();
    foreach ($events as $event) {
        $built = mailblaze_wc_product_view_event($event);
        if (!$built) {
            continue;
        }
        $key = mailblaze_wc_view_throttle_key(
            $built['anon_id'],
            $built['page'],
            $built['product_id'],
            $built['variant_id'],
            $built['category_ids']
        );
        if (isset($seen[$key]) || call_user_func($throttled, $key)) {
            continue;
        }
        $seen[$key] = true;
        $views[] = $built;
        $keys[] = $key;
        if (count($views) >= mailblaze_wc_view_batch_limit()) {
            break;
        }
    }
    return array(
        'views' => $views,
        'keys' => $keys,
        'until' => $now + $window,
    );
}

/**
 * Server context wins. Client email, anon id, and price are ignored.
 *
 * @param string $consent
 * @param array $context
 * @param array $client_views
 * @param callable $catalog
 * @param array|callable $throttled
 * @param int $now
 * @return array|null
 */
function mailblaze_wc_prepare_view_batch($consent, $context, $client_views, $catalog, $throttled, $now) {
    if ($consent !== 'granted' || !is_array($context) || !mailblaze_wc_is_anon_id(isset($context['anon_id']) ? $context['anon_id'] : '')) {
        return null;
    }
    if (!is_callable($catalog) || !is_array($client_views)) {
        return null;
    }
    $events = array();
    foreach (array_slice($client_views, 0, mailblaze_wc_view_batch_limit()) as $raw) {
        $clean = mailblaze_wc_sanitize_client_view($raw);
        if (!$clean) {
            continue;
        }
        $priced = call_user_func($catalog, $clean);
        if (!is_array($priced)) {
            continue;
        }
        $events[] = array(
            'page' => $clean['page'],
            'product_id' => isset($priced['product_id']) ? $priced['product_id'] : null,
            'variant_id' => isset($priced['variant_id']) ? $priced['variant_id'] : null,
            'category_ids' => isset($priced['category_ids']) && is_array($priced['category_ids']) ? $priced['category_ids'] : array(),
            'price' => array_key_exists('price', $priced) ? $priced['price'] : null,
            'currency' => isset($context['currency']) ? $context['currency'] : null,
            'anon_id' => $context['anon_id'],
            'email' => isset($context['email']) ? $context['email'] : null,
            'store_id' => isset($context['store_id']) ? $context['store_id'] : null,
            'list_uid' => isset($context['list_uid']) ? $context['list_uid'] : null,
            'created_at' => isset($context['created_at']) ? $context['created_at'] : null,
        );
    }
    $selected = mailblaze_wc_select_views($events, $throttled, $now);
    if (empty($selected['views'])) {
        return null;
    }
    return $selected;
}

/**
 * @param array $input
 * @return array|null
 */
function mailblaze_wc_visitor_link_event($input) {
    if (!is_array($input)) {
        return null;
    }
    $anon = isset($input['anon_id']) ? strtolower(trim((string) $input['anon_id'])) : '';
    if (!mailblaze_wc_is_anon_id($anon)) {
        return null;
    }
    $email = mailblaze_wc_normalize_email(isset($input['email']) ? $input['email'] : null);
    $mbclid = mailblaze_wc_passthrough_mbclid(isset($input['mbclid']) ? $input['mbclid'] : null);
    if ($email === null && $mbclid === null) {
        return null;
    }
    return array(
        'event' => 'ecommerce.visitor.linked',
        'platform' => 'woocommerce',
        'store_id' => mailblaze_wc_optional_token(isset($input['store_id']) ? $input['store_id'] : null, 64),
        'list_uid' => mailblaze_wc_optional_token(isset($input['list_uid']) ? $input['list_uid'] : null, 32),
        'subscriber_id' => null,
        'anon_id' => $anon,
        'email' => $email,
        'mbclid' => $mbclid,
        'source' => mailblaze_wc_link_source(isset($input['source']) ? $input['source'] : ''),
        'created_at' => mailblaze_wc_created_at(isset($input['created_at']) ? $input['created_at'] : null),
    );
}

/**
 * Newsletter stitching only when the shopper ticked the box.
 * An unticked box is 'no' and must not send source newsletter.
 *
 * @param mixed $optin
 * @return bool
 */
function mailblaze_wc_newsletter_optin_links($optin) {
    return $optin === 'yes';
}

/**
 * Source is part of the key so a newsletter link does not drop login or checkout.
 *
 * @param string $anon_id
 * @param mixed $email
 * @param mixed $mbclid
 * @param mixed $source
 * @return string
 */
function mailblaze_wc_link_throttle_key($anon_id, $email, $mbclid, $source = '') {
    $email = is_string($email) ? strtolower($email) : '';
    $mbclid = is_string($mbclid) ? $mbclid : '';
    $source = mailblaze_wc_link_source(is_string($source) ? $source : '');
    return 'mb_link_' . substr(hash('sha256', $anon_id . '|' . $email . '|' . $mbclid . '|' . $source), 0, 40);
}

/**
 * @param string $name
 * @return string|null
 */
function mailblaze_wc_browse_path($name) {
    if ($name === 'product-viewed') {
        return '/ecommerce/product-viewed';
    }
    if ($name === 'visitor-linked') {
        return '/ecommerce/visitor-linked';
    }
    return null;
}

/**
 * @param string $hooks_base
 * @param string $name
 * @return string|null
 */
function mailblaze_wc_browse_url($hooks_base, $name) {
    $path = mailblaze_wc_browse_path($name);
    if ($path === null) {
        return null;
    }
    $base = rtrim((string) $hooks_base, '/');
    if ($base === '' || strpos($base, '://') === false) {
        return null;
    }
    return $base . $path;
}

/**
 * @param bool $nonce_ok
 * @param string $consent
 * @param int $sent_count
 * @return int
 */
function mailblaze_wc_ingest_status($nonce_ok, $consent, $sent_count) {
    if (!$nonce_ok) {
        return 403;
    }
    if ($consent !== 'granted' || $sent_count < 1) {
        return 204;
    }
    return 202;
}

/**
 * @param int $count
 * @param int $limit
 * @return bool
 */
function mailblaze_wc_rate_allow($count, $limit = 30) {
    return $count < $limit;
}

/**
 * Shared NAT addresses stay distinct per consented visitor.
 * An id that is not a real anon id does not open another bucket.
 *
 * @param mixed $remote_addr
 * @param mixed $anon_id
 * @return string
 */
function mailblaze_wc_rate_limit_key($remote_addr, $anon_id) {
    $ip = is_string($remote_addr) ? $remote_addr : '';
    $anon = is_string($anon_id) ? strtolower(trim($anon_id)) : '';
    if (!mailblaze_wc_is_anon_id($anon)) {
        $anon = '';
    }
    return 'mb_view_rate_' . substr(hash('sha256', $ip . '|' . $anon), 0, 32);
}

/**
 * Address ceiling always applies. A per-id bucket is added only for an id
 * that arrived on the request. An id minted in this request is not a bucket:
 * on_init writes a fresh UUID into $_COOKIE before the cap is checked.
 *
 * @param mixed $remote_addr
 * @param mixed $anon_id
 * @param bool $id_was_minted
 * @return string[]
 */
function mailblaze_wc_rate_buckets($remote_addr, $anon_id, $id_was_minted) {
    $keys = array(mailblaze_wc_rate_limit_key($remote_addr, ''));
    $anon = is_string($anon_id) ? strtolower(trim($anon_id)) : '';
    if (!$id_was_minted && mailblaze_wc_is_anon_id($anon)) {
        $keys[] = mailblaze_wc_rate_limit_key($remote_addr, $anon);
    }
    return $keys;
}

/**
 * @param array $counts
 * @param int $limit
 * @return bool
 */
function mailblaze_wc_rate_blocked($counts, $limit = 30) {
    foreach ((array) $counts as $count) {
        if (!mailblaze_wc_rate_allow((int) $count, $limit)) {
            return true;
        }
    }
    return false;
}

/**
 * Account and checkout checkboxes post "1" when ticked. A present field
 * whose value is "no" is not consent.
 *
 * @param mixed $raw
 * @return string yes|no
 */
function mailblaze_wc_posted_optin($raw) {
    if (is_array($raw)) {
        return 'no';
    }
    if (!is_string($raw) && !is_numeric($raw)) {
        return 'no';
    }
    $value = strtolower(trim((string) $raw));
    if ($value === 'yes' || $value === '1') {
        return 'yes';
    }
    return 'no';
}

/**
 * Non-blocking requests usually return before a status exists (code 0).
 * A real 4xx/5xx is the only failure we can see in that mode.
 *
 * @param mixed $code
 * @return int|null
 */
function mailblaze_wc_browse_http_error($code) {
    $code = (int) $code;
    if ($code >= 400) {
        return $code;
    }
    return null;
}

class MailBlaze_Visitor {
    private static $applied = false;
    private static $anon_id = null;
    private static $anon_minted = false;
    private static $api_client = null;
    private static $transport = null;
    private static $sent_keys = array();

    public static function set_api_client($api_client) {
        self::$api_client = $api_client;
    }

    /**
     * @param array|null $cookies
     * @return string
     */
    public static function consent_state($cookies = null) {
        if ($cookies === null) {
            $cookies = (isset($_COOKIE) && is_array($_COOKIE)) ? $_COOKIE : array();
        }
        $state = mailblaze_wc_consent_from_cookies($cookies);
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('mailblaze_wc_visitor_consent', $state, $cookies);
            if ($filtered === 'granted' || $filtered === 'denied' || $filtered === 'unknown') {
                $state = $filtered;
            }
        }
        return $state;
    }

    /**
     * @return string|null
     */
    public static function get_anon_id() {
        if (!self::$applied) {
            self::on_init();
        }
        return self::$anon_id;
    }

    /**
     * True when this request created mb_anon_id. The rate cap must not key
     * on that fresh value, because a missing cookie would mint a new bucket.
     *
     * @return bool
     */
    public static function anon_id_was_minted() {
        if (!self::$applied) {
            self::on_init();
        }
        return self::$anon_minted;
    }

    public static function on_init() {
        if (self::$applied) {
            return;
        }
        if ((defined('DOING_CRON') && DOING_CRON) || (function_exists('is_admin') && is_admin())) {
            self::$applied = true;
            self::$anon_id = null;
            return;
        }
        $cookies = (isset($_COOKIE) && is_array($_COOKIE)) ? $_COOKIE : array();
        $name = mailblaze_wc_anon_cookie_name();
        $decision = mailblaze_wc_decide_anon_id(
            isset($cookies[$name]) ? $cookies[$name] : '',
            self::consent_state($cookies),
            mailblaze_wc_generate_anon_id()
        );
        self::$applied = true;
        self::$anon_id = $decision['anon_id'];
        self::$anon_minted = !empty($decision['set_cookie']);
        if (!empty($decision['set_cookie']) || !empty($decision['clear_cookie'])) {
            self::persist_cookie($decision);
        }
        if (self::$anon_id) {
            self::capture_mbclid_query();
        }
    }

    /**
     * @param string $email
     * @param string $source
     * @param mixed $mbclid
     * @return bool
     */
    public static function link_email($email, $source = 'email', $mbclid = null) {
        $anon = self::get_anon_id();
        if (!$anon) {
            return false;
        }
        $event = mailblaze_wc_visitor_link_event(array(
            'anon_id' => $anon,
            'email' => $email,
            'mbclid' => $mbclid,
            'source' => $source,
            'store_id' => self::store_id(),
            'list_uid' => self::list_uid(),
            'created_at' => gmdate('c'),
        ));
        if (!$event) {
            return false;
        }
        $key = mailblaze_wc_link_throttle_key($event['anon_id'], $event['email'], $event['mbclid'], $event['source']);
        if (self::throttle_hit($key)) {
            return true;
        }
        if (!self::post('visitor-linked', $event)) {
            return false;
        }
        self::throttle_set($key, mailblaze_wc_link_ttl());
        return true;
    }

    /**
     * @param mixed $mbclid
     * @return bool
     */
    public static function link_click($mbclid) {
        return self::link_email('', 'mbclid', $mbclid);
    }

    /**
     * @param array $views
     * @return bool
     */
    public static function post_product_views($views) {
        if (!is_array($views) || !$views) {
            return false;
        }
        return self::post('product-viewed', array('views' => array_values($views)));
    }

    /**
     * @param string $user_login
     * @param object $user
     */
    public static function on_wp_login($user_login, $user) {
        unset($user_login);
        if (is_object($user) && !empty($user->user_email)) {
            self::link_email($user->user_email, 'login');
        }
    }

    /**
     * @param int $customer_id
     */
    public static function on_created_customer($customer_id) {
        if (!function_exists('get_userdata')) {
            return;
        }
        $user = get_userdata($customer_id);
        if ($user && !empty($user->user_email)) {
            self::link_email($user->user_email, 'login');
        }
    }

    /**
     * @param int $order_id
     */
    public static function on_checkout($order_id) {
        $order = null;
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
        }
        self::link_order($order);
    }

    /**
     * @param object $order
     */
    public static function on_checkout_order($order) {
        self::link_order($order);
    }

    /**
     * @param object|null $order
     */
    private static function link_order($order) {
        if (!is_object($order) || !method_exists($order, 'get_billing_email')) {
            return;
        }
        $mbclid = null;
        if (function_exists('WC')) {
            $wc = WC();
            if (is_object($wc) && isset($wc->session) && is_object($wc->session) && method_exists($wc->session, 'get')) {
                $mbclid = $wc->session->get('mailblaze_mbclid');
            }
        }
        self::link_email($order->get_billing_email(), 'checkout', $mbclid);
    }

    private static function capture_mbclid_query() {
        if (!isset($_GET['mbclid']) || !is_string($_GET['mbclid'])) {
            return;
        }
        $raw = function_exists('wp_unslash') ? wp_unslash($_GET['mbclid']) : stripslashes($_GET['mbclid']);
        self::link_click($raw);
    }

    /**
     * @param array $decision
     */
    private static function persist_cookie($decision) {
        $clear = !empty($decision['clear_cookie']);
        $secure = function_exists('is_ssl') ? (bool) is_ssl() : false;
        $path = (defined('COOKIEPATH') && is_string(COOKIEPATH)) ? COOKIEPATH : '/';
        $domain = (defined('COOKIE_DOMAIN') && is_string(COOKIE_DOMAIN)) ? COOKIE_DOMAIN : '';
        $spec = mailblaze_wc_anon_cookie_options(
            $clear ? '' : $decision['anon_id'],
            time(),
            $secure,
            $path,
            $domain,
            $clear
        );
        if (!headers_sent()) {
            setcookie($spec['name'], $spec['value'], $spec['options']);
        }
        if ($clear) {
            unset($_COOKIE[mailblaze_wc_anon_cookie_name()]);
        } else {
            $_COOKIE[mailblaze_wc_anon_cookie_name()] = $spec['value'];
        }
    }

    /**
     * @return string
     */
    private static function store_id() {
        if (!function_exists('get_option')) {
            return '';
        }
        $id = get_option('mailblaze_wc_store_id', '');
        return is_scalar($id) ? (string) $id : '';
    }

    /**
     * @return string
     */
    private static function list_uid() {
        if (!function_exists('get_option')) {
            return '';
        }
        $id = get_option('mailblaze_wc_mailing_list', '');
        return is_scalar($id) ? (string) $id : '';
    }

    /**
     * @param string $key
     * @return bool
     */
    private static function throttle_hit($key) {
        if (isset(self::$sent_keys[$key])) {
            return true;
        }
        return function_exists('get_transient') && (bool) get_transient($key);
    }

    /**
     * @param string $key
     * @param int $ttl
     */
    private static function throttle_set($key, $ttl) {
        self::$sent_keys[$key] = true;
        if (function_exists('set_transient')) {
            set_transient($key, 1, $ttl);
        }
    }

    /**
     * @param string $name
     * @param array $body
     * @return bool
     */
    private static function post($name, $body) {
        if (self::$transport) {
            return call_user_func(self::$transport, $name, $body) !== false;
        }
        if (!self::$api_client) {
            return false;
        }
        try {
            if ($name === 'visitor-linked') {
                self::$api_client->send_visitor_link($body);
                return true;
            }
            if ($name === 'product-viewed') {
                self::$api_client->send_product_views($body);
                return true;
            }
        } catch (Exception $e) {
            error_log('Mailblaze browse post failed');
            return false;
        }
        return false;
    }
}

if (function_exists('add_action')) {
    add_action('init', array('MailBlaze_Visitor', 'on_init'), 1);
    add_action('wp_login', array('MailBlaze_Visitor', 'on_wp_login'), 10, 2);
    add_action('woocommerce_created_customer', array('MailBlaze_Visitor', 'on_created_customer'), 20, 1);
    add_action('woocommerce_checkout_order_processed', array('MailBlaze_Visitor', 'on_checkout'), 20, 1);
    add_action('woocommerce_store_api_checkout_order_processed', array('MailBlaze_Visitor', 'on_checkout_order'), 20, 1);
}
