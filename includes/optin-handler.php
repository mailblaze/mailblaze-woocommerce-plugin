<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Mailblaze_WC_Optin_Handler {
    public function __construct() {
        add_action('init', [$this, 'init']);
    }

    public function init() {
        if (get_option('mailblaze_wc_enable_optin', '0') === '1') {
            add_action('woocommerce_register_form', [$this, 'add_registration_optin_field'], 20);
            add_action('woocommerce_edit_account_form', [$this, 'add_account_optin_field'], 20);
            add_action('woocommerce_created_customer', [$this, 'save_optin_field']);
            add_action('woocommerce_save_account_details', [$this, 'save_optin_field']);
            
            // Add debugging
            add_action('wp_footer', [$this, 'debug_optin_status']);
        }
    }

    public function add_registration_optin_field() {
        error_log('Mailblaze: Adding registration opt-in field');
        woocommerce_form_field('mailblaze_optin', [
            'type'        => 'checkbox',
            'class'       => ['form-row privacy'],
            'label_class' => ['woocommerce-form__label woocommerce-form__label-for-checkbox checkbox'],
            'input_class' => ['woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'],
            'label'       => 'Subscribe to our newsletter',
            'required'    => false,
        ]);
    }

    public function add_account_optin_field() {
        error_log('Mailblaze: Adding account opt-in field');
        $user_id = get_current_user_id();
        $optin = get_user_meta($user_id, 'mailblaze_optin', true);
        
        woocommerce_form_field('mailblaze_optin', [
            'type'        => 'checkbox',
            'class'       => ['form-row privacy'],
            'label_class' => ['woocommerce-form__label woocommerce-form__label-for-checkbox checkbox'],
            'input_class' => ['woocommerce-form__input woocommerce-form__input-checkbox input-checkbox'],
            'label'       => 'Subscribe to our newsletter',
            'required'    => false,
            'default'     => $optin,
        ]);
    }

    public function save_optin_field($user_id) {
        error_log('Mailblaze: Saving opt-in field for user ' . $user_id);
        $new_optin = isset($_POST['mailblaze_optin']) ? 'yes' : 'no';
        $old_optin = get_user_meta($user_id, 'mailblaze_optin', true);

        update_user_meta($user_id, 'mailblaze_optin', $new_optin);

        $user = get_userdata($user_id);
        $api_key = get_option('mailblaze_wc_api_key', '');
        $mailing_list_id = get_option('mailblaze_wc_mailing_list_id', '');

        if (!empty($api_key) && !empty($mailing_list_id)) {
            $api_client = new Mailblaze_WC_API_Client($api_key);
            $user_data = [
                'user_id'    => $user_id,
                'email'      => $user->user_email,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
            ];

            if ($new_optin === 'yes' && $old_optin !== 'yes') {
                // User opted in
                $data = [
                    'event_type' => 'user_optin',
                    'user' => $user_data,
                    'mailing_list_id' => $mailing_list_id,
                ];
                try {
                    $api_client->send_event('user_optin', $data);
                    error_log('Mailblaze: Sent user_optin event for user ' . $user_id);
                } catch (Exception $e) {
                    error_log('Mailblaze Integration Error (Opt-in): ' . $e->getMessage());
                }
            } elseif ($new_optin === 'no' && $old_optin === 'yes') {
                // User opted out
                $data = [
                    'event_type' => 'user_unsubscribe',
                    'user' => $user_data,
                    'mailing_list_id' => $mailing_list_id,
                ];
                try {
                    $api_client->send_event('user_unsubscribe', $data);
                    error_log('Mailblaze: Sent user_unsubscribe event for user ' . $user_id);
                } catch (Exception $e) {
                    error_log('Mailblaze Integration Error (Unsubscribe): ' . $e->getMessage());
                }
            }
        }
    }

    public function debug_optin_status() {
        error_log('Mailblaze: Opt-in status - ' . get_option('mailblaze_wc_enable_optin', '0'));
    }
}
