<?php

/**
 * Plugin Name: Mailblaze WooCommerce Integration
 * Plugin URI:  https://mailblaze.com/
 * Description: Integrates WooCommerce with Mailblaze.
 * Version:     1.0.0
 * Author:      Mail Blaze
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
require_once MAILBLAZE_WC_PLUGIN_DIR . 'includes/smtp-handler.php';

class Mailblaze_WC_Integration {
    private $admin_settings;
    private $event_handler;
    private $optin_handler;
    private $api_client;
    private $smtp_handler;

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

        // Initialize SMTP handler
        $this->smtp_handler = new Mailblaze_WC_SMTP_Handler();

        // Add activation hook
        register_activation_hook(__FILE__, [$this, 'activate']);

        // Add product sync hook
        add_action('woocommerce_update_product', [$this, 'sync_product']);
        add_action('woocommerce_new_product', [$this, 'sync_product']);
        add_action('woocommerce_delete_product', [$this, 'delete_product']);

        // Add bulk sync action
        add_action('admin_init', [$this, 'handle_bulk_product_sync']);

        // Register REST API endpoint
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function activate() {
        // Activation code here (if needed)
    }

    public function sync_product($product_id) {
        if (empty($this->api_client) || get_option('mailblaze_wc_sync_products', '0') !== '1') {
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
        if (empty($this->api_client) || get_option('mailblaze_wc_sync_products', '0') !== '1') {
            return;
        }

        $product_data = [
            'id' => $product_id,
            'deleted' => true
        ];
        $this->api_client->sync_products([$product_data]);
    }

    /**
     * Get high-resolution image URL for better quality
     * 
     * @param int $attachment_id The attachment ID
     * @param string $size The image size (default: 'large')
     * @return string|false The image URL or false if not found
     */
    private function get_high_res_image_url($attachment_id, $size = 'large') {
        if (empty($attachment_id)) {
            return false;
        }
        
        // Try to get the specified size first, fallback to full size if not available
        $image_url = wp_get_attachment_image_url($attachment_id, $size);
        
        // If the specified size doesn't exist, get the full size
        if (!$image_url) {
            $image_url = wp_get_attachment_url($attachment_id);
        }
        
        return $image_url;
    }

    /**
     * Get multiple image sizes for responsive usage
     * 
     * @param int $attachment_id The attachment ID
     * @return array Array of image URLs in different sizes
     */
    private function get_responsive_image_urls($attachment_id) {
        if (empty($attachment_id)) {
            return [];
        }
        
        $sizes = ['thumbnail', 'medium', 'medium_large', 'large', 'full'];
        $images = [];
        
        foreach ($sizes as $size) {
            $url = wp_get_attachment_image_url($attachment_id, $size);
            if ($url) {
                $images[$size] = $url;
            }
        }
        
        return $images;
    }

    private function prepare_product_data($product) {
        $categories = [];
        foreach($product->get_category_ids() as $cat_id) {
            $term = get_term($cat_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $categories[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug
                ];
            }
        }

        $gallery_image_ids = $product->get_gallery_image_ids();
        $gallery_images = [];
        foreach($gallery_image_ids as $image_id) {
            $image_url = $this->get_high_res_image_url($image_id, 'large');
            if ($image_url) {
                $gallery_images[] = [
                    'url' => $image_url,
                    'sizes' => $this->get_responsive_image_urls($image_id)
                ];
            }
        }

        // Get main product image in high resolution
        $main_image_id = $product->get_image_id();
        $main_image_url = $this->get_high_res_image_url($main_image_id, 'large');

        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'slug' => $product->get_slug(),
            'sku' => $product->get_sku(),
            'price' => [
                'current' => $product->get_price(),
                'regular' => $product->get_regular_price(),
                'sale' => $product->get_sale_price(),
                'currency' => get_woocommerce_currency()
            ],
            'status' => $product->get_status(),
            'featured' => $product->get_featured(),
            'description' => [
                'full' => $product->get_description(),
                'short' => $product->get_short_description()
            ],
            'categories' => $categories,
            'images' => [
                'main' => $main_image_url,
                'main_sizes' => $this->get_responsive_image_urls($main_image_id),
                'gallery' => $gallery_images
            ],
            'stock' => [
                'status' => $product->get_stock_status(),
                'quantity' => $product->get_stock_quantity()
            ],
            'type' => $product->get_type(),
            'virtual' => $product->is_virtual(),
            'downloadable' => $product->is_downloadable(),
            'url' => get_permalink($product->get_id()),
            'date_created' => $product->get_date_created() ? $product->get_date_created()->format('c') : null,
            'date_modified' => $product->get_date_modified() ? $product->get_date_modified()->format('c') : null,
        ];
    }

    public function handle_bulk_product_sync() {
        if (isset($_POST['mailblaze_bulk_sync_products']) && current_user_can('manage_woocommerce')) {
            $this->bulk_sync_products();
            add_action('admin_notices', [$this, 'display_bulk_sync_notice']);
        }
    }

    private function bulk_sync_products() {
        if (empty($this->api_client) || get_option('mailblaze_wc_sync_products', '0') !== '1') {
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

    public function register_rest_routes()
    {
        register_rest_route('mailblaze/v1', '/products', [
            'methods' => 'GET',
            'callback' => [$this, 'get_products'],
            'permission_callback' => [$this, 'check_api_permissions'],
            'args' => [
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'limit' => [
                    'default' => 100,
                    'sanitize_callback' => 'absint',
                ],
            ],
            'schema' => [$this, 'get_products_schema'],
        ]);

        // Add new categories endpoint
        register_rest_route('mailblaze/v1', '/categories', [
            'methods' => 'GET',
            'callback' => [$this, 'get_categories'],
            'permission_callback' => [$this, 'check_api_permissions'],
            'args' => [
                'page' => [
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'limit' => [
                    'default' => 100,
                    'sanitize_callback' => 'absint',
                ],
            ],
            'schema' => [$this, 'get_categories_schema'],
        ]);
    }

    public function check_api_permissions(WP_REST_Request $request)
    {
        // Get the authorization header
        $auth_header = $request->get_header('X-Mailblaze-Token');
        
        if (empty($auth_header)) {
            return new WP_Error(
                'rest_forbidden',
                'Missing authentication token',
                ['status' => 401]
            );
        }

        // Get the stored token
        $stored_token = get_option('mailblaze_wc_store_access_token');
        
        if (empty($stored_token)) {
            return new WP_Error(
                'rest_forbidden',
                'Store is not properly registered',
                ['status' => 401]
            );
        }

        // Compare the tokens
        if ($auth_header !== $stored_token) {
            return new WP_Error(
                'rest_forbidden',
                'Invalid authentication token',
                ['status' => 403]
            );
        }

        return true;
    }

    public function get_store_access_token()
    {
        return get_option('mailblaze_wc_store_access_token');
    }

    public function get_products(WP_REST_Request $request)
    {
        try {
            $page = $request->get_param('page');
            $limit = $request->get_param('limit');
            
            // Get WooCommerce products with pagination
            $products = wc_get_products([
                'status' => 'publish',
                'limit' => $limit,
                'page' => $page,
            ]);
            
            // Format the products data
            $formatted_products = array_map([$this, 'prepare_product_data'], $products);
            
            // Get total product count for pagination
            $total_products = wp_count_posts('product');
            
            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'products' => $formatted_products,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total_pages' => ceil($total_products->publish / $limit),
                        'total_products' => $total_products->publish
                    ]
                ]
            ], 200);
        } catch (Exception $e) {
            return new WP_Error(
                'woocommerce_products_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    public function get_products_schema()
    {
        return [
            '$schema' => 'http://json-schema.org/draft-04/schema#',
            'title' => 'products',
            'type' => 'object',
            'required' => ['X-Mailblaze-Token'],
            'properties' => [
                'X-Mailblaze-Token' => [
                    'description' => 'Authentication token provided during store registration',
                    'type' => 'string',
                    'context' => ['header'],
                ],
                // ... other schema properties
            ],
        ];
    }

    public function get_categories(WP_REST_Request $request)
    {
        try {
            $page = $request->get_param('page');
            $limit = $request->get_param('limit');
            $offset = ($page - 1) * $limit;
            
            // Get product categories
            $categories = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'number' => $limit,
                'offset' => $offset,
            ]);
            
            if (is_wp_error($categories)) {
                throw new Exception($categories->get_error_message());
            }

            // Format the categories
            $formatted_categories = array_map(function($category) {
                $thumbnail_id = get_term_meta($category->term_id, 'thumbnail_id', true);
                $category_image = null;
                $category_image_sizes = [];
                
                if ($thumbnail_id) {
                    $category_image = $this->get_high_res_image_url($thumbnail_id, 'large');
                    $category_image_sizes = $this->get_responsive_image_urls($thumbnail_id);
                }
                
                return [
                    'id' => $category->term_id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'description' => $category->description,
                    'parent' => $category->parent,
                    'count' => $category->count,
                    'image' => $category_image,
                    'image_sizes' => $category_image_sizes,
                ];
            }, $categories);

            // Get total category count for pagination
            $total_categories = wp_count_terms('product_cat', ['hide_empty' => false]);
            
            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'categories' => $formatted_categories,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total_pages' => ceil($total_categories / $limit),
                        'total_categories' => $total_categories
                    ]
                ]
            ], 200);
        } catch (Exception $e) {
            return new WP_Error(
                'woocommerce_categories_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    public function get_categories_schema()
    {
        return [
            '$schema' => 'http://json-schema.org/draft-04/schema#',
            'title' => 'categories',
            'type' => 'object',
            'required' => ['X-Mailblaze-Token'],
            'properties' => [
                'X-Mailblaze-Token' => [
                    'description' => 'Authentication token provided during store registration',
                    'type' => 'string',
                    'context' => ['header'],
                ],
            ],
        ];
    }
}

// Initialize the plugin
new Mailblaze_WC_Integration();