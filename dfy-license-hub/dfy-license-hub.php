<?php
/**
 * Plugin Name: DFY License Hub
 * Description: Central control for BuddyBoss DFY premium licenses. Starts each site's 1-year period when it connects, tells the DFY License Manager on the client site when to remove licenses, and removes them directly from the vendors if the site stops reporting.
 * Version:     1.9.0
 * Author:      BuddyBoss DFY
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: dfy-license-hub
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DFY_LH_VERSION', '1.9.0' );
define( 'DFY_LH_FILE', __FILE__ );
define( 'DFY_LH_DIR', __DIR__ );

require_once DFY_LH_DIR . '/includes/class-dfy-crypto.php';
require_once DFY_LH_DIR . '/includes/class-dfy-license-remote.php';
require_once DFY_LH_DIR . '/includes/class-lh-store.php';
require_once DFY_LH_DIR . '/includes/class-lh-engine.php';
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
        if ( $from !== '' && version_compare( $from, '1.9.0', '<' ) ) DFY_LH_Store::save_license( 'elementor-pro', '', '' ); // Elementor is Connect & Activate only
    }
} );

add_action( 'dfy_lh_daily', [ 'DFY_LH_Engine', 'daily' ] );
add_action( 'rest_api_init', [ 'DFY_LH_Rest', 'routes' ] );
if ( is_admin() ) DFY_LH_Admin::init();
