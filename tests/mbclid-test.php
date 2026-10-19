<?php
/**
 * mbclid format checks against the vector shared by
 * mailblaze-commerce (merge c5ad673, src/conversion/mbclid.spec.ts) and
 * mailblaze-tracking (95cefd3, src/actions/mbclid_test.go).
 *
 * Run: php tests/mbclid-test.php
 */

define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/includes/mbclid.php';

$failures = 0;

function expect($label, $condition) {
    global $failures;
    if ($condition) {
        echo "ok  $label\n";
        return;
    }
    $failures++;
    echo "FAIL $label\n";
}

function mailblaze_wc_issue_mbclid($campaign, $subscriber, $url_hash, $unix, $secret) {
    $message = implode('|', array('v1', $campaign, $subscriber, $url_hash, (string) $unix));
    $sig = substr(hash_hmac('sha256', $message, $secret), 0, 16);
    return 'v1.' . $campaign . '.' . $subscriber . '.' . $url_hash . '.' . $unix . '.' . $sig;
}

$secret = 'mailblaze-local-mbclid-dev-secret';
$message = implode('|', array(
    'v1',
    'campuid12345',
    '507f1f77bcf86cd799439011',
    'abc123',
    '1700000000',
));
$sig = substr(hash_hmac('sha256', $message, $secret), 0, 16);
$recomputed = 'v1.campuid12345.507f1f77bcf86cd799439011.abc123.1700000000.' . $sig;
$published = 'v1.campuid12345.507f1f77bcf86cd799439011.abc123.1700000000.b906dbdd62d7f298';

expect('recomputed HMAC matches the published vector', $recomputed === $published);
expect('published vector is accepted unchanged', mailblaze_wc_normalize_mbclid($published) === $published);
expect('surrounding whitespace is trimmed', mailblaze_wc_normalize_mbclid("  $published\n") === $published);
expect('sha1-length url hash is accepted', mailblaze_wc_normalize_mbclid('v1.campuid12345.507f1f77bcf86cd799439011.' . str_repeat('ab', 20) . '.1700000000.b906dbdd62d7f298') !== null);
expect('empty url hash still has six fields', mailblaze_wc_normalize_mbclid('v1.camp.sub..1.aaaaaaaaaaaaaaaa') === 'v1.camp.sub..1.aaaaaaaaaaaaaaaa');

expect('rejects wrong version', mailblaze_wc_normalize_mbclid('v2.campuid12345.507f1f77bcf86cd799439011.abc123.1700000000.b906dbdd62d7f298') === null);
expect('rejects five fields', mailblaze_wc_normalize_mbclid('v1.camp.sub.1.aaaaaaaaaaaaaaaa') === null);
expect('rejects a dot inside a field', mailblaze_wc_normalize_mbclid('v1.camp.uid.507f1f77bcf86cd799439011.abc123.1700000000.b906dbdd62d7f298') === null);
expect('rejects a non-dot before the unix field', mailblaze_wc_normalize_mbclid('v1.campuid12345.507f1f77bcf86cd799439011.abc123X1700000000.b906dbdd62d7f298') === null);
expect('rejects uppercase signature', mailblaze_wc_normalize_mbclid('v1.camp.sub.hash.1.AAAAAAAAAAAAAAAA') === null);
expect('rejects a 15-char signature', mailblaze_wc_normalize_mbclid('v1.camp.sub.hash.1.aaaaaaaaaaaaaaa') === null);
expect('rejects the short URL fixture signature', mailblaze_wc_normalize_mbclid('v1.camp.sub.hash.1.sig') === null);
expect('rejects empty campaign', mailblaze_wc_normalize_mbclid('v1..sub.hash.1.aaaaaaaaaaaaaaaa') === null);
expect('rejects non-decimal unix', mailblaze_wc_normalize_mbclid('v1.camp.sub.hash.1e10.aaaaaaaaaaaaaaaa') === null);
expect('rejects spaces inside a field', mailblaze_wc_normalize_mbclid('v1.camp uid.sub.hash.1.aaaaaaaaaaaaaaaa') === null);
expect('rejects empty string', mailblaze_wc_normalize_mbclid('') === null);
expect('rejects non-string', mailblaze_wc_normalize_mbclid(null) === null);

$other = mailblaze_wc_issue_mbclid('othercamp', '507f1f77bcf86cd799439011', 'abc123', 1700000000, $secret);
$tampered = substr($published, 0, -1) . (substr($published, -1) === '8' ? '0' : '8');

expect('issued token signature matches', mailblaze_wc_mbclid_signature_valid($published, $secret));
expect('tampered signature does not verify', mailblaze_wc_mbclid_signature_valid($tampered, $secret) === false);

