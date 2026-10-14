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

if ($failures > 0) {
    fwrite(STDERR, $failures . " assertion(s) failed\n");
    exit(1);
}

echo "all assertions passed\n";
