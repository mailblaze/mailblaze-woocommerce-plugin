<?php
if (!function_exists('mailblaze_wc_line_identity')) {
    /**
     * Identity used to match a purchased product.
     * product_id is the parent catalog id. variation lines keep the attribute
     * title in name, and parent_name is the catalog title used only when the
     * id does not match.
     */
    function mailblaze_wc_line_identity($product_id, $variation_id, $item_name, $parent_name) {
        $catalog_name = is_string($parent_name) && $parent_name !== '' ? $parent_name : $item_name;
        return array(
            'product_id' => $product_id,
            'variation_id' => $variation_id ? (int) $variation_id : 0,
            'name' => $item_name,
            'parent_name' => $catalog_name,
        );
    }
}
