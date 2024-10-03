<?php
// Exit if accessed directly
if (! defined('ABSPATH')) {
    exit;
}

class Mailblaze_WC_Admin_Settings
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
    }

    public function add_settings_page()
    {
        add_menu_page(
            'Mailblaze Integration',
            'Mailblaze',
            'manage_options',
            'mailblaze-wc-integration',
            [$this, 'create_settings_page'],
            'dashicons-email-alt',
            56
        );

        // Only show Register Store submenu if store is not registered
        $store_id = get_option('mailblaze_wc_store_id', '');
        if (empty($store_id)) {
            add_submenu_page(
                'mailblaze-wc-integration',
                'Store Configuration',
                'Store Configuration',
                'manage_options',
                'mailblaze-wc-register-store',
                [$this, 'create_register_store_page']
            );
        }
    }


    public function create_settings_page()
    {
        // Check user capabilities
        if (! current_user_can('manage_options')) {
            return;
        }

        // Save settings if form is submitted
        if (isset($_POST['mailblaze_wc_save_settings'])) {
            $this->save_settings();
        }

        // Get stored API key
        $api_key = get_option('mailblaze_wc_api_key', '');

        // Display any error messages
        settings_errors('mailblaze_wc_errors');
        settings_errors('mailblaze_wc_success');

        // Get stored options
        $enabled_hooks = get_option('mailblaze_wc_enabled_hooks', []);

        // Display settings form
?>
        <div class="wrap">
            <h1>Mailblaze WooCommerce Integration</h1>
            <form method="post">
                <?php wp_nonce_field('mailblaze_wc_settings_save', 'mailblaze_wc_nonce'); ?>
                <table class="form-table">
                    <!-- Existing API Key Field -->
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_api_key">Mailblaze API Key</label></th>
                        <td><input type="text" id="mailblaze_wc_api_key" name="mailblaze_wc_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" /></td>
                    </tr>
                    <!-- New Hook Configuration Section -->
                    <tr valign="top">
                        <th scope="row">Enable Hooks</th>
                        <td>
                            <label>
                                <input type="checkbox" name="mailblaze_wc_enabled_hooks[]" value="new_order" <?php checked(in_array('new_order', $enabled_hooks)); ?> />
                                New Order Created
                            </label><br />
                            <label>
                                <input type="checkbox" name="mailblaze_wc_enabled_hooks[]" value="order_status_changed" <?php checked(in_array('order_status_changed', $enabled_hooks)); ?> />
                                Order Status Changed
                            </label><br />
                            <label>
                                <input type="checkbox" name="mailblaze_wc_enabled_hooks[]" value="user_register" <?php checked(in_array('user_register', $enabled_hooks)); ?> />
                                New Customer Registered
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings', 'primary', 'mailblaze_wc_save_settings'); ?>
            </form>
        </div>
    <?php
    }

    private function save_settings()
    {
        // Check nonce
        if (! isset($_POST['mailblaze_wc_nonce']) || ! wp_verify_nonce($_POST['mailblaze_wc_nonce'], 'mailblaze_wc_settings_save')) {
            add_settings_error('mailblaze_wc_errors', 'invalid_nonce', 'Invalid security token, please try again.', 'error');
            return;
        }

        // Sanitize and save API key
        if (isset($_POST['mailblaze_wc_api_key'])) {
            $api_key = sanitize_text_field($_POST['mailblaze_wc_api_key']);

            if (empty($api_key)) {
                add_settings_error('mailblaze_wc_errors', 'empty_api_key', 'API Key cannot be empty.', 'error');
            } else {
                update_option('mailblaze_wc_api_key', $api_key);
                add_settings_error('mailblaze_wc_success', 'settings_saved', 'API Key saved successfully.', 'updated');
            }
        }

        // Save enabled hooks
        $enabled_hooks = isset($_POST['mailblaze_wc_enabled_hooks']) ? (array) $_POST['mailblaze_wc_enabled_hooks'] : [];
        $enabled_hooks = array_map('sanitize_text_field', $enabled_hooks);
        update_option('mailblaze_wc_enabled_hooks', $enabled_hooks);
    }

    public function create_register_store_page()
    {
        // Check user capabilities
        if (! current_user_can('manage_options')) {
            return;
        }

        // Get stored API key
        $api_key = get_option('mailblaze_wc_api_key', '');

        if (empty($api_key)) {
            echo '<div class="notice notice-error"><p>Please enter your Mailblaze API key in the main settings page before registering a store.</p></div>';
            return;
        }

        // Instantiate API client
        $api_client = new Mailblaze_WC_API_Client($api_key);

        // Fetch mailing lists
        $mailing_lists = $api_client->get_mailing_lists();

        if (empty($mailing_lists)) {
            echo '<div class="notice notice-error"><p>No mailing lists found in your Mailblaze account. Please create a mailing list before registering a store.</p></div>';
            return;
        }

        // Process form submission
        if (isset($_POST['mailblaze_wc_register_store_submit'])) {
            $this->process_register_store_form($api_key);
        }

        // Display the form
        $this->display_register_store_form($mailing_lists);
    }


    private function display_register_store_form($mailing_lists)
    {
    ?>
        <div class="wrap">
            <h1>Register Store with Mailblaze</h1>
            <?php settings_errors('mailblaze_wc_errors'); ?>
            <form method="post">
                <?php wp_nonce_field('mailblaze_wc_register_store', 'mailblaze_wc_nonce'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_store_name">Store Name</label></th>
                        <td><input type="text" id="mailblaze_wc_store_name" name="mailblaze_wc_store_name" value="<?php echo esc_attr(get_bloginfo('name')); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_mailing_list_id">Select Mailing List</label></th>
                        <td>
                            <select id="mailblaze_wc_mailing_list_id" name="mailblaze_wc_mailing_list_id" required>
                                <?php foreach ($mailing_lists as $list) : ?>
                                    <option value="<?php echo esc_attr($list['list_uid']); ?>">
                                        <?php echo esc_html($list['display_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select the mailing list to associate with this store.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Register Store', 'primary', 'mailblaze_wc_register_store_submit'); ?>
            </form>
        </div>
<?php
    }


    private function process_register_store_form($api_key)
    {
        // Check nonce
        if (! isset($_POST['mailblaze_wc_nonce']) || ! wp_verify_nonce($_POST['mailblaze_wc_nonce'], 'mailblaze_wc_register_store')) {
            add_settings_error('mailblaze_wc_errors', 'invalid_nonce', 'Invalid security token, please try again.', 'error');
            return;
        }

        // Sanitize and validate inputs
        $store_name = sanitize_text_field($_POST['mailblaze_wc_store_name']);
        $mailing_list_id = sanitize_text_field($_POST['mailblaze_wc_mailing_list_id']);

        if (empty($store_name) || empty($mailing_list_id)) {
            add_settings_error('mailblaze_wc_errors', 'empty_fields', 'Please fill in all required fields.', 'error');
            return;
        }

        // Instantiate API client
        $api_client = new Mailblaze_WC_API_Client($api_key);

        // Get site domain
        $site_domain = parse_url(home_url(), PHP_URL_HOST);

        // Prepare data for registration
        $data = [
            'name'            => $store_name,
            'domain'          => $site_domain,
            'list_uid' => $mailing_list_id,
            'type'            => 'woocommerce',
        ];

        try {
            // Send registration request to Mailblaze
            $response = $api_client->register_store($data);
        } catch (Exception $e) {
            add_settings_error('mailblaze_wc_errors', 'registration_failed', 'Failed to register the store: ' . $e->getMessage(), 'error');
            return;
        }

        if ($response) {
            // Store the store ID and mailing list ID in options
            update_option('mailblaze_wc_mailing_list_id', $mailing_list_id);
            add_settings_error('mailblaze_wc_errors', 'store_registered', 'Store registered successfully.', 'updated');
            // Redirect to main settings page or display success message
            // You can use wp_redirect() if needed
        } else {
            add_settings_error('mailblaze_wc_errors', 'registration_failed', 'Failed to register the store. Please try again.', 'error');
        }
    }
}
