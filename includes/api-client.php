<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Mailblaze_WC_API_Client
{
    private $api_key;
    private $ecommerce_api_base;
    private $hooks_api_base;
    private $store_foreign_id;

    private $site_domain;

    public function __construct($api_key)
    {
        $this->api_key = $api_key;
        $this->store_foreign_id = get_option('mailblaze_wc_foreign_store_id');
        $this->site_domain = get_site_url();
        // Read base URLs from environment variables
        $this->ecommerce_api_base = getenv('MAILBLAZE_ECOMMERCE_API_BASE') ?: 'https://control.mailblaze.com/api';
        $this->hooks_api_base = getenv('MAILBLAZE_HOOKS_API_BASE') ?: 'https://control.mailblaze.com/api/hooks';
    }

    private function request($endpoint, $method = 'GET', $data = [], $is_ecommerce = true)
    {
        $url = ($is_ecommerce ? $this->ecommerce_api_base : $this->hooks_api_base) . $endpoint;

        $headers = [
            'Content-Type' => 'application/json',
            'X-Store-Id' => $this->store_foreign_id
        ];

        // Set the correct authorization header based on the API type
        if ($is_ecommerce) {
            $headers['authorization'] = $this->api_key;
        } else {
            $headers['Authorization'] = 'Bearer ' . $this->api_key;
        }

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
        ];
        if (!empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code >= 200 && $response_code < 300) {
            $body = wp_remote_retrieve_body($response);
            return json_decode($body, true);
        } elseif ($response_code == 400) {

            $body = wp_remote_retrieve_body($response);
            $decoded_body = json_decode($body, true);
            if (isset($decoded_body['message'])) {
                throw new Exception($decoded_body['message']);
            } else {
                throw new Exception('Bad Request');
            }
        } else {
            $body = wp_remote_retrieve_body($response);
            $decoded_body = json_decode($body, true);
            if (isset($decoded_body['status']) && $decoded_body['status'] === 'error') {
                if (isset($decoded_body['error']['general'])) {
                    throw new Exception($decoded_body['error']['general'], $response_code);
                } else {
                    var_dump($decoded_body);
                    throw new Exception('An error occurred:' . json_encode($decoded_body), $response_code);
                }
            }
            return false;
        }
    }

    public function get_stores()
    {
        $response = $this->request('/ecommerce/store');
        return $response ? $response['stores'] : [];
    }

    public function get_store_by_foreign_id($foreign_store_id)
    {
        $response = $this->request("/ecommerce/store/{$foreign_store_id}", 'GET');
        return $response && isset($response['store']) ? $response['store'] : false;
    }

    public function register_store($data)
    {
        // Ensure required fields are set
        $required_fields = ['foreign_store_id', 'name', 'currency_code'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                throw new Exception("$field is required for store registration.");
            }
        }

        // Generate a secure access token for the store
        $access_token = wp_generate_password(32, false);
        update_option('mailblaze_wc_store_access_token', $access_token);

        // Create the products endpoint configuration
        $products_endpoint = [
            'url' => get_rest_url(null, 'mailblaze/v1/products'),
            'token' => $access_token,
            'method' => 'GET',
            'headers' => [
                'X-Mailblaze-Token' => '$token' // Template variable for Mailblaze to use
            ]
        ];

        // Add the endpoint configuration to the store data
        $data['endpoints'] = [
            'products' => $products_endpoint
        ];

        // Check if the store already exists
        $existing_store = $this->get_store_by_foreign_id($data['foreign_store_id']);
        if ($existing_store) {
            // Update the existing store
            $response = $this->request("/ecommerce/store/{$data['foreign_store_id']}", 'PUT', $data);
        } else {
            // Set default values if not provided
            $data = array_merge([
                'platform' => 'woocommerce',
                'domain' => $this->site_domain,
                'is_syncing' => true,
            ], $data);

            // Register a new store
            $response = $this->request('/ecommerce/store', 'POST', $data);
        }

        if (isset($response['status']) && $response['status'] === 'error') {
            throw new Exception(json_encode($response['error']));
        }

        return $response;
    }

    public function get_mailing_lists()
    {
        $response = $this->request('/lists');
        if ($response && isset($response['status']) && $response['status'] === 'success' && isset($response['data']['records'])) {
            $lists = [];
            foreach ($response['data']['records'] as $record) {
                if (isset($record['general'])) {
                    $lists[] = [
                        'list_uid' => $record['general']['list_uid'],
                        'name' => $record['general']['name'],
                        'display_name' => $record['general']['display_name'],
                        'description' => $record['general']['description'],
                        'subscriber_count' => $record['general']['subscriber_count']
                    ];
                }
            }
            return $lists;
        }
        return [];
    }

    public function send_event($event_type, $data)
    {

        $payload = [
            'event_type' => $event_type,
            'data' => $data,
            'platform' => 'woocommerce'
        ];
        $response = $this->request('/trigger', 'POST', $payload, false);
        if ($response === false) {

            throw new Exception('Failed to send event to Mailblaze API');
        }

        return $response;
    }

    // function to subscribe user to a mailing list
    public function update_subscriber($mailing_list_id, $user_data, $subscriber_uid = null)
    {

        $response = $this->request('/lists/' . $mailing_list_id . '/subscribers' . ($subscriber_uid ? '/' . $subscriber_uid : ''), $subscriber_uid ? 'PUT' : 'POST', $user_data, true);
        return $response ? $response : false;
    }

    public function unsubscribe_subscriber($mailing_list_id, $subscriber_uid)
    {
        $response = $this->request('/lists/' . $mailing_list_id . '/subscribers/' . $subscriber_uid . '/unsubscribe', 'PUT', [], true);
        return $response ? $response : false;
    }

    public function resubscribe_subscriber($mailing_list_id, $subscriber_uid)
    {
        $response = $this->request('/lists/' . $mailing_list_id . '/subscribers/' . $subscriber_uid . '/resubscribe', 'PUT', [], true);
        return $response ? $response : false;
    }

    public function search_subscriber_by_email($mailing_list_id, $email)
    {
        try {
            $response = $this->request('/lists/' . $mailing_list_id . '/subscribers/search-by-email?EMAIL=' . $email, 'GET', [], true);
        } catch (Exception $e) {
            if ($e->getCode() === 404) {
                return false;
            }
            throw $e;
        }               
        return $response ? $response['data'] : false;
    }

    public function sync_products($products)
    {
        $response = $this->request('/ecommerce/products/sync', 'POST', ['products' => $products]);
        return $response ? $response : false;
    }

    public function get_products($page = 1, $limit = 100)
    {
        $response = $this->request("/ecommerce/products?page={$page}&limit={$limit}", 'GET');
        return $response ? $response['products'] : [];
    }
}
