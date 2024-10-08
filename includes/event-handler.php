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
        $this->mailing_list_id = get_option( 'mailblaze_wc_mailing_list_id', '' );
        $this->enabled_hooks = get_option( 'mailblaze_wc_enabled_hooks', [] );

        if ( ! empty( $api_key ) && ! empty( $this->store_id ) ) {
            $this->api_client = new Mailblaze_WC_API_Client( $api_key );
            $this->init_hooks();
        }
    }

    private function init_hooks() {
        // Existing hooks
        if ( in_array( 'new_order', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_new_order', [ $this, 'handle_new_order' ] );
        }

        if ( in_array( 'order_status_changed', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_changed' ], 10, 4 );
        }

        if ( in_array( 'user_register', $this->enabled_hooks ) ) {
            add_action( 'user_register', [ $this, 'handle_user_register' ] );
        }

        // New hooks
        if ( in_array( 'product_purchase', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_order_status_completed', [ $this, 'handle_product_purchase' ] );
        }

        if ( in_array( 'cart_abandoned', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_cart_emptied', [ $this, 'handle_cart_abandoned' ] );
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
    }

    public function handle_new_order( $order_id ) {
        $order = wc_get_order( $order_id );
        $data = [
            'created' => current_time('mysql'),
            'name' => 'order.created',
            'FIELD_TOTAL' => $order->get_total(),
            'FIELD_ORDER_REF' => $order->get_order_number(),
            'FIELD_ITEMS' => $this->get_order_items($order),
            'FIELD_CUSTOMER_EMAIL' => $order->get_billing_email(),
            'FIELD_SHIPPING_ADDRESS' => $this->get_shipping_address($order),
            'FIELD_PAYMENT_METHOD' => $order->get_payment_method_title(),
            'FIELD_CURRENCY' => $order->get_currency()
        ];

        try {
            $this->api_client->send_event($data);
        } catch (Exception $e) {
            // Log the error
            error_log('Mailblaze Integration Error: ' . $e->getMessage());
        }
    }

    public function handle_order_status_changed( $order_id, $old_status, $new_status, $order ) {
        // Prepare data to send to Mailblaze
        $data = [
            'store_id'  => $this->store_id,
            'event'     => 'order_status_changed',
            'order'     => $this->prepare_order_data( $order ),
            'old_status' => $old_status,
            'new_status' => $new_status,
        ];
        $this->api_client->send_event( $data );
    }

    public function handle_user_register( $user_id ) {
        $user = get_userdata( $user_id );
        // Prepare data to send to Mailblaze
        $data = [
            'store_id' => $this->store_id,
            'event'    => 'user_register',
            'user'     => $this->prepare_user_data( $user ),
        ];
        $this->api_client->send_event( $data );
    }

    public function handle_product_purchase( $order_id ) {
        $order = wc_get_order( $order_id );
        $data = [
            'store_id' => $this->store_id,
            'event'    => 'product_purchase',
            'order'    => $this->prepare_order_data( $order ),
        ];
        $this->api_client->send_event( $data );
    }

    public function handle_cart_abandoned() {
        if ( ! is_user_logged_in() || WC()->cart->is_empty() ) {
            return;
        }

        $user_id = get_current_user_id();
        $cart = WC()->cart->get_cart();

        $data = [
            'store_id' => $this->store_id,
            'event'    => 'cart_abandoned',
            'user_id'  => $user_id,
            'cart'     => $this->prepare_cart_data( $cart ),
        ];
        $this->api_client->send_event( $data );
    }

    public function handle_coupon_used( $coupon_code ) {
        $coupon = new WC_Coupon( $coupon_code );
        $data = [
            'store_id'    => $this->store_id,
            'event'       => 'coupon_used',
            'coupon_code' => $coupon_code,
            'discount'    => $coupon->get_amount(),
        ];
        $this->api_client->send_event( $data );
    }

    public function handle_subscription_created( $subscription ) {
        $data = [
            'store_id'     => $this->store_id,
            'event'        => 'subscription_created',
            'subscription' => $this->prepare_subscription_data( $subscription ),
        ];
        $this->api_client->send_event( $data );
    }

    public function handle_subscription_cancelled( $subscription ) {
        $data = [
            'store_id'     => $this->store_id,
            'event'        => 'subscription_cancelled',
            'subscription' => $this->prepare_subscription_data( $subscription ),
        ];
        $this->api_client->send_event( $data );
    }

    private function prepare_order_data( $order ) {
        return [
            'order_id'      => $order->get_id(),
            'status'        => $order->get_status(),
            'total'         => $order->get_total(),
            'currency'      => $order->get_currency(),
            'created_at'    => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
            'billing_email' => $order->get_billing_email(),
            'billing_first_name' => $order->get_billing_first_name(),
            'billing_last_name'  => $order->get_billing_last_name(),
            'items'         => $this->get_order_items( $order ),
            // Add more fields as needed
        ];
    }
    
    private function prepare_user_data( $user ) {
        // Extract necessary user data
        return [
            'user_id'    => $user->ID,
            'email'      => $user->user_email,
            'first_name' => $user->first_name,
            'last_name'  => $user->last_name,
            'registered' => $user->user_registered,
            // Add more fields as needed
        ];
    }

    private function prepare_cart_data( $cart ) {
        $cart_data = [];
        foreach ( $cart as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];
            $cart_data[] = [
                'product_id' => $product->get_id(),
                'name'       => $product->get_name(),
                'quantity'   => $cart_item['quantity'],
                'total'      => $cart_item['line_total'],
            ];
        }
        return $cart_data;
    }

    private function prepare_subscription_data( $subscription ) {
        return [
            'subscription_id' => $subscription->get_id(),
            'status'          => $subscription->get_status(),
            'total'           => $subscription->get_total(),
            'billing_period'  => $subscription->get_billing_period(),
            'billing_interval' => $subscription->get_billing_interval(),
            'start_date'      => $subscription->get_date('start'),
            'next_payment_date' => $subscription->get_date('next_payment'),
            'customer_id'     => $subscription->get_customer_id(),
        ];
    }


    private function get_order_items($order) {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name() . ' x ' . $item->get_quantity();
        }
        return implode(', ', $items);
    }

    private function get_shipping_address($order) {
        $address = $order->get_formatted_shipping_address();
        return str_replace('<br/>', ', ', $address);
    }
}
