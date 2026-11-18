<?php
// Runs the hub's real admin-post handlers (Add site, Delete, Check in now) end to end.
$SRC = $argv[1];
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/vendor-stubs.php';
class Redirect extends Exception {}
function current_user_can( $c ) { return true; } function check_admin_referer( $a ) { return true; } function wp_unslash( $s ) { return $s; }
function wp_safe_redirect( $u ) { throw new Redirect( urldecode( urldecode( $u ) ) ); } function wp_get_current_user() { return (object) [ 'user_login' => 'tester' ]; }
require $SRC . '/dfy-license-hub/dfy-license-hub.php';
require $SRC . '/dfy-license-manager/dfy-license-manager.php';
require_once $SRC . '/dfy-license-hub/includes/class-lh-admin.php';
$pass = 0; $fail = 0;
function ok( $c, $l ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? '  ok   ' : '  FAIL ' ) . "$l\n"; }
function act( $fn, $post ) { $_POST = $post; try { DFY_LH_Admin::$fn(); } catch ( Redirect $r ) { return $r->getMessage(); } return 'no redirect'; }
function site( $id ) { return DFY_LH_Store::get( $id ); }
function calls_to( $n ) { return array_values( array_filter( $GLOBALS['calls'], function ( $c ) use ( $n ) { return strpos( $c['url'], $n ) !== false; } ) ); }

DFY_LH_Store::save_settings( [ 'test_mode' => 1 ] );
foreach ( [ 'gravityforms' => 'GF-KEY-2222', 'wp-fusion' => 'WPF-KEY-4444', 'automatorwp-pro' => 'AWP-KEY-6666', 'learndash' => 'LD-KEY-3333' ] as $s => $k ) DFY_LH_Store::save_license( $s, $k, $s === 'learndash' ? 'dfy@example.com' : '' );

echo "Add site -> licenses active instantly (plugin already on the site)\n";
$GLOBALS['home'] = 'https://dfy-demo.example.com';
$r = act( 'add_site', [ 'site_url' => 'https://dfy-demo.example.com/', 'label' => 'Demo', 'asana' => 'https://app.asana.com/1/1/project/9' ] );
$id = (int) DFY_LH_Store::find_by_host( 'https://dfy-demo.example.com' )['id'];
ok( site( $id )['status'] === 'active' && strpos( $r, 'The site connected' ) !== false, 'connected on Add site: ' . substr( $r, strpos( $r, 'dfy_msg=' ) ) );
ok( wpf_get_option( 'license_status' ) === 'valid' && get_site_option( 'nss_plugin_license_sfwd_lms' ) === 'LD-KEY-3333', 'WP Fusion and LearnDash activated' );

echo "No duplicates\n";
foreach ( [ 'https://dfy-demo.example.com', 'https://www.dfy-demo.example.com/', 'http://DFY-Demo.example.com/x' ] as $u ) {
    $r = act( 'add_site', [ 'site_url' => $u, 'label' => 'Again' ] );
    ok( strpos( $r, 'already in the list' ) !== false && count( DFY_LH_Store::all() ) === 1, "refused: $u" );
}

echo "Check in now\n";
$r = act( 'site_action', [ 'site' => $id, 'do' => 'checkin', 'return' => 'list' ] );
ok( strpos( $r, 'checked in' ) !== false && strpos( $r, 'LearnDash LMS: active' ) !== false, 'check-in reports states' );

echo "Elementor: Connect & Activate on the site is taken over\n";
$GLOBALS['umeta'][1]['wp_elementor_connect_common_data'] = [ 'access_token' => jwt( 'dfy-agency' ) ];
update_option( 'elementor_pro_license_key', 'EL-FROM-CONNECT' ); update_option( '_elementor_pro_license_v2_data', [ 'license' => 'valid' ] );
act( 'site_action', [ 'site' => $id, 'do' => 'checkin' ] );
ok( ( site( $id )['plugins']['elementor-pro']['state'] ?? '' ) === 'active' && ( DFY_LH_Store::licenses()['elementor-pro']['key'] ?? '' ) === '', 'Connect & Activate taken over; no agency Elementor key kept' );

