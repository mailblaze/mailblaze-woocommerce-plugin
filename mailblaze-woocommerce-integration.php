<?php

/**
 * Plugin Name: Mailblaze WooCommerce Integration
 * Plugin URI:  https://mailblaze.com/
 * Description: Integrates WooCommerce with Mailblaze.
 * Version:     1.0.0
 * Author:      Stephan Wessels
 * Author URI:  https://mailblaze.com/
 * License:     GPL2
 * Text Domain: mailblaze-woocommerce-integration
 */

// Exit if accessed directly
if (! defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MAILBLAZE_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MAILBLAZE_WC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include necessary files
require_once MAILBLAZE_WC_PLUGIN_DIR . 'includes/admin-settings.php';
require_once MAILBLAZE_WC_PLUGIN_DIR . 'includes/api-client.php';
require_once MAILBLAZE_WC_PLUGIN_DIR . 'includes/event-handler.php';

// Initialize classes
new Mailblaze_WC_Admin_Settings();
new Mailblaze_WC_Event_Handler();
