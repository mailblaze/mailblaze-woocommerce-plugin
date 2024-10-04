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
        // Order Created
        if ( in_array( 'new_order', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_new_order', [ $this, 'handle_new_order' ] );
        }

        // Order Status Changed
        if ( in_array( 'order_status_changed', $this->enabled_hooks ) ) {
            add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_changed' ], 10, 4 );
        }

        // User Registration
        if ( in_array( 'user_register', $this->enabled_hooks ) ) {
            add_action( 'user_register', [ $this, 'handle_user_register' ] );
        }
    }

    public function handle_new_order( $order_id ) {
        $order = wc_get_order( $order_id );
        $data = [
            // ... prepare data ...
        ];
        try {
            $this->api_client->send_event( $data );
        } catch ( Exception $e ) {
            // Log the error
            error_log( 'Mailblaze Integration Error: ' . $e->getMessage() );
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
    
    private function get_order_items( $order ) {
        $items = [];
        foreach ( $order->get_items() as $item_id => $item ) {
            $product = $item->get_product();
            $items[] = [
                'product_id' => $product->get_id(),
                'name'       => $product->get_name(),
                'quantity'   => $item->get_quantity(),
                'total'      => $item->get_total(),
            ];
        }
        return $items;
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
}