echo "Delete = remove licenses, then delete\n";
$GLOBALS['calls'] = []; $GLOBALS['plugin_ops'] = [];
$r = act( 'site_action', [ 'site' => $id, 'do' => 'delete' ] );
ok( strpos( $r, 'deleted; all its DFY licenses were removed' ) !== false && ! site( $id ), 'site deleted: ' . substr( $r, strpos( $r, 'dfy_msg=' ) ) );
ok( get_option( 'rg_gforms_key' ) === '' && get_site_option( 'nss_plugin_license_sfwd_lms', 'gone' ) === 'gone' && get_option( 'elementor_pro_license_key', 'gone' ) === 'gone', 'licenses removed on the site (GF, LearnDash, Elementor)' );
ok( ! isset( $GLOBALS['umeta'][1]['wp_elementor_connect_common_data'] ), 'DFY Elementor account disconnected' );
ok( ! array_filter( $GLOBALS['plugin_ops'], function ( $o ) { return strpos( $o, 'dfy-license-manager' ) === false; } ), 'premium plugins not deactivated' );

echo "Next new site: Elementor shown as needing Connect & Activate (never activated by key)\n";
reset_client( 'https://new.example.com' ); $GLOBALS['umeta'] = [];
$GLOBALS['calls'] = [];
act( 'add_site', [ 'site_url' => 'https://new.example.com', 'label' => 'New' ] );
$n = DFY_LH_Store::find_by_host( 'https://new.example.com' );
ok( ( $n['plugins']['elementor-pro']['state'] ?? '' ) === 'unlicensed' && strpos( $n['plugins']['elementor-pro']['msg'], 'Connect & Activate' ) !== false && ! calls_to( 'license/activate' ), 'Elementor: use Connect & Activate (no key attempt)' );

echo "Closed site added again: same entry reopened, not duplicated\n";
act( 'site_action', [ 'site' => $n['id'], 'do' => 'remove_now', 'return' => 'list' ] );
ok( site( $n['id'] )['status'] === 'removed', 'closed' );
reset_client( 'https://new.example.com' );
$r = act( 'add_site', [ 'site_url' => 'https://new.example.com/', 'label' => 'New again' ] );
$all = array_filter( DFY_LH_Store::all(), function ( $s ) { return DFY_LH_Rest::host( $s['site_url'] ) === 'new.example.com'; } );
ok( count( $all ) === 1 && site( $n['id'] )['status'] === 'active' && site( $n['id'] )['label'] === 'New again', 'reopened and reconnected: ' . substr( $r, strpos( $r, 'dfy_msg=' ) ) );

echo "Activate licenses on a site whose DFY License Manager was reinstalled (lost its connection)\n";
act( 'site_action', [ 'site' => $n['id'], 'do' => 'remove_now', 'return' => 'list' ] );
delete_option( 'dfy_lm' );                      // plugin deleted + reinstalled: no connection data
$r = act( 'site_action', [ 'site' => $n['id'], 'do' => 'activate', 'return' => 'list' ] );
ok( site( $n['id'] )['status'] === 'active' && DFY_LM_Client::connected() && wpf_get_option( 'license_status' ) === 'valid', 'reconnected and licenses active: ' . substr( $r, strpos( $r, 'dfy_msg=' ) ) );

echo "Delete a site that never connected\n";
$GLOBALS['home'] = 'https://nothing-here.example.com'; $GLOBALS['client_plugin_missing'] = true;
act( 'add_site', [ 'site_url' => 'https://never.example.com', 'label' => 'Never' ] );
$v = DFY_LH_Store::find_by_host( 'https://never.example.com' );
$r = act( 'site_action', [ 'site' => $v['id'], 'do' => 'delete' ] );
ok( ! site( $v['id'] ) && strpos( $r, 'Never deleted.' ) !== false, 'deleted' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
