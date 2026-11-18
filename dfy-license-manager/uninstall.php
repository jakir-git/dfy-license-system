<?php
/**
 * Runs when the plugin is deleted from wp-admin. The deactivation hook has
 * normally removed the licenses already; this is the last chance if it could not.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

if ( ! defined( 'DFY_LM_FILE' ) ) define( 'DFY_LM_FILE', __DIR__ . '/dfy-license-manager.php' );
if ( ! defined( 'DFY_LM_VERSION' ) ) define( 'DFY_LM_VERSION', '1.0.0' );
require_once __DIR__ . '/includes/class-dfy-crypto.php';
require_once __DIR__ . '/includes/class-dfy-license-remote.php';
require_once __DIR__ . '/includes/class-lm-vendors.php';
require_once __DIR__ . '/includes/class-lm-client.php';

if ( DFY_LM_Client::connected() ) DFY_LM_Client::remove_all( 'plugin_uninstalled' );

delete_option( 'dfy_lm' );
delete_option( 'dfy_lm_last_removal' );
delete_option( 'dfy_lm_claim_error' );
wp_clear_scheduled_hook( 'dfy_lm_check' );
