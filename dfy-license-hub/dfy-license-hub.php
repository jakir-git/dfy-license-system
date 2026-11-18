<?php
/**
 * Plugin Name: DFY License Hub
 * Description: Central control for BuddyBoss DFY premium licenses. Starts each site's 1-year period when it connects, tells the DFY License Manager on the client site when to remove licenses, and removes them directly from the vendors if the site stops reporting.
 * Version:     1.12.0
 * Author:      BuddyBoss DFY
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: dfy-license-hub
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DFY_LH_VERSION', '1.12.0' );
define( 'DFY_LH_FILE', __FILE__ );
define( 'DFY_LH_DIR', __DIR__ );

require_once DFY_LH_DIR . '/includes/class-dfy-crypto.php';
require_once DFY_LH_DIR . '/includes/class-dfy-license-remote.php';
require_once DFY_LH_DIR . '/includes/class-lh-store.php';
require_once DFY_LH_DIR . '/includes/class-lh-engine.php';
require_once DFY_LH_DIR . '/includes/class-lh-asana.php';
require_once DFY_LH_DIR . '/includes/class-lh-rest.php';
require_once DFY_LH_DIR . '/includes/class-lh-admin.php';

register_activation_hook( __FILE__, function () {
    DFY_LH_Store::install();
    if ( ! wp_next_scheduled( 'dfy_lh_daily' ) ) wp_schedule_event( time() + 300, 'daily', 'dfy_lh_daily' );
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'dfy_lh_daily' );
} );

add_action( 'plugins_loaded', function () {
    if ( get_option( 'dfy_lh_db_version' ) !== DFY_LH_VERSION ) {
        $from = (string) get_option( 'dfy_lh_db_version', '' );
        DFY_LH_Store::install();
        if ( $from !== '' && version_compare( $from, '1.1.0', '<' ) ) DFY_LH_Store::upgrade_110();
        if ( $from !== '' && version_compare( $from, '1.8.0', '<' ) ) DFY_LH_Store::save_license( 'elementor-pro', '', '' ); // Elementor is Connect & Activate only
        if ( $from !== '' && version_compare( $from, '1.11.0', '<' ) ) {
            // Older projects (date found on the site, or switched from a reference entry): keep their licenses.
            foreach ( DFY_LH_Store::all() as $x ) {
                // A start date set by hand means the normal rule applies (licenses end on the date shown).
                if ( $x['status'] !== 'reference' && $x['start_source'] !== 'manual' && ( ! empty( $x['flags']['detect_start'] ) || $x['start_source'] === 'completed' ) && empty( $x['flags']['keep_licenses'] ) ) {
                    $x['flags']['keep_licenses'] = 1; DFY_LH_Store::update( (int) $x['id'], [ 'flags' => $x['flags'] ] );
                    DFY_LH_Store::log( (int) $x['id'], 'Older project: licenses are kept (never removed automatically).' );
                }
            }
        }
    }
} );

add_action( 'plugins_loaded', [ 'DFY_LH_Store', 'maybe_reencrypt' ], 20 );
add_action( 'dfy_lh_daily', [ 'DFY_LH_Engine', 'daily' ] );

// WP-Cron self-test: Settings → "Send test email through WP-Cron".
add_action( 'dfy_lh_cron_test', function ( $to, $queued ) {
    $ok = wp_mail( $to, '[DFY Licenses] WP-Cron test', "This email was sent by WP-Cron on " . home_url() . ".\n\nQueued: " . gmdate( 'Y-m-d H:i:s', (int) $queued ) . " UTC\nSent:   " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\nThe daily DFY license check runs the same way." );
    update_option( 'dfy_lh_cron_test', [ 'queued' => (int) $queued, 'ran' => time(), 'to' => $to, 'mail' => (bool) $ok ], false );
}, 10, 2 );
add_action( 'rest_api_init', [ 'DFY_LH_Rest', 'routes' ] );
// If the hub site uses BuddyBoss "Private REST APIs", client sites must still reach these (they check their own code / signature).
add_filter( 'bb_exclude_endpoints_from_restriction', function ( $endpoints ) {
    return array_merge( (array) $endpoints, [ '/dfy-lh/v1/connect', '/dfy-lh/v1/claim', '/dfy-lh/v1/heartbeat', '/dfy-lh/v1/event' ] );
} );
if ( is_admin() ) DFY_LH_Admin::init();
