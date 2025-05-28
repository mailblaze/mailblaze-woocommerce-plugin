<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mailblaze_WC_Event_Handler {
    private $api_client;
    private $enabled_hooks;
    private $store_id;
    private $mailing_list_id;

    public function __construct() {
        $api_key = get_option( 'mailblaze_wc_api_key', '' );
        $this->store_id = get_option( 'mailblaze_wc_store_id', '' );
        $this->mailing_list_id = get_option( 'mailblaze_wc_mailing_list', '' );
        $this->enabled_hooks = get_option( 'mailblaze_wc_enabled_hooks', [] );
        
        if ( ! empty( $api_key ) ) {
            $this->api_client = new Mailblaze_WC_API_Client( $api_key );
            $this->init_hooks();
            
        }
    }

    private function init_hooks() {
        // Existing hooks
        if ( in_array( 'new_order', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_thankyou', [ $this, 'handle_new_order' ] );
        }

        if ( in_array( 'order_status_changed', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_changed' ], 10, 4 );
        }

        if ( in_array( 'user_register', $this->enabled_hooks ) ) {
            // add_action( 'user_register', [ $this, 'handle_user_register' ] );
        }

        // New hooks
        if ( in_array( 'product_purchase', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_order_status_completed', [ $this, 'handle_product_purchase' ] );
        }

        if ( in_array( 'cart_abandoned', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_cart_emptied', [ $this, 'handle_cart_emptied_for_id_clear' ] );
        }

        if ( in_array( 'coupon_used', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_applied_coupon', [ $this, 'handle_coupon_used' ] );
        }

        if ( in_array( 'subscription_created', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_subscription_payment_complete', [ $this, 'handle_subscription_created' ] );
        }

        if ( in_array( 'subscription_cancelled', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_subscription_status_cancelled', [ $this, 'handle_subscription_cancelled' ] );
        }

        // Hooks for cart_created and cart_updated
        if ( in_array( 'cart_created', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_add_to_cart', [ $this, 'handle_cart_created' ], 10, 6 );
        }

        if ( in_array( 'cart_updated', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_cart_item_removed', [ $this, 'handle_cart_updated' ], 10, 0 );
            add_action( 'woocommerce_after_cart_item_quantity_update', [ $this, 'handle_cart_updated' ], 10, 0 );
        }
    }

    public function handle_new_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->get_billing_email() ) {
            return; // Need order and email to proceed
        }

        $cart_id_from_session = WC()->session ? WC()->session->get('mailblaze_cart_id') : null;

        $data = [
            // event_type is added by send_event method in Mailblaze_WC_API_Client
            'FIELD_TOTAL' => $order->get_total(),
            'FIELD_ORDER_REF' => $order->get_order_number(),
            'FIELD_ORDER_ITEMS' => $this->get_order_items($order),
            'FIELD_CUSTOMER_EMAIL' => $order->get_billing_email(),
            'FIELD_SHIPPING_ADDRESS' => $this->get_shipping_address($order),
            'FIELD_PAYMENT_METHOD' => $order->get_payment_method_title(),
            'FIELD_CURRENCY' => $order->get_currency(),
            'list_uid' => $this->mailing_list_id,
            'FIELD_CART_ID' => $cart_id_from_session // Added cart ID from session
        ];

        try {
            $this->api_client->send_event('order_created', $data);
            // Clear cart ID after successful order creation event is sent (assuming 'thankyou' means order is sufficiently processed)
            if (WC()->session && $cart_id_from_session) {
                WC()->session->set('mailblaze_cart_id', null);
                // error_log('Mailblaze: Cleared mailblaze_cart_id (new order) ' . $order_id);
            }
        } catch (Exception $e) {
            error_log('Mailblaze Integration Error (handle_new_order): ' . $e->getMessage());
        }
    }

    public function handle_order_status_changed( $order_id, $old_status, $new_status, $order ) {
        if (!$order || !$order->get_billing_email()) {
            return;
        }
        
        $order_data = $this->prepare_order_data( $order );
        $cart_id_from_session = WC()->session ? WC()->session->get('mailblaze_cart_id') : null;
        
        $data = [
            'old_status' => $old_status,
            'new_status' => $new_status,
            'order' => $order_data, // This contains FIELD_ORDER_ID etc.
            // FIELD_CUSTOMER_EMAIL is now part of the nested 'order' structure, but also kept at top level for direct access
            'FIELD_CUSTOMER_EMAIL' => $order->get_billing_email(),
            'list_uid' => $this->mailing_list_id,
            'FIELD_CART_ID' => $cart_id_from_session // Added cart ID from session
        ];
        
        try {
            $this->api_client->send_event('order_status_changed', $data);
            // If order is considered complete, clear the Mailblaze cart ID from session
            // Typical completion statuses: 'completed', 'processing'
            $completed_statuses = apply_filters('mailblaze_wc_completed_order_statuses', ['completed', 'processing']);
            if (in_array($new_status, $completed_statuses) && WC()->session && $cart_id_from_session) {
                WC()->session->set('mailblaze_cart_id', null);
                // error_log('Mailblaze: Cleared mailblaze_cart_id (order status changed) ' . $order_id);
            }
        } catch (Exception $e) {
            error_log('Mailblaze Integration Error (handle_order_status_changed): ' . $e->getMessage());
        }
    }

    public function handle_user_register( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;
        $data = [
            'user' => $this->prepare_user_data( $user ),
            'FIELD_CUSTOMER_EMAIL' => $user->user_email,
            'list_uid' => $this->mailing_list_id
        ];
        try {
            $this->api_client->send_event('user_register', $data);
        } catch (Exception $e) {
            error_log('Mailblaze Integration Error (handle_user_register): ' . $e->getMessage());
        }
    }

    public function handle_product_purchase( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->get_billing_email() ) return;
        $data = [
            'order' => $this->prepare_order_data( $order ),
            'FIELD_CUSTOMER_EMAIL' => $order->get_billing_email(),
            'list_uid' => $this->mailing_list_id
        ];
        try {
            $this->api_client->send_event('product_purchase', $data);
        } catch (Exception $e) {
            error_log('Mailblaze Integration Error (handle_product_purchase): ' . $e->getMessage());
        }
    }

    public function handle_cart_emptied_for_id_clear() {
        if (WC()->session) {
            $current_cart_id = WC()->session->get('mailblaze_cart_id');
            if ($current_cart_id) {
                WC()->session->set('mailblaze_cart_id', null);
                // error_log('Mailblaze: Cleared mailblaze_cart_id from session due to cart emptied hook. Old ID: ' . $current_cart_id);
            }
        }
        // The original handle_cart_abandoned_hook logic might still be called if that specific hook ('cart_abandoned') is enabled
        // This function ensures the ID is cleared regardless, if any cart tracking is on.
    }

    public function handle_coupon_used( $coupon_code ) {
        $user = wp_get_current_user();
        if ( !$user || !$user->ID || !$user->user_email ) return; // Need logged-in user with email
        
        $coupon = new WC_Coupon( $coupon_code );
        $data = [
            'coupon_code' => $coupon_code,
            'discount'    => $coupon->get_amount(),
            'FIELD_CUSTOMER_EMAIL' => $user->user_email,
            'list_uid' => $this->mailing_list_id
        ];
        try {
            $this->api_client->send_event('coupon_used', $data);
        } catch (Exception $e) {
            error_log('Mailblaze Integration Error (handle_coupon_used): ' . $e->getMessage());
        }
    }

    public function handle_subscription_created( $subscription ) {
        $user = get_userdata( $subscription->get_customer_id() );
        if ( ! $user ) return;
        $data = [
            'subscription' => $this->prepare_subscription_data( $subscription ),
            'FIELD_CUSTOMER_EMAIL' => $user->user_email,
            'list_uid' => $this->mailing_list_id
        ];
        try {
            $this->api_client->send_event('subscription_created', $data);
        } catch (Exception $e) {
            error_log('Mailblaze Integration Error (handle_subscription_created): ' . $e->getMessage());
        }
    }

    public function handle_subscription_cancelled( $subscription ) {
        $user = get_userdata( $subscription->get_customer_id() );
        if ( ! $user ) return;
        $data = [
            'subscription' => $this->prepare_subscription_data( $subscription ),
            'FIELD_CUSTOMER_EMAIL' => $user->user_email,
            'list_uid' => $this->mailing_list_id
        ];
        try {
            $this->api_client->send_event('subscription_cancelled', $data);
        } catch (Exception $e) {
            error_log('Mailblaze Integration Error (handle_subscription_cancelled): ' . $e->getMessage());
        }
    }

    // New handlers for cart_created and cart_updated
    public function handle_cart_created( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
        $this->prepare_and_send_cart_event('cart_created');
    }

    public function handle_cart_updated() {
        $this->prepare_and_send_cart_event('cart_updated');
    }

    private function prepare_and_send_cart_event($event_type) {
        if ( ! WC()->session || ! WC()->cart ) {
            // error_log('Mailblaze: Cart or session not available for ' . $event_type);
            return;
        }

        $cart_id = WC()->session->get('mailblaze_cart_id');

        // Generate a new cart ID if one doesn't exist or if the cart was previously empty and now it's a 'cart_created' event.
        // The woocommerce_add_to_cart hook (for cart_created) fires *before* the item is technically in the cart for is_empty() check.
        // So, if cart_id is empty, it's a new session for cart tracking.
        if ( empty($cart_id) ) {
            if (function_exists('wp_generate_uuid4')) {
                $cart_id = wp_generate_uuid4();
            } else {
                $cart_id = uniqid('mb_cart_', true); // Fallback
            }
            WC()->session->set('mailblaze_cart_id', $cart_id);
            // error_log('Mailblaze: Generated new mailblaze_cart_id: ' . $cart_id . ' for event: ' . $event_type);
        }
        
        // For 'cart_updated', don't send if cart becomes empty.
        // Cart emptying is handled by handle_cart_emptied_for_id_clear to clear the ID.
        if ( $event_type === 'cart_updated' && WC()->cart->is_empty() ) {
            // error_log('Mailblaze: Cart is empty for cart_updated event, not sending. Cart ID was: ' . $cart_id);
            return;
        }
        // If it's a 'cart_created' event and the cart is empty (e.g. hook fired but item not added yet/failed), also don't send.
        // This check is tricky because woocommerce_add_to_cart fires before item is fully in WC()->cart for is_empty().
        // The main check is that we have items in get_cart_items_payload.

        $email = null;
        $user = wp_get_current_user();
        if ( $user && $user->ID && $user->user_email ) {
            $email = $user->user_email;
        } elseif ( class_exists('WC_Checkout') && WC()->checkout() && method_exists(WC()->checkout(), 'get_value') && WC()->checkout()->get_value('billing_email') ) {
            $email = WC()->checkout()->get_value('billing_email');
        }

        if ( empty($email) ) {
            // error_log('Mailblaze Integration: No email found for cart event (' . $event_type . '), not sending. Cart ID: ' . $cart_id);
            return; 
        }

        $cart_items_payload = $this->get_cart_items_payload(WC()->cart);
        if ( $event_type === 'cart_created' && empty($cart_items_payload['items_detailed']) && WC()->cart->is_empty()) {
            // If truly nothing in cart for a create event, don't send.
            // error_log('Mailblaze: Cart is effectively empty for cart_created event (' . $event_type . '), not sending. Cart ID: ' . $cart_id);
            return;
        }

        $data = [
            'FIELD_CART_ID'         => $cart_id,
            'FIELD_CART_ITEMS'      => $cart_items_payload,
            'FIELD_CART_TOTAL'      => WC()->cart->get_total('raw'),
            'FIELD_CURRENCY'        => get_woocommerce_currency(),
            'FIELD_CUSTOMER_EMAIL'  => $email,
            'list_uid'              => $this->mailing_list_id
        ];

        try {
            $this->api_client->send_event($event_type, $data);
            // error_log('Mailblaze: Sent ' . $event_type . ' event. Cart ID: ' . $cart_id);
        } catch (Exception $e) {
            error_log('Mailblaze Integration Error (' . $event_type . '): ' . $e->getMessage() . ' Cart ID: ' . $cart_id);
        }
    }

    private function get_cart_items_payload($cart_object) {
        $items_string = [];
        $items_detailed = [];

        if ( ! $cart_object ) return ['items_string' => '', 'items_detailed' => []];

        foreach ( $cart_object->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            if ( ! $product instanceof WC_Product) continue; // Ensure product is valid WC_Product object

            $items_string[] = $product->get_name() . ' x ' . $cart_item['quantity'];
            
            $image_url = $this->get_product_image_url($product);

            $items_detailed[] = [
                'product_id'    => $product->get_id(),
                'name'          => $product->get_name(),
                'quantity'      => $cart_item['quantity'],
                'price'         => $cart_item['quantity'] > 0 ? (float) $cart_item['line_subtotal'] / $cart_item['quantity'] : 0,
                'total'         => (float) $cart_item['line_total'], 
                'sku'           => $product->get_sku(),
                'variation_id'  => $cart_item['variation_id'],
                'description'   => $product->get_short_description() ? $product->get_short_description() : $product->get_description(),
                'image'         => $image_url
            ];
        }
        return [
            'items_string' => implode(', ', $items_string),
            'items_detailed' => $items_detailed
        ];
    }

    /**
     * Get high-resolution image URL for better quality
     * 
     * @param WC_Product $product The product object
     * @return string The high-resolution image URL
     */
    private function get_product_image_url($product) {
        if (!$product instanceof WC_Product) {
            return '';
        }
        
        $image_id = $product->get_image_id();
        if (!$image_id) {
            return '';
        }
        
        // Try to get large size first, fallback to full size
        $image_url = wp_get_attachment_image_url($image_id, 'large');
        if (!$image_url) {
            $image_url = wp_get_attachment_url($image_id);
        }
        
        return $image_url ? $image_url : '';
    }

    private function prepare_order_data( $order ) {
        // Ensure order object is valid
        if ( ! $order instanceof WC_Order ) {
            return [];
        }
        return [
            'FIELD_ORDER_ID'           => $order->get_id(), // Changed from order_id to match NestJS field
            'FIELD_ORDER_STATUS'       => $order->get_status(), // Changed from status to match NestJS field
            'FIELD_ORDER_TOTAL'        => $order->get_total(), // Changed from total to match NestJS field
            'FIELD_ORDER_CURRENCY'     => $order->get_currency(), // Changed from currency to match NestJS field
            'FIELD_ORDER_CREATED_AT'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
            'FIELD_BILLING_EMAIL'      => $order->get_billing_email(),
            'FIELD_BILLING_FIRST_NAME' => $order->get_billing_first_name(),
            'FIELD_BILLING_LAST_NAME'  => $order->get_billing_last_name(),
            'FIELD_ORDER_ITEMS'        => $this->get_order_items( $order ),
            // Add order_completed based on status, matching NestJS IOrderStatusChangedEvent logic
            'order_completed'          => in_array($order->get_status(), ['completed', 'processing']), // Example completion statuses
        ];
    }
    
    private function prepare_user_data( $user ) {
        if ( ! $user instanceof WP_User ) return [];
        return [
            'FIELD_USER_ID'    => $user->ID,
            'FIELD_EMAIL'      => $user->user_email,
            'FIELD_FIRST_NAME' => $user->first_name,
            'FIELD_LAST_NAME'  => $user->last_name,
            'FIELD_REGISTERED_DATE' => $user->user_registered,
        ];
    }

    // prepare_cart_data is not used by new cart events, replaced by get_cart_items_payload
    // private function prepare_cart_data( $cart ) { ... }

    private function prepare_subscription_data( $subscription ) {
        if ( ! $subscription instanceof WC_Subscription ) return [];
        return [
            'FIELD_SUBSCRIPTION_ID'   => $subscription->get_id(),
            'FIELD_STATUS'            => $subscription->get_status(),
            'FIELD_TOTAL'             => $subscription->get_total(),
            'FIELD_BILLING_PERIOD'    => $subscription->get_billing_period(),
            'FIELD_BILLING_INTERVAL'  => $subscription->get_billing_interval(),
            'FIELD_START_DATE'        => $subscription->get_date('start'),
            'FIELD_NEXT_PAYMENT_DATE' => $subscription->get_date('next_payment'),
            'FIELD_CUSTOMER_ID'       => $subscription->get_customer_id(),
        ];
    }

    private function get_order_items($order) {
        $items_string = [];
        $items_detailed = [];
        
        if ( ! $order instanceof WC_Order ) return ['items_string' => '', 'items_detailed' => []];

        foreach ($order->get_items() as $item_id => $item) {
            if ( ! $item instanceof WC_Order_Item_Product ) continue;

            $product = $item->get_product();
            $image_url = '';
            if ($product instanceof WC_Product) { // Ensure $product is a WC_Product
                $image_url = $this->get_product_image_url($product);
            }
            
            $items_string[] = $item->get_name() . ' x ' . $item->get_quantity();
            
            $items_detailed[] = [
                'product_id'    => $item->get_product_id(),
                'name'          => $item->get_name(),
                'quantity'      => $item->get_quantity(),
                'price'         => $item->get_quantity() > 0 ? wc_format_decimal( $item->get_subtotal() / $item->get_quantity(), wc_get_price_decimals() ) : 0,
                'total'         => wc_format_decimal( $item->get_total(), wc_get_price_decimals() ),
                'subtotal'      => wc_format_decimal( $item->get_subtotal(), wc_get_price_decimals() ),
                'sku'           => $product instanceof WC_Product ? $product->get_sku() : '',
                'variation_id'  => $item->get_variation_id(),
                // variation_name can be complex, often included in product name for variations
                'tax'           => wc_format_decimal( $item->get_total_tax(), wc_get_price_decimals() ),
                // tax_data can be verbose: $item->get_taxes(),
                'description'   => $product instanceof WC_Product ? ($product->get_short_description() ? $product->get_short_description() : $product->get_description()) : '',
                'image'         => $image_url,
                'product_url'   => $product instanceof WC_Product ? $product->get_permalink() : ''
            ];
        }

        return [
            'items_string' => implode(', ', $items_string),
            'items_detailed' => $items_detailed
        ];
    }

    private function get_shipping_address($order) {
        if ( ! $order instanceof WC_Order ) return '';
        $address = $order->get_formatted_shipping_address();
        return str_replace('<br/>', ', ', $address);
    }

    public function handle_cart_abandoned_hook() { 
        if ( ! is_user_logged_in() ) { // Removed cart empty check as it will always be empty here
            return;
        }

        $user_id = get_current_user_id();
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        // Since cart is empty on woocommerce_cart_emptied, items_string and items_detailed will be empty.
        // This event, if enabled, mainly signals that a user (logged in) emptied their cart.
        // The actual abandonment with items is handled by NestJS service.
        $cart_items_payload = ['items_string' => '', 'items_detailed' => []];
        $mailblaze_cart_id = WC()->session ? WC()->session->get('mailblaze_cart_id') : null; // Get our cart_id

        $data = [
            'FIELD_USER_ID'  => $user_id, // Kept for consistency with how this event was structured
            'FIELD_CART_ID'  => $mailblaze_cart_id, // Add the cart_id that was just emptied
            'FIELD_CART_ITEMS' => $cart_items_payload, // Send the structured but empty payload
            'FIELD_CUSTOMER_EMAIL' => $user->user_email,
            'list_uid' => $this->mailing_list_id
        ];
        try {
            $this->api_client->send_event('cart_abandoned', $data);
            // error_log('Mailblaze: Sent plugin-triggered cart_abandoned (cart emptied) event. Cart ID: ' . $mailblaze_cart_id);
        } catch (Exception $e) {
            error_log('Mailblaze Integration Error (handle_cart_abandoned_hook): ' . $e->getMessage());
        }
        // The mailblaze_cart_id is cleared by handle_cart_emptied_for_id_clear, which also hooks to woocommerce_cart_emptied
    }
}