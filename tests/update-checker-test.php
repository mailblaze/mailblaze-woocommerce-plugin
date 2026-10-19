<?php
/**
 * PUC loads against the public GitHub mirror and notices a newer release.
 * HTTP is stubbed. Nothing is downloaded and no release is created.
 */
define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'MAILBLAZE_WC_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WP_DEBUG', false );
define( 'WP_PLUGIN_DIR', dirname( dirname( __DIR__ ) ) );
define( 'WPMU_PLUGIN_DIR', dirname( __DIR__ ) . '/mu-plugins-absent' );

$GLOBALS['wp_version'] = '6.8.1';
$GLOBALS['mailblaze_http_urls'] = array();
$GLOBALS['mailblaze_site_options'] = array();

function mailblaze_assert_same( $expected, $actual, $message ) {
    if ( $expected !== $actual ) {
        fwrite( STDERR, $message . " expected " . var_export( $expected, true ) . " got " . var_export( $actual, true ) . "\n" );
        if ( ! empty( $GLOBALS['mailblaze_http_urls'] ) ) {
            fwrite( STDERR, "requests:\n" . implode( "\n", $GLOBALS['mailblaze_http_urls'] ) . "\n" );
        }
        exit( 1 );
    }
}

function mailblaze_assert_true( $actual, $message ) {
    if ( $actual !== true ) {
        fwrite( STDERR, $message . " expected true got " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
}

class WP_Error {
    public function __construct( $code = '', $message = '' ) {
        $this->code = $code;
        $this->message = $message;
    }
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}

function plugin_basename( $file ) {
    $file = wp_normalize_path( $file );
    $plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
    $file = preg_replace( '#^' . preg_quote( $plugin_dir, '#' ) . '/#', '', $file );
    return trim( $file, '/' );
}

function esc_html( $text ) {
    return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
    return (string) $url;
}

function balanceTags( $text, $force = false ) {
    unset( $force );
    return $text;
}

function get_available_languages() {
    return array();
}

function wp_kses( $text, $allowed = array() ) {
    unset( $allowed );
    return $text;
}

function wp_doing_cron() {
    return false;
}

function wp_normalize_path( $path ) {
    return str_replace( '\\', '/', $path );
}

function trailingslashit( $value ) {
    return rtrim( $value, '/\\' ) . '/';
}

function plugins_url( $path = '', $plugin = '' ) {
    unset( $plugin );
    return 'http://example.test/wp-content/plugins/' . ltrim( $path, '/' );
}

function wp_parse_url( $url, $component = -1 ) {
    return parse_url( $url, $component );
}

function add_query_arg( $args, $url ) {
    if ( ! is_array( $args ) || empty( $args ) ) {
        return $url;
    }
    $separator = ( strpos( $url, '?' ) === false ) ? '?' : '&';
    return $url . $separator . http_build_query( $args );
}

function apply_filters( $tag, $value ) {
    return $value;
}

function add_filter() {
}

function add_action() {
}

function remove_filter() {
}

function remove_action() {
}

function did_action() {
    return 0;
}

function do_action() {
}

function register_deactivation_hook() {
}

function wp_next_scheduled() {
    return false;
}

function wp_schedule_event() {
}

function wp_clear_scheduled_hook() {
}

function get_site_option( $name, $default = false ) {
    if ( array_key_exists( $name, $GLOBALS['mailblaze_site_options'] ) ) {
        return $GLOBALS['mailblaze_site_options'][ $name ];
    }
    return $default;
}

function update_site_option( $name, $value ) {
    $GLOBALS['mailblaze_site_options'][ $name ] = $value;
    return true;
}

function __( $text ) {
    return $text;
}

function translate( $text ) {
    return $text;
}

function _cleanup_header_comment( $str ) {
    return trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $str ) );
}

function get_plugin_data( $file ) {
    $content = file_get_contents( $file );
    $headers = array(
        'Name' => 'Plugin Name',
        'PluginURI' => 'Plugin URI',
        'Version' => 'Version',
        'Description' => 'Description',
        'Author' => 'Author',
        'AuthorURI' => 'Author URI',
        'TextDomain' => 'Text Domain',
    );
    $data = array();
    foreach ( $headers as $key => $name ) {
        if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $content, $match ) ) {
            $data[ $key ] = _cleanup_header_comment( $match[1] );
        } else {
            $data[ $key ] = '';
        }
    }
    return $data;
}

function wp_remote_retrieve_response_code( $response ) {
    return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
    return $response['body'];
}

function mailblaze_json_response( $payload ) {
    return array(
        'response' => array( 'code' => 200 ),
        'body' => json_encode( $payload ),
    );
}

