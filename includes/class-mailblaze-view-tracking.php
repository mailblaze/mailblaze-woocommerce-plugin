<?php
/**
 * Product and category view beacons.
 *
 * The browser posts product ids only, to this site. Price, email, and
 * mb_anon_id are added here and sent to commerce. Nothing is put on the
 * storefront URL.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__FILE__) . '/class-mailblaze-visitor.php';

class MailBlaze_View_Tracking {
    private $api_client;
    private $remembered = array();

    public function __construct($api_client) {
        $this->api_client = $api_client;
        MailBlaze_Visitor::set_api_client($api_client);
        add_action('wp_enqueue_scripts', array($this, 'enqueue'));
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route('mailblaze/v1', '/browse/views', array(
            'methods' => 'POST',
            'callback' => array($this, 'ingest'),
            'permission_callback' => '__return_true',
        ));
    }

    public function enqueue() {
        if (!$this->tracking_enabled() || (function_exists('is_admin') && is_admin())) {
            return;
        }
        if (MailBlaze_Visitor::consent_state() !== 'granted' || !MailBlaze_Visitor::get_anon_id()) {
            return;
        }
        $views = $this->current_page_views();
        if (!$views) {
            return;
        }
        wp_enqueue_script(
            'mailblaze-wc-views',
            MAILBLAZE_WC_PLUGIN_URL . 'assets/js/mailblaze-views.js',
            array('jquery'),
            '1.1.1',
            true
        );
        $product_id = '';
        $category_ids = array();
        if (isset($views[0]['product_id']) && $views[0]['product_id']) {
            $product_id = $views[0]['product_id'];
        }
        if (isset($views[0]['category_ids']) && is_array($views[0]['category_ids'])) {
            $category_ids = $views[0]['category_ids'];
        }
        wp_localize_script('mailblaze-wc-views', 'mailblazeViews', array(
            'endpoint' => esc_url_raw(rest_url('mailblaze/v1/browse/views')),
            'nonce' => wp_create_nonce('wp_rest'),
            'throttleMs' => mailblaze_wc_view_ttl() * 1000,
            'productId' => $product_id,
            'categoryIds' => $category_ids,
            'views' => $views,
        ));
    }

    /**
     * @return array
     */
    private function current_page_views() {
        if (function_exists('is_product') && is_product() && function_exists('wc_get_product')) {
            $product = wc_get_product(get_queried_object_id());
            if (!$product || !method_exists($product, 'get_id')) {
                return array();
            }
            $product_id = mailblaze_wc_public_id($product->get_id());
            if ($product_id === '') {
                return array();
            }
            $categories = array();
            if (method_exists($product, 'get_category_ids')) {
                foreach ($product->get_category_ids() as $cat_id) {
                    $public = mailblaze_wc_public_id($cat_id);
                    if ($public !== '') {
                        $categories[] = $public;
                    }
                }
            }
            return array(array(
                'page' => 'product',
                'product_id' => $product_id,
                'variant_id' => '',
                'category_ids' => $categories,
            ));
        }
        if (function_exists('is_product_category') && is_product_category() && function_exists('get_queried_object')) {
            $term = get_queried_object();
            if (!$term || empty($term->term_id)) {
                return array();
            }
            $category_id = mailblaze_wc_public_id($term->term_id);
            if ($category_id === '') {
                return array();
            }
            return array(array(
                'page' => 'category',
                'product_id' => '',
                'variant_id' => '',
                'category_ids' => array($category_id),
            ));
        }
        return array();
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function ingest($request) {
        $nonce = $request->get_header('X-WP-Nonce');
        $nonce_ok = is_string($nonce) && function_exists('wp_verify_nonce') && wp_verify_nonce($nonce, 'wp_rest');
        if (!$nonce_ok) {
            return new WP_REST_Response(null, 403);
        }
        if (!$this->tracking_enabled() || !$this->allow_rate()) {
            return new WP_REST_Response(null, 204);
        }
        $consent = MailBlaze_Visitor::consent_state();
        $params = $request->get_json_params();
        $client_views = array();
        if (is_array($params) && isset($params['views']) && is_array($params['views'])) {
            $client_views = $params['views'];
        }
        $prepared = mailblaze_wc_prepare_view_batch(
            $consent,
            array(
                'anon_id' => MailBlaze_Visitor::get_anon_id(),
                'email' => $this->known_email(),
                'store_id' => $this->option_string('mailblaze_wc_store_id'),
                'list_uid' => $this->option_string('mailblaze_wc_mailing_list'),
                'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '',
                'created_at' => gmdate('c'),
            ),
            $client_views,
            array($this, 'resolve_catalog'),
            array($this, 'is_view_throttled'),
            time()
        );
        $sent_count = 0;
        if ($prepared && MailBlaze_Visitor::post_product_views($prepared['views'])) {
            $sent_count = count($prepared['views']);
            $this->remember_views($prepared['keys'], $prepared['until']);
            $email = $this->known_email();
            if ($email) {
                MailBlaze_Visitor::link_email($email, 'email');
            }
        }
        return new WP_REST_Response(null, mailblaze_wc_ingest_status(true, $consent, $sent_count));
    }

    /**
     * @param array $clean
     * @return array|null
     */
    public function resolve_catalog($clean) {
        if (!is_array($clean) || empty($clean['page'])) {
            return null;
        }
        if ($clean['page'] === 'category') {
            $ids = array();
            $raw_ids = isset($clean['category_ids']) ? $clean['category_ids'] : array();
            foreach ($raw_ids as $id) {
                if (!function_exists('get_term')) {
                    $public = mailblaze_wc_public_id($id);
                    if ($public !== '') {
                        $ids[] = $public;
                    }
                    continue;
                }
                $term = get_term((int) $id, 'product_cat');
                if ($term && !is_wp_error($term) && !empty($term->term_id)) {
                    $ids[] = (string) (int) $term->term_id;
                }
            }
            if (!$ids) {
                return null;
            }
            return array(
                'product_id' => null,
                'variant_id' => null,
                'category_ids' => $ids,
                'price' => null,
            );
        }
        if (!function_exists('wc_get_product')) {
            return null;
        }
        $product = wc_get_product((int) $clean['product_id']);
        if (!$product || !method_exists($product, 'get_id')) {
            return null;
        }
        $variant_id = null;
        $price = method_exists($product, 'get_price') ? $product->get_price() : null;
        if (!empty($clean['variant_id'])) {
            $variation = wc_get_product((int) $clean['variant_id']);
            $parent_id = ($variation && method_exists($variation, 'get_parent_id')) ? (int) $variation->get_parent_id() : 0;
            if ($parent_id === (int) $product->get_id()) {
                $variant_id = (string) (int) $variation->get_id();
                if (method_exists($variation, 'get_price')) {
                    $price = $variation->get_price();
                }
            }
        }
        $categories = array();
        if (method_exists($product, 'get_category_ids')) {
            foreach ($product->get_category_ids() as $cat_id) {
                $public = mailblaze_wc_public_id($cat_id);
                if ($public !== '') {
                    $categories[] = $public;
                }
            }
        }
        $product_id = mailblaze_wc_public_id($product->get_id());
        if ($product_id === '') {
            return null;
        }
        return array(
            'product_id' => $product_id,
            'variant_id' => $variant_id,
            'category_ids' => $categories,
            'price' => $price,
        );
    }

    /**
     * @param string $key
     * @return bool
     */
    public function is_view_throttled($key) {
        if (isset($this->remembered[$key])) {
            return true;
        }
        return function_exists('get_transient') && (bool) get_transient($key);
    }

    /**
     * @param array $keys
     * @param int $until
     */
    private function remember_views($keys, $until) {
        $ttl = max(1, (int) $until - time());
        foreach ($keys as $key) {
            $this->remembered[$key] = true;
            if (function_exists('set_transient')) {
                set_transient($key, 1, $ttl);
            }
        }
    }

    /**
     * @return string|null
     */
    private function known_email() {
        $user_email = '';
        if (function_exists('wp_get_current_user')) {
            $user = wp_get_current_user();
            if (is_object($user) && !empty($user->user_email)) {
                $user_email = $user->user_email;
            }
        }
        $billing = '';
        if (function_exists('WC')) {
            $wc = WC();
            if (is_object($wc) && isset($wc->customer) && is_object($wc->customer) && method_exists($wc->customer, 'get_billing_email')) {
                $billing = $wc->customer->get_billing_email();
            }
        }
        return mailblaze_wc_known_email($user_email, $billing);
    }

    /**
     * @param string $key
     * @return string
     */
    private function option_string($key) {
        if (!function_exists('get_option')) {
            return '';
        }
        $value = get_option($key, '');
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return bool
     */
    private function tracking_enabled() {
        if (!function_exists('apply_filters')) {
            return true;
        }
        return (bool) apply_filters('mailblaze_wc_track_product_views', true);
    }

    /**
     * @return bool
     */
    private function allow_rate() {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return true;
        }
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $key = 'mb_view_rate_' . substr(hash('sha256', $ip), 0, 32);
        $count = (int) get_transient($key);
        if (!mailblaze_wc_rate_allow($count, 30)) {
            return false;
        }
        set_transient($key, $count + 1, 600);
        return true;
    }
}
