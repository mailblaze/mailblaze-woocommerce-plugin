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

    public function debug_optin_status()
    {
        error_log('Mailblaze: Opt-in status - ' . get_option('mailblaze_wc_enable_optin', '0'));
    }
}
