<?php

/**
 * Plugin Name: Mailblaze WooCommerce Integration
 * Plugin URI:  https://mailblaze.com/
 * Description: Integrates WooCommerce with Mailblaze.
 * Version:     1.0.0
 * Author:      Stephan Wessels
 * Author URI:  https://mailblaze.com/
 * License:     GPL2
 * Text Domain: mailblaze-woocommerce-integration
 */

// Exit if accessed directly
if (! defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MAILBLAZE_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MAILBLAZE_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include necessary files
require_once MAILBLAZE_WC_PLUGIN_DIR . 'includes/admin-settings.php';
require_once MAILBLAZE_WC_PLUGIN_DIR . 'includes/api-client.php';
require_once MAILBLAZE_WC_PLUGIN_DIR . 'includes/event-handler.php';
require_once MAILBLAZE_WC_PLUGIN_DIR . 'includes/optin-handler.php';

class Mailblaze_WC_Integration {
    private $admin_settings;
    private $event_handler;
    private $optin_handler;
    private $api_client;

    public function __construct() {
        // Initialize admin settings
        $this->admin_settings = new Mailblaze_WC_Admin_Settings();

        // Initialize API client
        $api_key = get_option('mailblaze_wc_api_key', '');
        if (!empty($api_key)) {
            $this->api_client = new Mailblaze_WC_API_Client($api_key);
            $this->event_handler = new Mailblaze_WC_Event_Handler();
        }

        // Initialize opt-in handler
        $this->optin_handler = new Mailblaze_WC_Optin_Handler();

        // Add activation hook
        register_activation_hook(__FILE__, [$this, 'activate']);

        // Add product sync hook
        add_action('woocommerce_update_product', [$this, 'sync_product']);
        add_action('woocommerce_new_product', [$this, 'sync_product']);
        add_action('woocommerce_delete_product', [$this, 'delete_product']);

        // Add bulk sync action
        add_action('admin_init', [$this, 'handle_bulk_product_sync']);
    }

    public function activate() {
        // Activation code here (if needed)
    }

    public function sync_product($product_id) {
        if (empty($this->api_client)) {
            return;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }

        $product_data = $this->prepare_product_data($product);
        $this->api_client->sync_products([$product_data]);
    }

    public function delete_product($product_id) {
        if (empty($this->api_client)) {
            return;
        }

        $product_data = [
            'id' => $product_id,
            'deleted' => true
        ];
        $this->api_client->sync_products([$product_data]);
    }

    private function prepare_product_data($product) {
        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'price' => $product->get_price(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'status' => $product->get_status(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'categories' => wp_list_pluck($product->get_category_ids(), 'term_id'),
            'image_url' => wp_get_attachment_url($product->get_image_id()),
            'url' => get_permalink($product->get_id()),
        ];
    }

    public function handle_bulk_product_sync() {
        if (isset($_POST['mailblaze_bulk_sync_products']) && current_user_can('manage_woocommerce')) {
            $this->bulk_sync_products();
            add_action('admin_notices', [$this, 'display_bulk_sync_notice']);
        }
    }

    private function bulk_sync_products() {
        if (empty($this->api_client)) {
            return;
        }

        $products = wc_get_products(['status' => 'publish', 'limit' => -1]);
        $product_data = array_map([$this, 'prepare_product_data'], $products);
        
        $chunk_size = 100; // Adjust this value based on API limitations
        $chunks = array_chunk($product_data, $chunk_size);

        foreach ($chunks as $chunk) {
            $this->api_client->sync_products($chunk);
        }
    }

    public function display_bulk_sync_notice() {
        ?>
        <div class="notice notice-success">
            <p><?php _e('All products have been synced with Mailblaze.', 'mailblaze-woocommerce-integration'); ?></p>
        </div>
        <?php
    }
}

// Initialize the plugin
new Mailblaze_WC_Integration();
