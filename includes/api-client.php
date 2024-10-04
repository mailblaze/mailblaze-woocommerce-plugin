<?php
// Exit if accessed directly
if (! defined('ABSPATH')) {
    exit;
}

class Mailblaze_WC_API_Client
{
    private $api_key;
    private $api_base = 'http://commerce:3000/commerce'; // Replace with actual API base URL

    public function __construct($api_key)
    {
        $this->api_key = $api_key;
    }

    private function request($endpoint, $method = 'GET', $data = [])
    {
        $url = $this->api_base . $endpoint;

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ];

        if (! empty($data)) {
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
            return false;
        }
    }


    public function get_stores()
    {
        $response = $this->request('/stores');
        return $response ? $response['stores'] : [];
    }

    public function register_store($data)
    {
        $response = $this->request('/stores/register', 'POST', $data);
        return $response ? $response['store'] : false;
    }

    public function get_mailing_lists()
    {
        $response = $this->request('/lists');
        return $response ? $response['lists'] : [];
    }

    public function send_event($data)
    {
        $response = $this->request('/events', 'POST', $data);
        return $response ? $response : false;
    }
}
