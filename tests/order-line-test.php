<?php
require dirname(__DIR__) . '/includes/order-line.php';

function mailblaze_assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . " expected " . var_export($expected, true) . " got " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$variation = mailblaze_wc_line_identity(42, 99, 'Serum - Large', 'Serum');
mailblaze_assert_same(42, $variation['product_id'], 'variation line uses the parent product id');
mailblaze_assert_same(99, $variation['variation_id'], 'variation id is kept separately');
mailblaze_assert_same('Serum - Large', $variation['name'], 'line name keeps the attributes');
mailblaze_assert_same('Serum', $variation['parent_name'], 'catalog title is the name fallback');

$simple = mailblaze_wc_line_identity(7, 0, 'Serum', '');
mailblaze_assert_same(7, $simple['product_id'], 'simple product id is unchanged');
mailblaze_assert_same('Serum', $simple['parent_name'], 'simple product falls back to its own name');

echo "ok\n";
