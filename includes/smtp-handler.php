<?php

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Mailblaze_WC_SMTP_Handler {
    // Define SMTP constants
    const SMTP_HOST = 'smtp.mailblaze.net';
    const SMTP_PORT = 2525;

    public function __construct() {
        // Only hook into WordPress if SMTP is enabled
        if (get_option('mailblaze_wc_use_smtp', '0') === '1') {
            add_action('phpmailer_init', [$this, 'configure_smtp'], 10, 1);
            add_filter('wp_mail_from', [$this, 'set_from_email']);
            add_filter('wp_mail_from_name', [$this, 'set_from_name']);
        }
    }

    /**
     * Configure WordPress PHPMailer to use Mail Blaze SMTP
     *
     * @param PHPMailer $phpmailer The PHPMailer instance
     */
    public function configure_smtp($phpmailer) {
        // Set mailer to use SMTP
        $phpmailer->isSMTP();
        
        // Enable SMTP authentication
        $phpmailer->SMTPAuth = true;
        
        // Set SMTP host and port
        $phpmailer->Host = self::SMTP_HOST;
        $phpmailer->Port = self::SMTP_PORT;
        
        // Set SMTP username and password (API key)
        $phpmailer->Username = get_option('mailblaze_wc_smtp_username', '');
        $phpmailer->Password = get_option('mailblaze_wc_api_key', '');
        
        // Additional settings for better compatibility
        $phpmailer->SMTPSecure = '';  // No encryption for port 2525
        $phpmailer->SMTPAutoTLS = false;
        $phpmailer->SMTPKeepAlive = true;

        // Make sure the from email is set
        $phpmailer->From = $this->set_from_email($phpmailer->From);
        
        // Enable debug mode if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $phpmailer->SMTPDebug = 2;
            $phpmailer->Debugoutput = 'error_log';
        }
    }

    /**
     * Set the from email address for outgoing emails
     *
     * @param string $email The current from email
     * @return string The new from email
     */
    public function set_from_email($email) {
        $from_email = get_option('mailblaze_wc_smtp_from_email', '');
        return !empty($from_email) ? $from_email : $email;
    }

    /**s
     * Set the from name for outgoing emails
     *
     * @param string $name The current from name
     * @return string The new from name
     */
    public function set_from_name($name) {
        $from_name = get_option('mailblaze_wc_smtp_from_name', '');
        return !empty($from_name) ? $from_name : $name;
    }

    /**
     * Test SMTP connection
     *
     * @return array Success status and message
     */
    public static function test_smtp_connection() {
        if (get_option('mailblaze_wc_use_smtp', '0') !== '1') {
            return [
                'success' => false,
                'message' => 'SMTP is not enabled in settings.'
            ];
        }

        // Check for required username and API key
        $username = get_option('mailblaze_wc_smtp_username', '');
        $api_key = get_option('mailblaze_wc_api_key', '');

        if (empty($username) || empty($api_key)) {
            return [
                'success' => false,
                'message' => 'Missing required fields: ' . 
                    (empty($username) ? 'SMTP Username' : '') . 
                    (empty($api_key) ? (empty($username) ? ', API Key' : 'API Key') : '')
            ];
        }

        try {
            // Create PHPMailer instance
            require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
            require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
            require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->SMTPAuth = true;
            $mail->Host = self::SMTP_HOST;
            $mail->Port = self::SMTP_PORT;
            $mail->Username = $username;
            $mail->Password = $api_key;
            $mail->SMTPSecure = '';  // No encryption for port 2525
            $mail->SMTPAutoTLS = false;

            // Try to connect
            $mail->smtpConnect();

            return [
                'success' => true,
                'message' => 'SMTP connection test successful!'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'SMTP connection failed: ' . $e->getMessage()
            ];
        }
    }
} 