expect('invalid format does not replace a stored token', mailblaze_wc_resolve_captured_mbclid('not-a-token', $published, $secret) === $published);
expect('invalid signature does not replace a stored token', mailblaze_wc_resolve_captured_mbclid($tampered, $published, $secret) === $published);
expect('a new valid click replaces the stored token', mailblaze_wc_resolve_captured_mbclid($other, $published, $secret) === $other);
expect('invalid format with nothing stored stays empty', mailblaze_wc_resolve_captured_mbclid('not-a-token', null, $secret) === null);

$order_meta = $published;
$session_token = $other;
expect(
    'status event uses the order meta, not the session',
    mailblaze_wc_mbclid_for_existing_order($order_meta, $session_token) === $order_meta
);
expect(
    'status event with no order meta does not borrow the session',
    mailblaze_wc_mbclid_for_existing_order('', $session_token) === null
);

$attached = mailblaze_wc_attach_session_mbclid('', $published, $secret);
expect('checkout attach copies the session token onto the order', $attached['meta'] === $published && $attached['clear_session'] === true);
$kept = mailblaze_wc_attach_session_mbclid($published, $other, $secret);
expect('existing order meta is not replaced by the session', $kept['meta'] === $published && $kept['clear_session'] === false);
$rejected = mailblaze_wc_attach_session_mbclid('', $tampered, $secret);
expect('checkout does not attach an invalid signature', $rejected['meta'] === null && $rejected['clear_session'] === false);

$with_token = mailblaze_wc_with_mbclid_field(array('list_uid' => 'list'), $published);
$without_token = mailblaze_wc_with_mbclid_field(array('list_uid' => 'list', 'FIELD_MBCLID' => null), null);
expect('payload includes FIELD_MBCLID when a token exists', $with_token['FIELD_MBCLID'] === $published);
expect('payload omits FIELD_MBCLID when there is no token', array_key_exists('FIELD_MBCLID', $without_token) === false);

class Mailblaze_WC_Fake_Session {
    public $data = array();
    public function get($key) {
        return array_key_exists($key, $this->data) ? $this->data[$key] : null;
    }
    public function set($key, $value) {
        $this->data[$key] = $value;
    }
}
class Mailblaze_WC_Fake_Woo {
    public $session;
}
class Mailblaze_WC_Fake_Order {
    public $meta = array();
    public $saves = 0;
    public $email = 'shopper@example.com';
    public function get_meta($key) {
        return array_key_exists($key, $this->meta) ? $this->meta[$key] : '';
    }
    public function update_meta_data($key, $value) {
        $this->meta[$key] = $value;
    }
    public function save() {
        $this->saves++;
    }
    public function get_billing_email() {
        return $this->email;
    }
}
function WC() {
    global $mailblaze_wc_fake;
    return $mailblaze_wc_fake;
}

$mailblaze_wc_current_action = '';
function doing_action($hook) {
    global $mailblaze_wc_current_action;
    return $mailblaze_wc_current_action === $hook;
}

class MailBlaze_Visitor {
    public static $clicks = array();
    public static $links = array();
    public static function link_click($mbclid) {
        self::$clicks[] = $mbclid;
    }
    public static function link_email($email, $source = 'email', $mbclid = null) {
        $session = (function_exists('WC') && WC() && WC()->session) ? WC()->session->get('mailblaze_mbclid') : null;
        self::$links[] = array($email, $source, $mbclid, $session);
    }
}

$previous_mbclid_secret = getenv('MBCLID_SECRET');
putenv('MBCLID_SECRET');

mailblaze_wc_link_captured_click($published);
expect('capture hands the accepted token to link_click', MailBlaze_Visitor::$clicks === array($published));
mailblaze_wc_link_captured_click('');
mailblaze_wc_link_captured_click(null);
expect('an empty handoff does not call link_click', count(MailBlaze_Visitor::$clicks) === 1);

$mailblaze_wc_fake = new Mailblaze_WC_Fake_Woo();
$mailblaze_wc_fake->session = new Mailblaze_WC_Fake_Session();
$mailblaze_wc_fake->session->set('mailblaze_mbclid', $published);
$mailblaze_wc_current_action = 'woocommerce_checkout_order_processed';
unset($GLOBALS['mailblaze_wc_clear_mbclid_after_visitor']);
$first_order = new Mailblaze_WC_Fake_Order();
mailblaze_wc_persist_order_mbclid($first_order);
expect('checkout_order_processed writes _mailblaze_mbclid', isset($first_order->meta['_mailblaze_mbclid']) && $first_order->meta['_mailblaze_mbclid'] === $published);
expect('order meta is saved', $first_order->saves === 1);
expect(
    'checkout link_email runs while the session still holds the click id',
    MailBlaze_Visitor::$links[0] === array('shopper@example.com', 'checkout', $published, $published)
);
expect('priority 10 leaves the session for the priority 20 visitor link', $mailblaze_wc_fake->session->get('mailblaze_mbclid') === $published);
$visitor_would_read = $mailblaze_wc_fake->session->get('mailblaze_mbclid');
expect('priority 20 checkout visitor link still sees the click id', $visitor_would_read === $published);
mailblaze_wc_clear_mbclid_session_after_visitor();
expect('priority 30 clears the session after the visitor link', $mailblaze_wc_fake->session->get('mailblaze_mbclid') === null);
expect('the clear flag is consumed', empty($GLOBALS['mailblaze_wc_clear_mbclid_after_visitor']));

