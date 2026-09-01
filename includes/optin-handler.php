<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Mailblaze_WC_Optin_Handler
{
    private $mailing_list_id;
    public function __construct()
    {
        $this->mailing_list_id = get_option('mailblaze_wc_mailing_list', '');
        add_action('init', [$this, 'init']);
    }

    public function init()
    {
        if (get_option('mailblaze_wc_enable_optin', '0') === '1') {
            add_action('woocommerce_register_form', [$this, 'add_registration_optin_field'], 20);
            add_action('woocommerce_edit_account_form', [$this, 'add_account_optin_field'], 20);
            add_action('woocommerce_created_customer', [$this, 'save_optin_field']);
            add_action('woocommerce_save_account_details', [$this, 'save_optin_field']);

            // Checkout opt-in for guests. Must render INSIDE form.checkout or the
            // value is never posted. woocommerce_before_checkout_form is outside
            // the form — that was why Mail Blaze never received checkout opt-ins.
            // Do not hook order-review actions: that fragment is AJAX-refreshed
            // and would paint a second checkbox under "Your order".
            add_action('woocommerce_checkout_before_customer_details', [$this, 'add_checkout_optin_field'], 10);
            add_action('woocommerce_before_checkout_billing_form', [$this, 'add_checkout_optin_field'], 10);
            add_action('woocommerce_after_checkout_billing_form', [$this, 'add_checkout_optin_field'], 10);
            add_action('woocommerce_after_checkout_registration_form', [$this, 'add_checkout_optin_field'], 10);
            add_action('woocommerce_checkout_after_customer_details', [$this, 'add_checkout_optin_field'], 10);
            add_action('woocommerce_after_order_notes', [$this, 'add_checkout_optin_field'], 10);

            add_action('woocommerce_checkout_update_order_meta', [$this, 'save_checkout_optin_field'], 10, 1);
            add_action('woocommerce_checkout_order_processed', [$this, 'save_checkout_optin_field'], 10, 1);

            // Add debugging
            add_action('wp_footer', [$this, 'debug_optin_status']);
        }
    }

    public function add_registration_optin_field()
    {
        error_log('Mailblaze: Adding registration opt-in field');
        woocommerce_form_field('mailblaze_optin', [
            'type' => 'checkbox',
            'class' => ['form-row privacy'],
            'label_class' => ['woocommerce-form__label woocommerce-form__label-for-checkbox checkbox'],
            'input_class' => ['woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'],
            'label' => 'Subscribe to our newsletter',
            'required' => false,
        ]);
    }

    public function add_account_optin_field()
    {
        error_log('Mailblaze: Adding account opt-in field');
        $user_id = get_current_user_id();
        $optin = get_user_meta($user_id, 'mailblaze_optin', true);
        woocommerce_form_field('mailblaze_optin', [
            'type' => 'checkbox',
            'class' => ['form-row privacy'],
            'label_class' => ['woocommerce-form__label woocommerce-form__label-for-checkbox checkbox'],
            'input_class' => ['woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'],
            'label' => 'Subscribe to our newsletter',
            'required' => false,
            'default' => $optin === 'yes' ? TRUE : FALSE,
        ]);
    }

    /**
     * Add opt-in checkbox to checkout page for non-logged-in customers
     */
    public function add_checkout_optin_field()
    {
        // Only show for non-logged-in users
        if (!is_user_logged_in()) {
            // WooCommerce refreshes the order table in a separate AJAX request.
            // Never render into that fragment or a second box appears under "Your order".
            $wc_ajax = isset($_REQUEST['wc-ajax']) ? sanitize_text_field(wp_unslash($_REQUEST['wc-ajax'])) : '';
            if ($wc_ajax === 'update_order_review') {
                return;
            }

            // Prevent duplicate checkboxes by checking if we've already rendered it
            static $already_rendered = false;
            if ($already_rendered) {
                error_log('Mailblaze: Checkout opt-in already rendered, skipping');
                return;
            }
            $already_rendered = true;
            
            // Log which hook triggered this
            $current_hook = current_action();
            error_log('Mailblaze: Adding checkout opt-in field via hook: ' . $current_hook);
            
            ?>
            <div class="mailblaze-checkout-optin" style="margin: 20px 0 !important; padding: 20px !important; background: #f9f9f9 !important; border: 2px solid #007cba !important; border-radius: 4px !important; clear: both !important;">
                <h3 style="margin-top: 0 !important; margin-bottom: 15px !important; font-size: 18px !important; color: #333 !important;">📧 Marketing Communications</h3>
                <?php
                woocommerce_form_field('mailblaze_checkout_optin', [
                    'type' => 'checkbox',
                    'class' => ['form-row privacy'],
                    'label_class' => ['woocommerce-form__label woocommerce-form__label-for-checkbox checkbox'],
                    'input_class' => ['woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'],
                    'label' => 'I would like to receive marketing emails and updates',
                    'required' => false,
                ]);
                ?>
            </div>
            <?php
        } else {
            error_log('Mailblaze: User is logged in, not showing checkout opt-in field');
        }
    }

    public function save_optin_field($user_id)
    {
        error_log('Mailblaze: Saving opt-in field for user ' . $user_id);
        $new_optin = isset($_POST['mailblaze_optin']) ? 'yes' : 'no';
        $old_optin = get_user_meta($user_id, 'mailblaze_optin', true);
        update_user_meta($user_id, 'mailblaze_optin', $new_optin);

        $user = get_userdata($user_id);
        $api_key = get_option('mailblaze_wc_api_key', '');

        if (!empty($api_key) && !empty($this->mailing_list_id)) {
            try {
                $api_client = new Mailblaze_WC_API_Client($api_key);
                $user_data = [
                    'EMAIL' => $user->user_email,
                    'FNAME' => $user->first_name,
                    'LNAME' => $user->last_name,
                    'list_uid' => $this->mailing_list_id
                ];

                // Search for existing subscriber
                $existing_subscriber = $api_client->search_subscriber_by_email($this->mailing_list_id, $user->user_email);
                if ($new_optin === 'yes' && $old_optin !== 'yes') {
                    $user_data += [
                        'STATUS' => "UNCONFIRMED",
                        "SEND_OPTIN" => true
                    ];
                    // User opted in
                    try {
                        if ($existing_subscriber) {
                            $api_client->resubscribe_subscriber($this->mailing_list_id, $existing_subscriber['subscriber_uid']);
                            $api_client->update_subscriber($this->mailing_list_id, $user_data, $existing_subscriber['subscriber_uid']);
                        } else {
                            $api_client->update_subscriber($this->mailing_list_id, $user_data);
                            // Sleep for 1 second to allow save in MongoDB to complete
                            sleep(1);
                            $api_client->send_event('user_register', $user_data);
                            $api_client->send_event('user_optin', $user_data);
                        }
                    } catch (Exception $e) {
                        error_log('Mailblaze: Failed to update subscriber opt-in status - ' . $e->getMessage());
                    }
                } elseif ($new_optin === 'no' && $old_optin === 'yes') {
                    // User opted out
                    $user_data += [
                        'STATUS' => "UNSUBSCRIBED",
                    ];
                    try {
                        
                        if ($existing_subscriber) {
                            $api_client->update_subscriber($this->mailing_list_id, $user_data, $existing_subscriber['subscriber_uid']);
                            $api_client->unsubscribe_subscriber($this->mailing_list_id, $existing_subscriber['subscriber_uid']);
                        } else {
                            $api_client->update_subscriber($this->mailing_list_id, $user_data);
                        }
                    } catch (Exception $e) {
                        error_log('Mailblaze: Failed to update subscriber opt-out status - ' . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                error_log('Mailblaze: API error - ' . $e->getMessage());
            }
        }
    }

    /**
     * Save opt-in preference from checkout for non-logged-in customers
     * 
     * @param int $order_id The order ID
     */
    public function save_checkout_optin_field($order_id)
    {
        static $already_processed = false;
        if ($already_processed) {
            return;
        }
        $already_processed = true;

        $checkout_optin = !empty($_POST['mailblaze_checkout_optin']) ? 'yes' : 'no';

        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('Mailblaze: Could not retrieve order for checkout opt-in processing');
            return;
        }

        $order->update_meta_data('_mailblaze_checkout_optin', $checkout_optin);
        $order->save();

        if ($checkout_optin !== 'yes') {
            error_log('Mailblaze: Checkout opt-in was not ticked for order ' . $order_id);
            return;
        }
        $customer_email = $order->get_billing_email();
        $customer_first_name = $order->get_billing_first_name();
        $customer_last_name = $order->get_billing_last_name();

        if (empty($customer_email)) {
            error_log('Mailblaze: No customer email found for checkout opt-in');
            return;
        }

        $api_key = get_option('mailblaze_wc_api_key', '');

        if (!empty($api_key) && !empty($this->mailing_list_id)) {
            try {
                $api_client = new Mailblaze_WC_API_Client($api_key);
                $user_data = [
                    'EMAIL' => $customer_email,
                    'FNAME' => $customer_first_name,
                    'LNAME' => $customer_last_name,
                    'list_uid' => $this->mailing_list_id,
                    'STATUS' => "UNCONFIRMED",
                    "SEND_OPTIN" => true
                ];

                $existing_subscriber = $api_client->search_subscriber_by_email($this->mailing_list_id, $customer_email);

                if ($existing_subscriber) {
                    $api_client->resubscribe_subscriber($this->mailing_list_id, $existing_subscriber['subscriber_uid']);
                    $api_client->update_subscriber($this->mailing_list_id, $user_data, $existing_subscriber['subscriber_uid']);
                    error_log('Mailblaze: Updated existing subscriber from checkout: ' . $customer_email);
                } else {
                    $api_client->update_subscriber($this->mailing_list_id, $user_data);
                    error_log('Mailblaze: Created new subscriber from checkout: ' . $customer_email);

                    sleep(1);

                    $api_client->send_event('user_register', $user_data);
                    $api_client->send_event('user_optin', $user_data);
                }
            } catch (Exception $e) {
                error_log('Mailblaze: Failed to process checkout opt-in for ' . $customer_email . ' - ' . $e->getMessage());
            }
        } else {
            error_log('Mailblaze: API key or mailing list not configured for checkout opt-in');
        }
    }

    public function debug_optin_status()
    {
        error_log('Mailblaze: Opt-in status - ' . get_option('mailblaze_wc_enable_optin', '0'));
        
        // Add fallback JavaScript injection for checkout page
        if (is_checkout() && !is_user_logged_in() && get_option('mailblaze_wc_enable_optin', '0') === '1') {
            ?>
            <script type="text/javascript">
            jQuery(document).ready(function($) {
                console.log('Mailblaze: Checkout page detected for non-logged-in user');
                
                // Check if our opt-in checkbox already exists
                if ($('.mailblaze-checkout-optin').length === 0) {
                    console.log('Mailblaze: Opt-in checkbox not found, injecting via JavaScript');
                    
                    // Prefer billing / customer details — never the order-review fragment,
                    // which is where the duplicate under "Your order" used to appear.
                    var targetSelectors = [
                        '#customer_details',
                        '.woocommerce-billing-fields',
                        '[name="billing_country"]',
                        'input[name="billing_email"]',
                        '.checkout_coupon',
                        'form.checkout',
                        'form[name="checkout"]',
                        '.woocommerce-checkout',
                        '.checkout',
                        'form'
                    ];
                    
                    var injected = false;
                    $.each(targetSelectors, function(index, selector) {
                        if (!injected && $(selector).length > 0) {
                            console.log('Mailblaze: Found target selector: ' + selector);
                            
                            var optinHtml = '<div class="mailblaze-checkout-optin" style="margin: 20px 0 !important; padding: 20px !important; background: #f9f9f9 !important; border: 2px solid #007cba !important; border-radius: 4px !important; position: relative !important; z-index: 9999 !important; display: block !important; visibility: visible !important; width: auto !important; height: auto !important;">' +
                                '<h3 style="margin-top: 0 !important; margin-bottom: 15px !important; font-size: 18px !important; color: #333 !important; display: block !important;">📧 Marketing Communications</h3>' +
                                '<p class="form-row privacy" style="margin: 0 !important; display: block !important;">' +
                                '<label class="woocommerce-form__label checkbox" style="display: flex !important; align-items: flex-start !important; gap: 8px !important; cursor: pointer !important; color: #333 !important; font-size: 14px !important;">' +
                                '<input type="checkbox" class="woocommerce-form__input input-checkbox" name="mailblaze_checkout_optin" id="mailblaze_checkout_optin" value="1" style="margin-top: 2px !important; width: 16px !important; height: 16px !important; display: inline-block !important;"> ' +
                                '<span style="display: inline-block !important; line-height: 1.4 !important;">I would like to receive marketing emails and updates</span>' +
                                '</label>' +
                                '</p>' +
                                '</div>';
                            
                            // Try different injection methods based on selector
                            if (selector === 'form' || selector === 'form.checkout' || selector === 'form[name="checkout"]') {
                                // For forms, try to inject before the submit button or at a strategic location
                                var injectionPoints = [
                                    'input[type="submit"]',
                                    'button[type="submit"]',
                                    '.place-order',
                                    '#place_order',
                                    '.woocommerce-checkout-payment',
                                    '#payment'
                                ];
                                
                                var formInjected = false;
                                $.each(injectionPoints, function(i, point) {
                                    if (!formInjected && $(point).length > 0) {
                                        $(point).before(optinHtml);
                                        console.log('Mailblaze: Injected before ' + point + ' inside form');
                                        formInjected = true;
                                        return false;
                                    }
                                });
                                
                                if (!formInjected) {
                                    // If no good injection point found, just append to form
                                    $(selector).append(optinHtml);
                                    console.log('Mailblaze: Appended to form as fallback');
                                }
                            } else if (selector === '.woocommerce-checkout') {
                                // For checkout container, inject at the end
                                $(selector).append(optinHtml);
                                console.log('Mailblaze: Appended opt-in checkbox to ' + selector);
                            } else {
                                // For other elements, inject before
                                $(selector).before(optinHtml);
                                console.log('Mailblaze: Injected opt-in checkbox before ' + selector);
                            }
                            
                            injected = true;
                            return false; // Break the loop
                        }
                    });
                    
                    if (!injected) {
                        console.log('Mailblaze: Could not find suitable injection point, trying body fallback');
                        // Last resort: inject at the top of the page content
                        var fallbackHtml = '<div class="mailblaze-checkout-optin" style="margin: 20px auto; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; max-width: 600px;">' +
                            '<h3 style="margin-top: 0; margin-bottom: 15px;">📧 Marketing Communications</h3>' +
                            '<p class="form-row privacy">' +
                            '<label style="display: flex; align-items: flex-start; gap: 8px; cursor: pointer;">' +
                            '<input type="checkbox" name="mailblaze_checkout_optin" id="mailblaze_checkout_optin" value="1" style="margin-top: 2px;"> ' +
                            '<span>I would like to receive marketing emails and updates</span>' +
                            '</label>' +
                            '</p>' +
                            '</div>';
                        
                        $('body').prepend(fallbackHtml);
                        console.log('Mailblaze: Used fallback injection to body');
                    }
                } else {
                    console.log('Mailblaze: Opt-in checkbox already exists');
                }

                // Order review HTML is replaced on AJAX refresh. Strip any box that
                // lands under "Your order" so only the billing-area checkbox remains.
                $(document.body).on('updated_checkout', function() {
                    $('#order_review .mailblaze-checkout-optin').remove();
                    var $boxes = $('.mailblaze-checkout-optin');
                    if ($boxes.length > 1) {
                        $boxes.slice(1).remove();
                    }
                });
            });
            </script>
            <?php
        }
    }
}
