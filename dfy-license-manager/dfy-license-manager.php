<?php
/**
 * Plugin Name: DFY License Manager
 * Description: Activates the premium plugin licenses included with your BuddyBoss Done-For-You project for one year. Deactivating or deleting this plugin removes those licenses.
 * Version:     1.9.1
 * Author:      BuddyBoss DFY
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: dfy-license-manager
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DFY_LM_VERSION', '1.9.1' );
define( 'DFY_LM_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-dfy-crypto.php';
require_once __DIR__ . '/includes/class-dfy-license-remote.php';
require_once __DIR__ . '/includes/class-lm-vendors.php';
require_once __DIR__ . '/includes/class-lm-client.php';
require_once __DIR__ . '/includes/class-lm-admin.php';

register_activation_hook( __FILE__, function () {
    if ( ! wp_next_scheduled( 'dfy_lm_check' ) ) wp_schedule_event( time() + 600, 'twicedaily', 'dfy_lm_check' );
    wp_schedule_single_event( time(), 'dfy_lm_auto_connect' ); // connect to the hub by itself
} );

add_action( 'dfy_lm_auto_connect', function () { DFY_LM_Client::auto_connect(); } );

/** Keep page caches (LiteSpeed, Cloudflare, …) away from the connection endpoints. */
function dfy_lm_nocache(): void {
    do_action( 'litespeed_control_set_nocache', 'DFY License Manager connection' );
    if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
    nocache_headers();
}

// BuddyBoss "Private REST APIs" blocks REST for logged-out visitors; the hub must still reach these two.
// (They are safe in public: the challenge is one-time and random, ping only makes the site call its own hub.)
add_filter( 'bb_exclude_endpoints_from_restriction', function ( $endpoints ) {
    return array_merge( (array) $endpoints, [ '/dfy-lm/v1/challenge', '/dfy-lm/v1/ping' ] );
} );

// Endpoints the hub calls: read back the connection challenge, and "connect now" right after Add site.
add_action( 'rest_api_init', function () {
    register_rest_route( 'dfy-lm/v1', '/challenge', [
        'methods' => 'GET,POST', 'permission_callback' => '__return_true',
        'callback' => function () {
            dfy_lm_nocache();
            $c = DFY_LM_Client::challenge();
            $r = new WP_REST_Response( $c === '' ? [ 'code' => 'dfy_none', 'message' => 'No connection in progress.' ] : [ 'challenge' => $c ], $c === '' ? 404 : 200 );
            $r->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
            $r->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
            return $r;
        },
    ] );
    register_rest_route( 'dfy-lm/v1', '/ping', [
        'methods' => 'POST', 'permission_callback' => '__return_true', // only makes this site contact its own configured hub
        'callback' => function () {
            dfy_lm_nocache();
            return DFY_LM_Client::handle_ping();
        },
    ] );
} );

// Customer deactivates the plugin (also runs before WordPress lets them delete it).
register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'dfy_lm_check' );
    if ( DFY_LM_Client::connected() ) DFY_LM_Client::remove_all( 'plugin_deactivated' );
} );

add_action( 'dfy_lm_check', [ 'DFY_LM_Client', 'heartbeat' ] );

// Elementor Pro was just activated ("Connect & Activate"): check in within a minute so it is taken over.
foreach ( [ 'add_option_elementor_pro_license_key', 'update_option_elementor_pro_license_key' ] as $h ) {
    add_action( $h, function () {
        if ( DFY_LM_Client::connected() && ! wp_next_scheduled( 'dfy_lm_check_soon' ) ) wp_schedule_single_event( time() + 30, 'dfy_lm_check_soon' );
    } );
}
add_action( 'dfy_lm_check_soon', [ 'DFY_LM_Client', 'heartbeat' ] );

// Safety net when WP-Cron is not running: check in from wp-admin at most once a day.
add_action( 'admin_init', function () {
    if ( wp_doing_ajax() ) return;
    if ( ! DFY_LM_Client::connected() || DFY_LM_Client::is_copy() ) {
        // Not connected yet: try in the background (WP-Cron), at most every 10 minutes.
        if ( ! get_transient( 'dfy_lm_claim_wait' ) && ! wp_next_scheduled( 'dfy_lm_auto_connect' ) ) wp_schedule_single_event( time(), 'dfy_lm_auto_connect' );
        return;
    }
    if ( ! wp_next_scheduled( 'dfy_lm_check' ) ) wp_schedule_event( time() + 600, 'twicedaily', 'dfy_lm_check' );
    $c = DFY_LM_Client::get();
    if ( time() - (int) $c['last_ok'] > DAY_IN_SECONDS && ! get_transient( 'dfy_lm_admin_check' ) ) {
        set_transient( 'dfy_lm_admin_check', 1, 6 * HOUR_IN_SECONDS );
        DFY_LM_Client::heartbeat();
    }
} );

if ( is_admin() ) DFY_LM_Admin::init();
