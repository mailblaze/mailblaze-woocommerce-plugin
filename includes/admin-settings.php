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

        add_submenu_page(
            'mailblaze-wc-integration',
            'Settings',
            'Settings',
            'manage_options',
            'mailblaze-wc-integration',
            [$this, 'create_settings_page']
        );

        // Always show Register Store submenu
        add_submenu_page(
            'mailblaze-wc-integration',
            'Store Configuration',
            'Store Configuration',
            'manage_options',
            'mailblaze-wc-register-store',
            [$this, 'create_register_store_page']
        );
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
        $sync_products = get_option('mailblaze_wc_sync_products', '0');

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
                            </label><br />
                            <label>
                                <input type="checkbox" name="mailblaze_wc_enabled_hooks[]" value="product_purchase" <?php checked(in_array('product_purchase', $enabled_hooks)); ?> />
                                Product Purchased
                            </label><br />
                            <label>
                                <input type="checkbox" name="mailblaze_wc_enabled_hooks[]" value="cart_abandoned" <?php checked(in_array('cart_abandoned', $enabled_hooks)); ?> />
                                Cart Abandoned
                            </label><br />
                            <label>
                                <input type="checkbox" name="mailblaze_wc_enabled_hooks[]" value="coupon_used" <?php checked(in_array('coupon_used', $enabled_hooks)); ?> />
                                Coupon Used
                            </label><br />
                            <label>
                                <input type="checkbox" name="mailblaze_wc_enabled_hooks[]" value="subscription_created" <?php checked(in_array('subscription_created', $enabled_hooks)); ?> />
                                Subscription Created
                            </label><br />
                            <label>
                                <input type="checkbox" name="mailblaze_wc_enabled_hooks[]" value="subscription_cancelled" <?php checked(in_array('subscription_cancelled', $enabled_hooks)); ?> />
                                Subscription Cancelled
                            </label>
                        </td>
                    </tr>
                    <!-- New Product Sync Option -->
                    <tr valign="top">
                        <th scope="row">Sync Products</th>
                        <td>
                            <label>
                                <input type="checkbox" name="mailblaze_wc_sync_products" value="1" <?php checked($sync_products, '1'); ?> />
                                Enable product synchronization with Mailblaze
                            </label>
                            <p class="description">When enabled, product information will be synced with Mailblaze when products are created, updated, or deleted.</p>
                        </td>
                    </tr>
                    <!-- Opt-in Checkbox Option -->
                    <tr valign="top">
                        <th scope="row">Enable Opt-in Checkbox</th>
                        <td>
                            <label>
                                <input type="checkbox" name="mailblaze_wc_enable_optin" value="1" <?php checked(get_option('mailblaze_wc_enable_optin', '0'), '1'); ?> />
                                Add opt-in checkbox to registration and account pages
                            </label>
                            <p class="description">When enabled, an opt-in checkbox will be added to the WooCommerce registration form and account details page.</p>
                        </td>
                    </tr>
                    <!-- Mailing List Dropdown -->
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_mailing_list">Default Mailing List</label></th>
                        <td>
                            <?php
                            try {
                                $api_key = get_option('mailblaze_wc_api_key', '');
                                if (!empty($api_key)) {
                                    $api_client = new Mailblaze_WC_API_Client($api_key);
                                    $mailing_lists = $api_client->get_mailing_lists();
                                    if (!empty($mailing_lists)) {
                                        $selected_list = get_option('mailblaze_wc_mailing_list', '');
                                        echo '<select id="mailblaze_wc_mailing_list" name="mailblaze_wc_mailing_list" class="regular-text">';
                                        echo '<option value="">Select a mailing list</option>';
                                        foreach ($mailing_lists as $list) {
                                            echo sprintf(
                                                '<option value="%s" %s>%s</option>',
                                                esc_attr($list['list_uid']),
                                                selected($selected_list, $list['list_uid'], false),
                                                esc_html($list['name'])
                                            );
                                        }
                                        echo '</select>';
                                        echo '<p class="description">Select the default mailing list for new subscribers.</p>';
                                    } else {
                                        echo '<p class="description">No mailing lists found. Please create a mailing list in your Mailblaze account.</p>';
                                    }
                                } else {
                                    echo '<p class="description">Please save your API key first to load available mailing lists.</p>';
                                }
                            } catch (Exception $e) {
                                echo '<p class="description error">Error loading mailing lists: ' . esc_html($e->getMessage()) . '</p>';
                            }
                            ?>
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

        // Save store configuration
        $store_fields = [
            'mailblaze_wc_store_name',
            'mailblaze_wc_store_email',
            'mailblaze_wc_store_currency',
            'mailblaze_wc_store_money_format',
            'mailblaze_wc_store_locale',
            'mailblaze_wc_store_timezone',
            'mailblaze_wc_store_phone',
            'mailblaze_wc_store_address'
        ];

        foreach ($store_fields as $field) {
            if (isset($_POST[$field])) {
                $value = $field === 'mailblaze_wc_store_address' ? sanitize_textarea_field($_POST[$field]) : sanitize_text_field($_POST[$field]);
                update_option($field, $value);
            }
        }

        // Save enabled hooks
        $enabled_hooks = isset($_POST['mailblaze_wc_enabled_hooks']) ? (array) $_POST['mailblaze_wc_enabled_hooks'] : [];
        $enabled_hooks = array_map('sanitize_text_field', $enabled_hooks);
        update_option('mailblaze_wc_enabled_hooks', $enabled_hooks);

        // Save opt-in checkbox option
        $enable_optin = isset($_POST['mailblaze_wc_enable_optin']) ? '1' : '0';
        update_option('mailblaze_wc_enable_optin', $enable_optin);

        // Save product sync option
        $sync_products = isset($_POST['mailblaze_wc_sync_products']) ? '1' : '0';
        update_option('mailblaze_wc_sync_products', $sync_products);

        // Save mailing list selection
        if (isset($_POST['mailblaze_wc_mailing_list'])) {
            $mailing_list = sanitize_text_field($_POST['mailblaze_wc_mailing_list']);
            update_option('mailblaze_wc_mailing_list', $mailing_list);
        }

        // Update store information in Mailblaze
        $this->update_store_in_mailblaze();

        // Add a success message for the new option
        add_settings_error('mailblaze_wc_success', 'settings_updated', 'Settings saved successfully. Product sync ' . ($sync_products === '1' ? 'enabled' : 'disabled') . '.', 'updated');
    }

    private function update_store_in_mailblaze()
    {
        $api_key = get_option('mailblaze_wc_api_key', '');
        $foreign_store_id = get_option('mailblaze_wc_foreign_store_id', '');

        if (empty($api_key) || empty($foreign_store_id)) {
            return;
        }

        $data = [
            'foreign_store_id' => $foreign_store_id,
            'name' => get_option('mailblaze_wc_store_name', ''),
            'email_address' => get_option('mailblaze_wc_store_email', ''),
            'currency_code' => get_option('mailblaze_wc_store_currency', ''),
            'money_format' => get_option('mailblaze_wc_store_money_format', ''),
            'primary_locale' => get_option('mailblaze_wc_store_locale', ''),
            'timezone' => get_option('mailblaze_wc_store_timezone', ''),
            'phone' => get_option('mailblaze_wc_store_phone', ''),
            'address' => get_option('mailblaze_wc_store_address', ''),
        ];

        try {
            $api_client = new Mailblaze_WC_API_Client($api_key);
            $response = $api_client->register_store($data);

            if ($response && isset($response['store_id'])) {
                add_settings_error('mailblaze_wc_success', 'store_updated', 'Store information updated successfully in Mailblaze.', 'updated');
            } else {
                add_settings_error('mailblaze_wc_errors', 'update_failed', 'Failed to update store information in Mailblaze.', 'error');
            }
        } catch (Exception $e) {
            add_settings_error('mailblaze_wc_errors', 'update_failed', 'Failed to update store information in Mailblaze: ' . $e->getMessage(), 'error');
        }
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
                        <td><input type="text" id="mailblaze_wc_store_name" name="mailblaze_wc_store_name" value="<?php echo esc_attr(get_option('mailblaze_wc_store_name', get_bloginfo('name'))); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_store_email">Store Email</label></th>
                        <td><input type="email" id="mailblaze_wc_store_email" name="mailblaze_wc_store_email" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_store_currency">Currency Code</label></th>
                        <td>
                            <select id="mailblaze_wc_store_currency" name="mailblaze_wc_store_currency" required>
                                <?php
                                $currencies = get_woocommerce_currencies();
                                $default_currency = get_woocommerce_currency();
                                foreach ($currencies as $code => $name) {
                                    echo '<option value="' . esc_attr($code) . '" ' . selected($default_currency, $code, false) . '>' . esc_html($name . ' (' . $code . ')') . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_store_money_format">Money Format</label></th>
                        <td><input type="text" id="mailblaze_wc_store_money_format" name="mailblaze_wc_store_money_format" value="<?php echo esc_attr(get_woocommerce_currency_symbol()); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_store_locale">Primary Locale</label></th>
                        <td><input type="text" id="mailblaze_wc_store_locale" name="mailblaze_wc_store_locale" value="<?php echo esc_attr(get_locale()); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_store_timezone">Timezone</label></th>
                        <td><input type="text" id="mailblaze_wc_store_timezone" name="mailblaze_wc_store_timezone" value="<?php echo esc_attr(wp_timezone_string()); ?>" class="regular-text" required /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_store_phone">Store Phone</label></th>
                        <td><input type="tel" id="mailblaze_wc_store_phone" name="mailblaze_wc_store_phone" value="" class="regular-text" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_store_address">Store Address</label></th>
                        <td><textarea id="mailblaze_wc_store_address" name="mailblaze_wc_store_address" class="large-text" rows="3"></textarea></td>
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
        if (!isset($_POST['mailblaze_wc_nonce']) || !wp_verify_nonce($_POST['mailblaze_wc_nonce'], 'mailblaze_wc_register_store')) {
            add_settings_error('mailblaze_wc_errors', 'invalid_nonce', 'Invalid security token, please try again.', 'error');
            return;
        }

        // Sanitize and validate inputs
        $store_name = sanitize_text_field($_POST['mailblaze_wc_store_name']);
        $store_email = sanitize_email($_POST['mailblaze_wc_store_email']);
        $currency_code = sanitize_text_field($_POST['mailblaze_wc_store_currency']);
        $money_format = sanitize_text_field($_POST['mailblaze_wc_store_money_format']);
        $primary_locale = sanitize_text_field($_POST['mailblaze_wc_store_locale']);
        $timezone = sanitize_text_field($_POST['mailblaze_wc_store_timezone']);
        $phone = sanitize_text_field($_POST['mailblaze_wc_store_phone']);
        $address = sanitize_textarea_field($_POST['mailblaze_wc_store_address']);

        if (empty($store_name) || empty($store_email)) {
            add_settings_error('mailblaze_wc_errors', 'empty_fields', 'Please fill in all required fields.', 'error');
            return;
        }

        // Get or generate a unique store ID
        $foreign_store_id = get_option('mailblaze_wc_foreign_store_id');
        if (!$foreign_store_id) {
            $foreign_store_id = 'wc_' . uniqid();
            update_option('mailblaze_wc_foreign_store_id', $foreign_store_id);
        }

        // Prepare data for registration or update
        $data = [
            'foreign_store_id' => $foreign_store_id,
            'name' => $store_name,
            'platform' => 'woocommerce',
            'domain' => parse_url(home_url(), PHP_URL_HOST),
            'is_syncing' => true,
            'email_address' => $store_email,
            'currency_code' => $currency_code,
            'money_format' => $money_format,
            'primary_locale' => $primary_locale,
            'timezone' => $timezone,
            'phone' => $phone,
            'address' => $address,
        ];

        try {
            // Send registration/update request to Mailblaze
            $api_client = new Mailblaze_WC_API_Client($api_key);
            $response = $api_client->register_store($data);

            if ($response && isset($response['store_id'])) {
                // Store the store data in options
                update_option('mailblaze_wc_store_id', $response['store_id']);
                update_option('mailblaze_wc_store_name', $store_name);
                update_option('mailblaze_wc_store_email', $store_email);
                update_option('mailblaze_wc_store_currency', $currency_code);
                update_option('mailblaze_wc_store_money_format', $money_format);
                update_option('mailblaze_wc_store_locale', $primary_locale);
                update_option('mailblaze_wc_store_timezone', $timezone);
                update_option('mailblaze_wc_store_phone', $phone);
                update_option('mailblaze_wc_store_address', $address);

                $action = isset($response['created_at']) && isset($response['updated_at']) && $response['created_at'] === $response['updated_at'] ? 'registered' : 'updated';
                add_settings_error('mailblaze_wc_errors', 'store_registered', "Store {$action} successfully.", 'updated');
            } else {
                add_settings_error('mailblaze_wc_errors', 'registration_failed', 'Failed to register/update the store. Please try again.', 'error');
            }
        } catch (Exception $e) {
            add_settings_error('mailblaze_wc_errors', 'registration_failed', 'Failed to register/update the store: ' . $e->getMessage(), 'error');
        }
    }
}









