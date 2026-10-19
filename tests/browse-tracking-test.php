<?php
/**
 * Functional tests for consent-gated browse tracking. HTTP is mocked.
 */
define('ABSPATH', dirname(__DIR__) . '/');

require dirname(__DIR__) . '/includes/class-mailblaze-visitor.php';

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

function mailblaze_assert_null($actual, $message) {
    if ($actual !== null) {
        fwrite(STDERR, $message . " expected null got " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$anon = '11111111-1111-4111-8111-111111111111';
$other = '22222222-2222-4222-8222-222222222222';
$now = 1700000000;
$stamp = '2026-10-16T07:00:00Z';

mailblaze_assert_same('unknown', mailblaze_wc_consent_from_cookies(array()), 'no cookies is unknown consent');
mailblaze_assert_same('unknown', mailblaze_wc_consent_from_cookies(array(
    'wp_consent_statistics' => 'allow',
)), 'statistics consent does not grant marketing tracking');
mailblaze_assert_same('granted', mailblaze_wc_consent_from_cookies(array(
    'wp_consent_marketing' => 'allow',
)), 'WP Consent API marketing allow');
mailblaze_assert_same('denied', mailblaze_wc_consent_from_cookies(array(
    'wp_consent_marketing' => 'deny',
)), 'WP Consent API marketing deny');
mailblaze_assert_same('denied', mailblaze_wc_consent_from_cookies(array(
    'cmplz_marketing' => 'allow',
    'cookieyes-consent' => 'consentid:abc,advertisement:no',
)), 'an explicit deny wins over another plugin allow');
mailblaze_assert_same('granted', mailblaze_wc_consent_from_cookies(array(
    'cookieyes-consent' => 'consentid%3Aabc%2Cadvertisement%3Ayes',
)), 'CookieYes advertisement yes');
mailblaze_assert_same('granted', mailblaze_wc_consent_from_cookies(array(
    'CookieConsent' => '{"necessary":true,"marketing":true}',
)), 'Cookiebot marketing true');
mailblaze_assert_same('denied', mailblaze_wc_consent_from_cookies(array(
    'CookieConsent' => '{"marketing":false}',
)), 'Cookiebot marketing false');
mailblaze_assert_same('granted', mailblaze_wc_consent_from_cookies(array(
    'viewed_cookie_policy' => 'yes',
    'cookielawinfo-checkbox-advertisement' => 'yes',
)), 'Cookie Law Info advertisement after a choice');
mailblaze_assert_same('unknown', mailblaze_wc_consent_from_cookies(array(
    'cookielawinfo-checkbox-advertisement' => 'yes',
)), 'Cookie Law Info without a recorded choice is unknown');
mailblaze_assert_same('granted', mailblaze_wc_consent_from_cookies(array(
    'borlabs-cookie' => '{"consents":{"essential":["borlabs-cookie"],"marketing":["facebook"]}}',
)), 'Borlabs marketing list');
mailblaze_assert_same('denied', mailblaze_wc_consent_from_cookies(array(
    'borlabs-cookie' => '{"consents":{"essential":["borlabs-cookie"],"marketing":[]}}',
)), 'Borlabs saved choice without marketing');
mailblaze_assert_same('unknown', mailblaze_wc_consent_from_cookies(array(
    'borlabs-cookie' => 'not-json',
)), 'unreadable consent cookie stays unknown');
mailblaze_assert_same('granted', mailblaze_wc_consent_from_cookies(array(
    'moove_gdpr_popup' => '{"strict":"1","thirdparty":"1"}',
)), 'Moove third-party consent');

$unknown_decision = mailblaze_wc_decide_anon_id($anon, 'unknown', $other);
mailblaze_assert_null($unknown_decision['anon_id'], 'unknown consent does not read an existing anon id');
mailblaze_assert_same(false, $unknown_decision['set_cookie'], 'unknown consent sets no cookie');
mailblaze_assert_same(false, $unknown_decision['clear_cookie'], 'unknown consent leaves any existing cookie untouched');

$denied_decision = mailblaze_wc_decide_anon_id($anon, 'denied', $other);
mailblaze_assert_null($denied_decision['anon_id'], 'denied consent records nothing');
mailblaze_assert_same(false, $denied_decision['set_cookie'], 'denied consent does not refresh the cookie');
mailblaze_assert_same(true, $denied_decision['clear_cookie'], 'denied consent clears a previous anon id');

$reused = mailblaze_wc_decide_anon_id(strtoupper($anon), 'granted', $other);
mailblaze_assert_same($anon, $reused['anon_id'], 'granted consent reuses the existing anon id');
mailblaze_assert_same(false, $reused['set_cookie'], 'a valid cookie is not rewritten');

$fresh = mailblaze_wc_decide_anon_id('not-an-id', 'granted', $anon);
mailblaze_assert_same($anon, $fresh['anon_id'], 'granted consent replaces an invalid cookie');
mailblaze_assert_same(true, $fresh['set_cookie'], 'granted consent sets mb_anon_id');

$broken = mailblaze_wc_decide_anon_id('', 'granted', 'not-a-uuid');
mailblaze_assert_null($broken['anon_id'], 'a bad generated id is not stored');
mailblaze_assert_same(false, $broken['set_cookie'], 'a bad generated id does not set a cookie');

$seen = array();
for ($i = 0; $i < 20; $i++) {
    $generated = mailblaze_wc_generate_anon_id();
    mailblaze_assert_true(mailblaze_wc_is_anon_id($generated), 'generated id is a lowercase uuid v4');
    mailblaze_assert_true(!isset($seen[$generated]), 'generated ids are not repeated in a small sample');
    $seen[$generated] = true;
}

$cookie = mailblaze_wc_anon_cookie_options($anon, $now, false, '/', '', false);
mailblaze_assert_same('mb_anon_id', $cookie['name'], 'cookie name is mb_anon_id');
mailblaze_assert_same($anon, $cookie['value'], 'cookie value is the anon id');
mailblaze_assert_same(true, $cookie['options']['httponly'], 'anon cookie is HttpOnly');
mailblaze_assert_same('Lax', $cookie['options']['samesite'], 'anon cookie is SameSite Lax');
mailblaze_assert_same(false, $cookie['options']['secure'], 'secure flag follows the caller');
mailblaze_assert_true(!isset($cookie['options']['domain']), 'host-only when no domain is configured');
mailblaze_assert_true(strpos($cookie['value'], '@') === false, 'cookie value has no email');
$cleared = mailblaze_wc_anon_cookie_options($anon, $now, true, '/', '', true);
mailblaze_assert_same('', $cleared['value'], 'clearing the cookie empties the value');
mailblaze_assert_same($now - 3600, $cleared['options']['expires'], 'cleared cookie is already expired');

$clean = mailblaze_wc_sanitize_client_view(array(
    'page' => 'product',
    'product_id' => 15,
    'variant_id' => '99',
    'email' => 'evil@example.com',
    'anon_id' => $other,
    'price' => '0.01',
    'category_ids' => array('4', 'nope', '4'),
));
mailblaze_assert_true(!isset($clean['email']) && !isset($clean['anon_id']) && !isset($clean['price']), 'client view drops email, anon id, and price');
mailblaze_assert_same(array('4'), $clean['category_ids'], 'category ids are numeric and unique');

$catalog_calls = 0;
$catalog = function ($view) use (&$catalog_calls) {
    $catalog_calls++;
    if ($view['page'] === 'category') {
        return array(
            'product_id' => null,
            'variant_id' => null,
            'category_ids' => $view['category_ids'],
            'price' => null,
        );
    }
    return array(
        'product_id' => $view['product_id'],
        'variant_id' => $view['variant_id'],
        'category_ids' => array('4', '9'),
        'price' => '19.5',
    );
};

$context = array(
    'anon_id' => $anon,
    'email' => null,
    'store_id' => '55',
    'list_uid' => 'listuid',
    'currency' => 'zar',
    'created_at' => $stamp,
);
$client_view = array(
    'page' => 'product',
    'product_id' => '15',
    'variant_id' => '99',
    'email' => 'evil@example.com',
    'anon_id' => $other,
    'price' => '0.01',
    'category_ids' => array('1'),
);

mailblaze_assert_null(mailblaze_wc_prepare_view_batch('unknown', $context, array($client_view), $catalog, array(), $now), 'unknown consent prepares no batch');
mailblaze_assert_null(mailblaze_wc_prepare_view_batch('denied', $context, array($client_view), $catalog, array(), $now), 'denied consent prepares no batch');
mailblaze_assert_same(0, $catalog_calls, 'catalog is not read when consent is missing');

$prepared = mailblaze_wc_prepare_view_batch('granted', $context, array($client_view), $catalog, array(), $now);
mailblaze_assert_true(is_array($prepared), 'granted consent prepares a batch');
$view = $prepared['views'][0];
mailblaze_assert_same('ecommerce.product.viewed', $view['event'], 'event name');
mailblaze_assert_same($anon, $view['anon_id'], 'server anon id is used');
mailblaze_assert_null($view['email'], 'server email stays empty when the shopper is unknown');
mailblaze_assert_same('15', $view['product_id'], 'product id');
mailblaze_assert_same('99', $view['variant_id'], 'variant id');
mailblaze_assert_same(array('4', '9'), $view['category_ids'], 'catalog categories replace the client list');
mailblaze_assert_same('19.50', $view['price'], 'catalog price is normalised');
mailblaze_assert_same('19.50', $view['value'], 'ticket value matches price');
mailblaze_assert_same('ZAR', $view['currency'], 'currency is normalised');
mailblaze_assert_same($stamp, $view['created_at'], 'created_at is the event time');
mailblaze_assert_same($stamp, $view['time'], 'time repeats created_at');
mailblaze_assert_same('55', $view['store_id'], 'store id');
mailblaze_assert_null($view['subscriber_id'], 'subscriber id is left for commerce');
mailblaze_assert_same('product', $view['page'], 'product page');

$category = mailblaze_wc_prepare_view_batch('granted', $context, array(array(
    'page' => 'category',
    'product_id' => '15',
    'category_ids' => array('7'),
    'email' => 'evil@example.com',
)), $catalog, array(), $now);
mailblaze_assert_same('category', $category['views'][0]['page'], 'category page');
mailblaze_assert_null($category['views'][0]['product_id'], 'category view has no product id');
mailblaze_assert_null($category['views'][0]['price'], 'category view has no price');
mailblaze_assert_same(array('7'), $category['views'][0]['category_ids'], 'category ids');

$throttle_key = $prepared['keys'][0];
$throttled = mailblaze_wc_prepare_view_batch('granted', $context, array($client_view), $catalog, array(
    $throttle_key => $now + 10,
), $now);
mailblaze_assert_null($throttled, 'a recent view is not sent again');

class Mailblaze_View_Throttle_Probe {
    public $blocked_key = '';

    public function is_view_throttled($key) {
        return $key === $this->blocked_key;
    }
}

$probe = new Mailblaze_View_Throttle_Probe();
$probe->blocked_key = $throttle_key;
$callable_throttled = mailblaze_wc_select_views(array($view), array($probe, 'is_view_throttled'), $now);
mailblaze_assert_same(array(), $callable_throttled['views'], 'an array callable throttle is invoked');
$probe->blocked_key = 'mb_view_other';
$callable_open = mailblaze_wc_select_views(array($view), array($probe, 'is_view_throttled'), $now);
mailblaze_assert_same(1, count($callable_open['views']), 'a callable throttle allows a different view');

$many = array();
for ($i = 1; $i <= 12; $i++) {
    $many[] = array('page' => 'product', 'product_id' => (string) $i);
}
$capped = mailblaze_wc_prepare_view_batch('granted', $context, $many, $catalog, array(), $now);
mailblaze_assert_same(10, count($capped['views']), 'a beacon is capped at 10 views');
mailblaze_assert_same('1', $capped['views'][0]['product_id'], 'cap keeps the earliest views');

$duplicate = mailblaze_wc_prepare_view_batch('granted', $context, array($client_view, $client_view), $catalog, array(), $now);
mailblaze_assert_same(1, count($duplicate['views']), 'the same view is sent once per batch');

$with_email = mailblaze_wc_prepare_view_batch('granted', array_merge($context, array(
    'email' => 'Shopper@Example.com',
)), array($client_view), $catalog, array(), $now);
mailblaze_assert_same('shopper@example.com', $with_email['views'][0]['email'], 'a known email is lowercased on the event');

$link = mailblaze_wc_visitor_link_event(array(
    'anon_id' => $anon,
    'email' => 'Shopper@Example.com',
    'source' => 'checkout',
    'store_id' => '55',
    'list_uid' => 'listuid',
    'mbclid' => 'v1.camp.sub.hash.1.aaaaaaaaaaaaaaaa',
    'created_at' => $stamp,
));
mailblaze_assert_same('ecommerce.visitor.linked', $link['event'], 'link event name');
mailblaze_assert_same('shopper@example.com', $link['email'], 'link email');
mailblaze_assert_same('checkout', $link['source'], 'checkout source');
mailblaze_assert_same('v1.camp.sub.hash.1.aaaaaaaaaaaaaaaa', $link['mbclid'], 'mbclid is forwarded without an HMAC check');
mailblaze_assert_null(mailblaze_wc_visitor_link_event(array(
    'anon_id' => $anon,
    'email' => 'not-an-email',
    'mbclid' => 'has a space',
)), 'a link with no email and no usable click id is dropped');
$click_only = mailblaze_wc_visitor_link_event(array(
    'anon_id' => $anon,
    'email' => '',
    'mbclid' => 'click.without.hmac',
    'source' => 'mbclid',
    'created_at' => $stamp,
));
mailblaze_assert_null($click_only['email'], 'click stitch can omit email');
mailblaze_assert_same('mbclid', $click_only['source'], 'click source');
mailblaze_assert_same('click.without.hmac', $click_only['mbclid'], 'unsigned click id is still forwarded');

mailblaze_assert_same(true, mailblaze_wc_newsletter_optin_links('yes'), 'a ticked newsletter box can stitch');
mailblaze_assert_same(false, mailblaze_wc_newsletter_optin_links('no'), 'an unticked newsletter box does not stitch');
mailblaze_assert_same(false, mailblaze_wc_newsletter_optin_links(''), 'a missing newsletter choice does not stitch');
$optin_src = file_get_contents(dirname(__DIR__) . '/includes/optin-handler.php');
mailblaze_assert_true(strpos($optin_src, 'mailblaze_wc_newsletter_optin_links($new_optin)') !== false, 'account opt-in uses the ticked-box gate');
mailblaze_assert_true(strpos($optin_src, 'mailblaze_wc_newsletter_optin_links($checkout_optin)') !== false, 'checkout opt-in uses the ticked-box gate');
mailblaze_assert_true(strpos($optin_src, 'mailblaze_wc_posted_optin($posted_optin)') !== false, 'account opt-in checks the posted value');
mailblaze_assert_true(strpos($optin_src, 'mailblaze_wc_posted_optin($posted_checkout_optin)') !== false, 'checkout opt-in checks the posted value');
mailblaze_assert_same('no', mailblaze_wc_posted_optin('no'), 'posted mailblaze_optin=no is not consent');
mailblaze_assert_same('no', mailblaze_wc_posted_optin('No'), 'posted opt-in no is case-insensitive');
mailblaze_assert_same('yes', mailblaze_wc_posted_optin('yes'), 'posted yes is consent');
mailblaze_assert_same('yes', mailblaze_wc_posted_optin('1'), 'the Woo checkbox value 1 is consent');
mailblaze_assert_same('yes', mailblaze_wc_posted_optin(1), 'a numeric checkbox 1 is consent');
mailblaze_assert_same('no', mailblaze_wc_posted_optin('0'), 'an unticked value 0 is not consent');
mailblaze_assert_same('no', mailblaze_wc_posted_optin(null), 'a missing field is not consent');
mailblaze_assert_same('no', mailblaze_wc_posted_optin(array('yes')), 'an array post is not consent');
$newsletter_key = mailblaze_wc_link_throttle_key($anon, 'shopper@example.com', null, 'newsletter');
$login_key = mailblaze_wc_link_throttle_key($anon, 'shopper@example.com', null, 'login');
mailblaze_assert_true($newsletter_key !== $login_key, 'the link throttle key includes source');
mailblaze_assert_same(
    $newsletter_key,
    mailblaze_wc_link_throttle_key($anon, 'Shopper@Example.com', null, 'newsletter'),
    'the same source and email share one throttle key'
);

mailblaze_assert_null(mailblaze_wc_browse_url('https://commerce.test/api/hooks/', 'nope'), 'unknown browse route');
$product_url = mailblaze_wc_browse_url('https://commerce.test/api/hooks/', 'product-viewed');
$link_url = mailblaze_wc_browse_url('https://commerce.test/api/hooks', 'visitor-linked');
mailblaze_assert_same('https://commerce.test/api/hooks/ecommerce/product-viewed', $product_url, 'product view endpoint');
mailblaze_assert_same('https://commerce.test/api/hooks/ecommerce/visitor-linked', $link_url, 'visitor link endpoint');
mailblaze_assert_true(strpos($product_url, '?') === false && strpos($product_url, 'shopper@example.com') === false, 'browse URL has no query and no email');

mailblaze_assert_same(403, mailblaze_wc_ingest_status(false, 'granted', 1), 'bad nonce is rejected');
mailblaze_assert_same(204, mailblaze_wc_ingest_status(true, 'unknown', 1), 'unknown consent records nothing');
mailblaze_assert_same(204, mailblaze_wc_ingest_status(true, 'granted', 0), 'an empty batch records nothing');
mailblaze_assert_same(202, mailblaze_wc_ingest_status(true, 'granted', 1), 'a sent view is accepted');
mailblaze_assert_same(false, mailblaze_wc_rate_allow(30, 30), 'rate limit blocks the 31st call');
mailblaze_assert_same(true, mailblaze_wc_rate_allow(29, 30), 'rate limit allows the 30th call');
$ip_only = mailblaze_wc_rate_limit_key('203.0.113.5', '');
$with_anon = mailblaze_wc_rate_limit_key('203.0.113.5', $anon);
$other_visitor = mailblaze_wc_rate_limit_key('203.0.113.5', $other);
mailblaze_assert_true($ip_only !== $with_anon, 'the rate limit key includes the anon id');
mailblaze_assert_true($with_anon !== $other_visitor, 'two visitors behind one address do not share a bucket');
mailblaze_assert_same($ip_only, mailblaze_wc_rate_limit_key('203.0.113.5', 'not-a-uuid'), 'an invalid anon id does not open a new bucket');
$minted_buckets = mailblaze_wc_rate_buckets('203.0.113.5', $anon, true);
$stable_buckets = mailblaze_wc_rate_buckets('203.0.113.5', $anon, false);
mailblaze_assert_same(array($ip_only), array_values($minted_buckets), 'a just-minted id uses only the address ceiling');
mailblaze_assert_same(array($ip_only, $with_anon), array_values($stable_buckets), 'a returning visitor has an address ceiling and a per-id bucket');
mailblaze_assert_same(
    array_values($minted_buckets),
    array_values(mailblaze_wc_rate_buckets('203.0.113.5', $other, true)),
    'rotating minted ids share one address ceiling'
);
mailblaze_assert_same(array($ip_only), array_values(mailblaze_wc_rate_buckets('203.0.113.5', 'not-a-uuid', false)), 'junk ids do not open a per-id bucket');
mailblaze_assert_true(mailblaze_wc_rate_blocked(array(30, 0), 30), 'the address ceiling blocks a fresh id');
mailblaze_assert_same(false, mailblaze_wc_rate_blocked(array(29, 29), 30), 'under both caps is allowed');
mailblaze_assert_true(mailblaze_wc_rate_blocked(array(1, 30), 30), 'the per-id bucket still caps one visitor');
$rate_src = file_get_contents(dirname(__DIR__) . '/includes/class-mailblaze-view-tracking.php');
mailblaze_assert_true(strpos($rate_src, 'mailblaze_wc_rate_buckets($ip, $anon, $minted)') !== false, 'ingest applies the address ceiling and the per-id bucket');
mailblaze_assert_true(strpos($rate_src, 'MailBlaze_Visitor::anon_id_was_minted()') !== false, 'a cookie minted in this request is not its own bucket');

$js = file_get_contents(dirname(__DIR__) . '/assets/js/mailblaze-views.js');
mailblaze_assert_true(strpos($js, "method: 'POST'") !== false, 'the beacon is a POST');
mailblaze_assert_true(strpos($js, "credentials: 'same-origin'") !== false, 'the beacon stays on this site');
mailblaze_assert_true(strpos($js, 'cfg.endpoint') !== false, 'the beacon uses the localized endpoint');
mailblaze_assert_true(strpos($js, 'found_variation') !== false, 'variation changes are tracked');
mailblaze_assert_true(strpos($js, 'slice(0, 10)') !== false, 'the browser batches at most 10 views');
mailblaze_assert_true(strpos($js, 'email') === false, 'the browser script does not handle email');
mailblaze_assert_true(strpos($js, 'mb_anon_id') === false, 'the browser script does not read the anon cookie');
mailblaze_assert_true(strpos($js, 'URLSearchParams') === false, 'the browser script does not build a query string');
mailblaze_assert_true(preg_match('/[?&][A-Za-z0-9_]+=/', $js) !== 1, 'the browser script does not append query parameters');
mailblaze_assert_true(strpos($js, '.catch(') !== false, 'a failed beacon is retried');

function get_option($key, $default = false) {
    if ($key === 'mailblaze_wc_foreign_store_id') {
        return 'wc_test_store';
    }
    return $default;
}
function get_site_url() {
    return 'https://shop.example';
}
function wp_json_encode($data) {
    return json_encode($data);
}
function is_wp_error($thing) {
    return is_object($thing) && !empty($thing->mb_error);
}
function wp_remote_retrieve_response_code($response) {
    return $response['response']['code'];
}
function wp_remote_retrieve_body($response) {
    return $response['body'];
}
function wp_remote_request($url, $args) {
    $GLOBALS['mb_requests'][] = array('url' => $url, 'args' => $args);
    if (!empty($GLOBALS['mb_response_error'])) {
        return (object) array('mb_error' => true);
    }
    $code = isset($GLOBALS['mb_response_code']) ? (int) $GLOBALS['mb_response_code'] : 200;
    return array(
        'response' => array('code' => $code),
        'body' => '{"ok":true}',
    );
}

putenv('MAILBLAZE_HOOKS_API_BASE=https://commerce.test/api/hooks/');
putenv('MAILBLAZE_ECOMMERCE_API_BASE=https://commerce.test/api');
$GLOBALS['mb_requests'] = array();

require dirname(__DIR__) . '/includes/api-client.php';

$client = new Mailblaze_WC_API_Client('test-key');
$sent_views = $client->send_product_views(array('views' => $with_email['views']));
$sent_link = $client->send_visitor_link($link);
$sent_order = $client->send_event('order_created', array('FIELD_ORDER_ID' => '1'));
mailblaze_assert_same(array('dispatched' => true), $sent_views, 'mocked product view HTTP is dispatched');
mailblaze_assert_same(array('dispatched' => true), $sent_link, 'mocked link HTTP is dispatched');
mailblaze_assert_same(array('ok' => true), $sent_order, 'mocked order event HTTP still returns the body');

$view_request = $GLOBALS['mb_requests'][0];
$link_request = $GLOBALS['mb_requests'][1];
mailblaze_assert_same('https://commerce.test/api/hooks/ecommerce/product-viewed', $view_request['url'], 'client posts product views to the named endpoint');
mailblaze_assert_same('https://commerce.test/api/hooks/ecommerce/visitor-linked', $link_request['url'], 'client posts the link to the named endpoint');
mailblaze_assert_same('POST', $view_request['args']['method'], 'product views use POST');
mailblaze_assert_true(strpos($view_request['url'], '?') === false, 'product view URL has no query string');
mailblaze_assert_true(strpos($link_request['url'], 'shopper@example.com') === false, 'link URL has no email');
mailblaze_assert_true(strpos($view_request['url'], 'test-key') === false, 'api key stays out of the URL');
mailblaze_assert_same('Bearer test-key', $view_request['args']['headers']['Authorization'], 'hooks auth is a bearer token');
mailblaze_assert_same('wc_test_store', $view_request['args']['headers']['X-Store-Id'], 'foreign store id is the header');
mailblaze_assert_same(false, $view_request['args']['blocking'], 'product view posts do not block the shopper');
mailblaze_assert_same(2, $view_request['args']['timeout'], 'product view posts use a short timeout');
mailblaze_assert_same(false, $link_request['args']['blocking'], 'link posts do not block the shopper');
mailblaze_assert_same(2, $link_request['args']['timeout'], 'link posts use a short timeout');
$order_request = $GLOBALS['mb_requests'][2];
mailblaze_assert_same(30, $order_request['args']['timeout'], 'order events keep the 30 second timeout');
mailblaze_assert_true(!array_key_exists('blocking', $order_request['args']), 'order events stay blocking');

mailblaze_assert_null(mailblaze_wc_browse_http_error(0), 'non-blocking calls often have no status');
mailblaze_assert_null(mailblaze_wc_browse_http_error(204), 'a 2xx is not an HTTP error');
mailblaze_assert_same(400, mailblaze_wc_browse_http_error(400), 'a visible 4xx is logged as an error');
mailblaze_assert_same(500, mailblaze_wc_browse_http_error(500), 'a visible 5xx is logged as an error');
$api_src = file_get_contents(dirname(__DIR__) . '/includes/api-client.php');
mailblaze_assert_true(strpos($api_src, 'blocking false usually returns before a status exists') !== false, 'the client notes that non-blocking posts often have no status');
mailblaze_assert_true(strpos($api_src, "error_log('Mailblaze browse HTTP '") !== false, 'a visible 4xx or 5xx is written to the log');

$log_file = tempnam(sys_get_temp_dir(), 'mb-http-');
ini_set('error_log', $log_file);
$GLOBALS['mb_response_code'] = 0;
$sent_unstatused = $client->send_product_views(array('views' => $with_email['views']));
mailblaze_assert_same(array('dispatched' => true), $sent_unstatused, 'a non-blocking post with no status still counts as dispatched');

$GLOBALS['mb_response_code'] = 500;
$saw_http_error = false;
try {
    $client->send_visitor_link($link);
} catch (Exception $http_error) {
    $saw_http_error = $http_error->getMessage() === 'Failed to send visitor link to Mailblaze API';
}
mailblaze_assert_true($saw_http_error, 'a visible 5xx fails the browse post');
$http_log = file_get_contents($log_file);
mailblaze_assert_true(strpos($http_log, 'Mailblaze browse HTTP 500') !== false, 'a visible 5xx is logged without the response body');
mailblaze_assert_true(strpos($http_log, 'shopper@example.com') === false, 'browse HTTP logs do not include the email');

$GLOBALS['mb_response_code'] = 400;
$saw_client_error = false;
try {
    $client->send_product_views(array('views' => $with_email['views']));
} catch (Exception $client_error) {
    $saw_client_error = $client_error->getMessage() === 'Failed to send product views to Mailblaze API';
}
mailblaze_assert_true($saw_client_error, 'a visible 4xx fails the browse post');
$http_log = file_get_contents($log_file);
mailblaze_assert_true(strpos($http_log, 'Mailblaze browse HTTP 400') !== false, 'a visible 4xx is logged');

$GLOBALS['mb_response_code'] = 200;
$GLOBALS['mb_response_error'] = true;
$saw_transport_error = false;
try {
    $client->send_product_views(array('views' => $with_email['views']));
} catch (Exception $transport_error) {
    $saw_transport_error = $transport_error->getMessage() === 'Failed to send product views to Mailblaze API';
}
mailblaze_assert_true($saw_transport_error, 'a transport error fails the browse post');
$http_log = file_get_contents($log_file);
mailblaze_assert_true(strpos($http_log, 'Mailblaze browse request failed before a response') !== false, 'a transport error is logged');
unlink($log_file);

$decoded = json_decode($view_request['args']['body'], true);
mailblaze_assert_same('shopper@example.com', $decoded['views'][0]['email'], 'email is in the JSON body');
mailblaze_assert_same('ecommerce.product.viewed', $decoded['views'][0]['event'], 'posted event name');
$decoded_link = json_decode($link_request['args']['body'], true);
mailblaze_assert_same('ecommerce.visitor.linked', $decoded_link['event'], 'posted link event');
mailblaze_assert_same($anon, $decoded_link['anon_id'], 'posted link anon id');

echo "ok\n";