$mailblaze_wc_current_action = '';
$second_order = new Mailblaze_WC_Fake_Order();
mailblaze_wc_persist_order_mbclid($second_order);
expect('the next order does not inherit the cleared session', $second_order->get_meta('_mailblaze_mbclid') === '');
expect('a cleared session does not save empty meta', $second_order->saves === 0);

$mailblaze_wc_fake->session->set('mailblaze_mbclid', $other);
mailblaze_wc_persist_order_mbclid($first_order);
expect('a later session token does not overwrite order meta', $first_order->meta['_mailblaze_mbclid'] === $published);
expect('a later session token stays for the next order', $mailblaze_wc_fake->session->get('mailblaze_mbclid') === $other);
expect('keeping an existing order meta does not ask for a session clear', empty($GLOBALS['mailblaze_wc_clear_mbclid_after_visitor']));

$mailblaze_wc_fake->session->set('mailblaze_mbclid', $published);
$thankyou_order = new Mailblaze_WC_Fake_Order();
$links_before_thankyou = count(MailBlaze_Visitor::$links);
mailblaze_wc_persist_order_mbclid($thankyou_order);
expect(
    'outside checkout the handoff still happens before the session clear',
    MailBlaze_Visitor::$links[$links_before_thankyou] === array('shopper@example.com', 'checkout', $published, $published)
);
expect('a thank-you attach clears the session in that same call', $mailblaze_wc_fake->session->get('mailblaze_mbclid') === null);

$integration = file_get_contents(dirname(__DIR__) . '/mailblaze-woocommerce-integration.php');
$visitor_source = file_get_contents(dirname(__DIR__) . '/includes/class-mailblaze-visitor.php');
$readme = file_get_contents(dirname(__DIR__) . '/readme.txt');
expect(
    'meta is written from woocommerce_checkout_order_processed at priority 10',
    strpos($integration, "add_action('woocommerce_checkout_order_processed', [\$this, 'persist_mbclid_on_checkout'], 10, 3);") !== false
);
expect(
    'store api persist stays at priority 10',
    strpos($integration, "add_action('woocommerce_store_api_checkout_order_processed', [\$this, 'persist_mbclid_on_checkout'], 10, 1);") !== false
);
expect(
    'classic checkout clear is priority 30 with no arguments',
    strpos($integration, "add_action('woocommerce_checkout_order_processed', 'mailblaze_wc_clear_mbclid_session_after_visitor', 30, 0);") !== false
);
expect(
    'store api clear is priority 30 with no arguments',
    strpos($integration, "add_action('woocommerce_store_api_checkout_order_processed', 'mailblaze_wc_clear_mbclid_session_after_visitor', 30, 0);") !== false
);
expect(
    'capture calls the visitor link_click handoff',
    strpos($integration, 'mailblaze_wc_link_captured_click($mbclid);') !== false
);
expect(
    'checkout visitor link stays at priority 20',
    strpos($visitor_source, "add_action('woocommerce_checkout_order_processed', array('MailBlaze_Visitor', 'on_checkout'), 20, 1);") !== false
    && strpos($visitor_source, "add_action('woocommerce_store_api_checkout_order_processed', array('MailBlaze_Visitor', 'on_checkout_order'), 20, 1);") !== false
);
expect(
    'priority 20 reads the session click id',
    strpos($visitor_source, "\$mbclid = \$wc->session->get('mailblaze_mbclid');") !== false
);
expect('plugin header is 1.2.0', strpos($integration, ' * Version:     1.2.0') !== false);
expect('readme stable tag is 1.2.0', strpos($readme, "Stable tag: 1.2.0\n") !== false);
expect('changelog lists mbclid and the update service', strpos($readme, '= 1.2.0 =') !== false && strpos($readme, 'mbclid') !== false && strpos($readme, 'GitHub releases') !== false);

if ($previous_mbclid_secret === false) {
    putenv('MBCLID_SECRET');
} else {
    putenv('MBCLID_SECRET=' . $previous_mbclid_secret);
}

if ($failures > 0) {
    fwrite(STDERR, $failures . " assertion(s) failed\n");
    exit(1);
}

echo "all assertions passed\n";
