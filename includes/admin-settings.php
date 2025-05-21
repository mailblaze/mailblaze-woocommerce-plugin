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
        add_action('admin_notices', [$this, 'display_setup_notice']);
        add_action('wp_ajax_test_smtp_connection', [$this, 'handle_smtp_test']);
        add_action('admin_head', function () {
            $image_url = plugin_dir_url(__DIR__) . 'assets/img/mb-woo-icon.svg';
            ?>
            <style>
                #toplevel_page_mailblaze-wc-integration .wp-menu-image.dashicons-email-alt {
                    background-image: url('<?php echo esc_url($image_url); ?>');
                    background-repeat: no-repeat;
                    background-position: center;
                    background-size: 20px auto;
                    &:before {
                        content: '';
                    }
                }
            </style>
            <?php
        });
    }

    public function handle_smtp_test() {
        // Check nonce
        if (!check_ajax_referer('test_smtp_connection', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token.']);
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to perform this action.']);
        }

        // Test SMTP connection
        $result = Mailblaze_WC_SMTP_Handler::test_smtp_connection();

        if ($result['success']) {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    private function is_setup_complete()
    {
        $api_key = get_option('mailblaze_wc_api_key', '');
        $store_id = get_option('mailblaze_wc_store_id', '');
        return !empty($api_key) && !empty($store_id);
    }

    public function display_setup_notice()
    {
        // Only show on Mail Blaze plugin pages or WooCommerce pages
        $screen = get_current_screen();
        if (!$screen || (!strpos($screen->id, 'mailblaze') && !strpos($screen->id, 'wc'))) {
            return;
        }

        if (!$this->is_setup_complete()) {
            $api_key = get_option('mailblaze_wc_api_key', '');
            $store_id = get_option('mailblaze_wc_store_id', '');
            ?>
            <div class="notice notice-warning is-dismissible">
                <h3 style="margin-top: 0.5em; margin-bottom: 0.5em;">📧 Complete Your Mail Blaze Integration Setup</h3>
                <p>
                    To start using the Mail Blaze WooCommerce integration, please complete the following steps:
                </p>
                <ul style="list-style-type: disc; margin-left: 1.5em; margin-bottom: 1em;">
                    <?php if (empty($api_key)): ?>
                        <li>
                            <strong>Connect your Mail Blaze account</strong> - 
                            <a href="<?php echo admin_url('admin.php?page=mailblaze-wc-integration'); ?>">Add your API key</a>
                        </li>
                    <?php endif; ?>
                    <?php if (!empty($api_key) && empty($store_id)): ?>
                        <li>
                            <strong>Configure your store</strong> - 
                            <a href="<?php echo admin_url('admin.php?page=mailblaze-wc-register-store'); ?>">Complete store registration</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php
        }
    }

    public function add_settings_page()
    {
        add_menu_page(
            'Mail Blaze Integration',
            'Mail Blaze',
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
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings if form is submitted
        if (isset($_POST['mailblaze_wc_save_settings'])) {
            $this->save_settings();
        }

        // Get stored API key
        $api_key = get_option('mailblaze_wc_api_key', '');

        // Display introduction screen if no API key is set
        if (empty($api_key)) {
            $this->display_introduction_screen();
            return;
        }

        // Display any error messages
        settings_errors('mailblaze_wc_errors');
        settings_errors('mailblaze_wc_success');

        // Get stored options
        $enabled_hooks = get_option('mailblaze_wc_enabled_hooks', []);
        $sync_products = get_option('mailblaze_wc_sync_products', '0');

        // Display settings form
?>
        <div class="wrap">
            <h1>Mail Blaze WooCommerce Integration</h1>
            <form method="post">
                <?php wp_nonce_field('mailblaze_wc_settings_save', 'mailblaze_wc_nonce'); ?>
                <table class="form-table">
                    <!-- Existing API Key Field -->
                    <tr valign="top">
                        <th scope="row"><label for="mailblaze_wc_api_key">Mail Blaze API Key</label></th>
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
                                <input type="checkbox" name="mailblaze_wc_enabled_hooks[]" value="cart_created" <?php checked(in_array('cart_created', $enabled_hooks)); ?> />
                                Cart Created (First item added)
                            </label><br />
                            <label>
                                <input type="checkbox" name="mailblaze_wc_enabled_hooks[]" value="cart_updated" <?php checked(in_array('cart_updated', $enabled_hooks)); ?> />
                                Cart Updated (Item change, removal)
                            </label><br />
                            <label>
                                <input type="checkbox" name="mailblaze_wc_enabled_hooks[]" value="cart_abandoned" <?php checked(in_array('cart_abandoned', $enabled_hooks)); ?> />
                                Cart Abandoned (Logged-in users, on cart emptied)
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
                    <!-- Mailing List Droperwn -->
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
                                        echo '<p class="description">No mailing lists found. Please create a mailing list in your Mail Blaze account.</p>';
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

                    <!-- SMTP Configuration Section -->
                    <tr valign="top">
                        <th scope="row" colspan="2">
                            <h3 style="margin: 0;">SMTP Configuration</h3>
                        </th>
                    </tr>
                    <tr valign="top">
                        <th scope="row">
                            <label for="mailblaze_wc_use_smtp">Use Mail Blaze SMTP</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="mailblaze_wc_use_smtp" name="mailblaze_wc_use_smtp" value="1" <?php checked(get_option('mailblaze_wc_use_smtp', '0'), '1'); ?> />
                                Use Mail Blaze SMTP server for sending emails
                            </label>
                            <p class="description">When enabled, all WordPress emails will be sent through Mail Blaze SMTP servers.</p>
                        </td>
                    </tr>
                    <tr valign="top" class="mailblaze-smtp-settings" style="<?php echo get_option('mailblaze_wc_use_smtp', '0') !== '1' ? 'display: none;' : ''; ?>">
                        <th scope="row">
                            <label for="mailblaze_wc_smtp_username">SMTP Username</label>
                        </th>
                        <td>
                            <input type="text" id="mailblaze_wc_smtp_username" name="mailblaze_wc_smtp_username" value="<?php echo esc_attr(get_option('mailblaze_wc_smtp_username', '')); ?>" class="regular-text" />
                            <p class="description">Your Mail Blazes username (your API key will be used as the password)</p>
                        </td>
                    </tr>
                    <tr valign="top" class="mailblaze-smtp-settings" style="<?php echo get_option('mailblaze_wc_use_smtp', '0') !== '1' ? 'display: none;' : ''; ?>">
                        <th scope="row">
                            <label for="mailblaze_wc_smtp_from_email">From Email</label>
                        </th>
                        <td>
                            <input type="email" id="mailblaze_wc_smtp_from_email" name="mailblaze_wc_smtp_from_email" value="<?php echo esc_attr(get_option('mailblaze_wc_smtp_from_email', get_option('admin_email'))); ?>" class="regular-text" />
                            <p class="description">The email address that emails will be sent from</p>
                        </td>
                    </tr>
                    <tr valign="top" class="mailblaze-smtp-settings" style="<?php echo get_option('mailblaze_wc_use_smtp', '0') !== '1' ? 'display: none;' : ''; ?>">
                        <th scope="row">
                            <label for="mailblaze_wc_smtp_from_name">From Name</label>
                        </th>
                        <td>
                            <input type="text" id="mailblaze_wc_smtp_from_name" name="mailblaze_wc_smtp_from_name" value="<?php echo esc_attr(get_option('mailblaze_wc_smtp_from_name', get_bloginfo('name'))); ?>" class="regular-text" />
                            <p class="description">The name that emails will be sent from</p>
                        </td>
                    </tr>
                    <tr valign="top" class="mailblaze-smtp-settings" style="<?php echo get_option('mailblaze_wc_use_smtp', '0') !== '1' ? 'display: none;' : ''; ?>">
                        <th scope="row">Test Connection</th>
                        <td>
                            <button type="button" id="test_smtp_connection" class="button button-secondary">
                                Test SMTP Connection
                            </button>
                            <span id="smtp_test_result" style="margin-left: 10px; display: none;"></span>
                        </td>
                    </tr>
                </table>

                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        $('#mailblaze_wc_use_smtp').on('change', function() {
                            if ($(this).is(':checked')) {
                                $('.mailblaze-smtp-settings').show();
                            } else {
                                $('.mailblaze-smtp-settings').hide();
                            }
                        });

                        $('#test_smtp_connection').on('click', function() {
                            var $button = $(this);
                            var $result = $('#smtp_test_result');
                            
                            $button.prop('disabled', true);
                            $button.text('Testing...');
                            $result.hide();

                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'test_smtp_connection',
                                    nonce: '<?php echo wp_create_nonce('test_smtp_connection'); ?>'
                                },
                                success: function(response) {
                                    $result.removeClass('notice-success notice-error')
                                          .addClass(response.success ? 'notice-success' : 'notice-error')
                                          .html(response.data.message)
                                          .show();
                                },
                                error: function() {
                                    $result.removeClass('notice-success notice-error')
                                          .addClass('notice-error')
                                          .html('Connection test failed. Please try again.')
                                          .show();
                                },
                                complete: function() {
                                    $button.prop('disabled', false);
                                    $button.text('Test SMTP Connection');
                                }
                            });
                        });
                    });
                </script>

                <?php submit_button('Save Settings', 'primary', 'mailblaze_wc_save_settings'); ?>
            </form>
        </div>
    <?php
    }

    private function display_introduction_screen()
    {
        ?>
        <div class="wrap mailblaze-welcome">
            <h1>Welcome to Mail Blaze WooCommerce Integration</h1>
            
            <div class="mailblaze-welcome-content">
                <h2>Let's get started!</h2>
                <p>Connect your WooCommerce store with Mail Blaze to:</p>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li>Automatically sync customer data</li>
                    <li>Track order information</li>
                    <!-- <li>Create targeted email campaigns</li> -->
                    <li>Automate your marketing efforts</li>
                </ul>

                <div class="mailblaze-setup-steps">
                    <h3>Quick Setup Guide:</h3>
                    <ol>
                        <li>Enter your Mail Blaze API key below</li>
                        <li>Configure your store settings</li>
                        <li>Choose which events to track</li>
                        <li>Start engaging with your customers!</li>
                    </ol>
                </div>

                <form method="post" class="mailblaze-initial-setup">
                    <?php wp_nonce_field('mailblaze_wc_settings_save', 'mailblaze_wc_nonce'); ?>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">
                                <label for="mailblaze_wc_api_key">Mail Blaze API Key</label>
                            </th>
                            <td>
                                <input type="text" id="mailblaze_wc_api_key" name="mailblaze_wc_api_key" class="regular-text" required />
                                <p class="description">
                                    Don't have an API key? <a href="https://control.mailblaze.com/customer/index.php/api-keys/index" target="_blank">Generate one here</a>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Connect to Mail Blaze', 'primary', 'mailblaze_wc_save_settings'); ?>
                </form>
            </div>

            <style>
                .mailblaze-welcome-content {
                    max-width: 800px;
                    margin: 20px 0;
                    background: #fff;
                    padding: 25px;
                    border-radius: 5px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                }
                .mailblaze-setup-steps {
                    margin: 25px 0;
                    padding: 20px;
                    background: #f8f9fa;
                    border-radius: 4px;
                }
                .mailblaze-initial-setup {
                    margin-top: 30px;
                    padding-top: 20px;
                    border-top: 1px solid #eee;
                }
            </style>
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

        // Save SMTP settings
        $use_smtp = isset($_POST['mailblaze_wc_use_smtp']) ? '1' : '0';
        update_option('mailblaze_wc_use_smtp', $use_smtp);

        if ($use_smtp === '1') {
            // Save SMTP configuration
            $smtp_fields = [
                'mailblaze_wc_smtp_username' => 'text',
                'mailblaze_wc_smtp_from_email' => 'email',
                'mailblaze_wc_smtp_from_name' => 'text'
            ];

            foreach ($smtp_fields as $field => $type) {
                if (isset($_POST[$field])) {
                    $value = $_POST[$field];
                    switch ($type) {
                        case 'email':
                            $value = sanitize_email($value);
                            break;
                        case 'number':
                            $value = absint($value);
                            break;
                        default:
                            $value = sanitize_text_field($value);
                    }
                    update_option($field, $value);
                }
            }

            // Verify SMTP settings are complete
            $required_smtp_fields = ['mailblaze_wc_smtp_username'];
            $missing_fields = [];

            foreach ($required_smtp_fields as $field) {
                if (empty($_POST[$field])) {
                    $missing_fields[] = str_replace('mailblaze_wc_smtp_', '', $field);
                }
            }

            if (!empty($missing_fields)) {
                add_settings_error(
                    'mailblaze_wc_errors',
                    'smtp_incomplete',
                    sprintf(
                        'SMTP configuration is incomplete. Required fields missing: %s',
                        implode(', ', $missing_fields)
                    ),
                    'error'
                );
            }
        }

        // Update store information in Mail Blaze
        $this->update_store_in_mailblaze();

        // Add a success message
        add_settings_error('mailblaze_wc_success', 'settings_updated', 'Settings saved successfully.', 'updated');
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
            $response = $api_client->save_store($data);

            if ($response && isset($response['store_id'])) {
                add_settings_error('mailblaze_wc_success', 'store_updated', 'Store information updated successfully in Mail Blaze.', 'updated');
            } else {
                add_settings_error('mailblaze_wc_errors', 'update_failed', 'Failed to update store information in Mail Blaze.', 'error');
            }
        } catch (Exception $e) {
            add_settings_error('mailblaze_wc_errors', 'update_failed', 'Failed to update store information in Mail Blaze: ' . $e->getMessage(), 'error');
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
            echo '<div class="notice notice-error"><p>Please enter your Mail Blaze API key in the main settings page before registering a store.</p></div>';
            return;
        }

        // Instantiate API client
        $api_client = new Mailblaze_WC_API_Client($api_key);

        // Fetch mailing lists
        $mailing_lists = $api_client->get_mailing_lists();

        if (empty($mailing_lists)) {
            echo '<div class="notice notice-error"><p>No mailing lists found in your Mail Blaze account. Please create a mailing list before registering a store.</p></div>';
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
            <h1>Register Store with Mail Blaze</h1>
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
            // Send registration/update request to Mail Blaze
            $api_client = new Mailblaze_WC_API_Client($api_key);
            $response = $api_client->save_store($data);

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









