<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress update checks for this plugin.
 *
 * The GitHub mirror mailblaze/mailblaze-woocommerce-plugin is public, so
 * installed stores can read releases without a token. The Bitbucket pipeline
 * attaches mailblaze-woocommerce-integration.zip to each release. That asset
 * is the package WordPress installs.
 *
 * Bundled library: YahnisElsts plugin-update-checker 5.7 (MIT).
 *
 * @return \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker
 */
function mailblaze_wc_register_update_checker() {
    if ( ! class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class, false ) ) {
        require_once MAILBLAZE_WC_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
    }

    $update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/mailblaze/mailblaze-woocommerce-plugin/',
        MAILBLAZE_WC_PLUGIN_DIR . 'mailblaze-woocommerce-integration.php',
        'mailblaze-woocommerce-integration'
    );

    $update_checker->getVcsApi()->enableReleaseAssets(
        '/^mailblaze-woocommerce-integration\.zip$/i'
    );

    return $update_checker;
}