function wp_remote_get( $url ) {
    $GLOBALS['mailblaze_http_urls'][] = $url;
    $parts = parse_url( $url );
    $path = isset( $parts['path'] ) ? $parts['path'] : '';
    $remote = getenv( 'MAILBLAZE_TEST_REMOTE_VERSION' );
    if ( $remote === false || $remote === '' ) {
        $remote = '9.9.9';
    }
    $asset = 'https://github.com/mailblaze/mailblaze-woocommerce-plugin/releases/download/v' . $remote . '/mailblaze-woocommerce-integration.zip';

    if ( substr( $path, -strlen( '/releases/latest' ) ) === '/releases/latest' ) {
        return mailblaze_json_response( array(
            'tag_name' => 'v' . $remote,
            'draft' => false,
            'prerelease' => false,
            'created_at' => '2026-10-19T12:00:00Z',
            'body' => "Test release " . $remote,
            'zipball_url' => 'https://api.github.com/repos/mailblaze/mailblaze-woocommerce-plugin/zipball/v' . $remote,
            'assets' => array(
                array(
                    'name' => 'notes.txt',
                    'browser_download_url' => 'https://github.com/mailblaze/mailblaze-woocommerce-plugin/releases/download/v' . $remote . '/notes.txt',
                    'url' => 'https://api.github.com/repos/mailblaze/mailblaze-woocommerce-plugin/releases/assets/1',
                    'download_count' => 1,
                ),
                array(
                    'name' => 'mailblaze-woocommerce-integration.zip',
                    'browser_download_url' => $asset,
                    'url' => 'https://api.github.com/repos/mailblaze/mailblaze-woocommerce-plugin/releases/assets/2',
                    'download_count' => 4,
                ),
            ),
        ) );
    }

    if ( strpos( $path, '/contents/mailblaze-woocommerce-integration.php' ) !== false ) {
        $header = "<?php\n/**\n * Plugin Name: Mailblaze WooCommerce Integration\n * Version:     {$remote}\n */\n";
        return mailblaze_json_response( array(
            'encoding' => 'base64',
            'content' => base64_encode( $header ),
        ) );
    }

    if ( strpos( $path, '/contents/readme.txt' ) !== false ) {
        $readme = "=== Mailblaze WooCommerce Integration ===\nStable tag: {$remote}\n\nSimulated release.\n\n== Description ==\n\nSimulated details.\n\n== Changelog ==\n\n= {$remote} =\n* Simulated release notes for the details view.\n";
        return mailblaze_json_response( array(
            'encoding' => 'base64',
            'content' => base64_encode( $readme ),
        ) );
    }

    return array(
        'response' => array( 'code' => 404 ),
        'body' => '{}',
    );
}

require dirname( __DIR__ ) . '/includes/update-checker.php';

$remote = getenv( 'MAILBLAZE_TEST_REMOTE_VERSION' );
if ( $remote === false || $remote === '' ) {
    $remote = '9.9.9';
}

$checker = mailblaze_wc_register_update_checker();
mailblaze_assert_same(
    'https://github.com/mailblaze/mailblaze-woocommerce-plugin/',
    $checker->metadataUrl,
    'update checker points at the public GitHub mirror'
);
mailblaze_assert_same( '1.1.2', $checker->getInstalledVersion(), 'installed header stays 1.1.2 on this branch' );

$update = $checker->checkForUpdates();
$updates = new stdClass();
$updates->response = array();
$injected = $checker->injectUpdate( $updates );

$saw_latest_release = false;
foreach ( $GLOBALS['mailblaze_http_urls'] as $url ) {
    if ( strpos( $url, 'https://api.github.com/repos/mailblaze/mailblaze-woocommerce-plugin/releases/latest' ) === 0 ) {
        $saw_latest_release = true;
    }
}
mailblaze_assert_true( $saw_latest_release, 'PUC asked GitHub for the latest release' );

if ( version_compare( $remote, '1.1.2', '>' ) ) {
    mailblaze_assert_true( is_object( $update ), 'a newer release is detected' );
    mailblaze_assert_same( $remote, $update->version, 'detected version' );
    $asset = 'https://github.com/mailblaze/mailblaze-woocommerce-plugin/releases/download/v' . $remote . '/mailblaze-woocommerce-integration.zip';
    mailblaze_assert_same( $asset, $update->download_url, 'download uses the release zip asset' );
    mailblaze_assert_true( strpos( $update->download_url, 'zipball' ) === false, 'source zipball is not the package' );

    $key = null;
    foreach ( $injected->response as $plugin_file => $wp_update ) {
        $key = $plugin_file;
        mailblaze_assert_same( $remote, $wp_update->new_version, 'WordPress update list version' );
        mailblaze_assert_same( $asset, $wp_update->package, 'WordPress update package' );
        mailblaze_assert_same( 'mailblaze-woocommerce-integration', $wp_update->slug, 'WordPress update slug' );
    }
    mailblaze_assert_true( is_string( $key ) && $key !== '', 'the update is listed for this plugin' );
} else {
    mailblaze_assert_same( null, $update, 'same or older release is not an update' );
    mailblaze_assert_same( array(), $injected->response, 'WordPress update list stays empty' );
}

echo "ok {$remote}\n";
