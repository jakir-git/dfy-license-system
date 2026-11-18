<?php
// End-to-end: DFY License Hub + DFY License Manager in one process.
// Usage: php run.php <src_dir>
$SRC = $argv[1];
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/vendor-stubs.php';
require $SRC . '/dfy-license-hub/dfy-license-hub.php';
require $SRC . '/dfy-license-manager/dfy-license-manager.php';
require_once $SRC . '/dfy-license-hub/includes/class-lh-admin.php';

$pass = 0; $fail = 0;
function ok( $cond, $label ) { global $pass, $fail; if ( $cond ) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label\n"; } }
function calls_to( $needle ) { return array_values( array_filter( $GLOBALS['calls'], function ( $c ) use ( $needle ) { return strpos( $c['url'], $needle ) !== false; } ) ); }
function site( $id ) { return DFY_LH_Store::get( $id ); }
// Simulates the DFY team clicking Elementor → License → Connect & Activate on the site.
function el_connect( $k = 'EL-KEY-1111' ) { update_option( 'elementor_pro_license_key', $k ); update_option( '_elementor_pro_license_v2_data', [ 'license' => 'valid' ] ); }
function iso( $days_ago ) { return gmdate( 'Y-m-d\TH:i:s.000\Z', time() - $days_ago * 86400 ); }

// ── Hub setup ──
DFY_LH_Store::save_settings( [ 'notify_email' => 'team@example.com', 'test_mode' => 0 ] );
$KEYS = [ 'elementor-pro' => 'EL-KEY-1111', 'gravityforms' => 'GF-KEY-2222', 'learndash' => 'LD-KEY-3333', 'wp-fusion' => 'WPF-KEY-4444', 'uncanny-automator-pro' => 'UA-KEY-5555', 'automatorwp-pro' => 'AWP-KEY-6666' ];
foreach ( $KEYS as $s => $k ) DFY_LH_Store::save_license( $s, $k, $s === 'learndash' ? 'dfy@example.com' : '' );
ok( strpos( (string) get_option( 'dfy_lh_licenses' )['elementor-pro']['key'], 'EL-KEY' ) === false, 'license keys stored encrypted' );
ok( DFY_LH_Store::licenses()['learndash']['key'] === 'LD-KEY-3333', 'license keys decrypt' );

echo "\n1. Add site: no clock until it connects\n";
$id = DFY_LH_Store::create( [ 'label' => 'Client A', 'site_url' => 'https://client.example.com', 'asana_project' => '' ] );
DFY_LH_Rest::new_token( $id );
ok( ! site( $id )['start_at'] && ! site( $id )['expires_at'], 'not started before connecting' );

echo "\n2. Client connects with the code\n";
$code = DFY_LH_Rest::connection_code( site( $id ) );
ok( strpos( $code, 'DFY1.' ) === 0, 'connection code created' );
el_connect();
$r = DFY_LM_Client::connect( $code );
ok( $r['ok'], 'connect: ' . $r['msg'] );
ok( get_option( 'elementor_pro_license_key' ) === 'EL-KEY-1111', 'Elementor Pro (Connect & Activate) taken over' );
ok( ! calls_to( 'my.elementor.com/api/v2/license/activate' ), 'hub never tries to activate Elementor by key' );
ok( get_option( 'rg_gforms_key' ) === md5( 'GF-KEY-2222' ), 'Gravity Forms key activated' );
ok( get_site_option( 'nss_plugin_license_sfwd_lms' ) === 'LD-KEY-3333', 'LearnDash key activated' );
ok( wpf_get_option( 'license_status' ) === 'valid', 'WP Fusion activated' );
ok( get_option( 'uap_automator_pro_license_status' ) === 'valid', 'Automator Pro activated' );
ok( ( get_option( 'automatorwp_settings' )['automatorwp_pro_license'] ?? '' ) === 'AWP-KEY-6666', 'AutomatorWP Pro key saved in its own license field' );
$awp = calls_to( 'automatorwp.com/edd-sl-api' );
ok( $awp && $awp[0]['body']['edd_action'] === 'activate_license' && $awp[0]['body']['item_id'] === '1234', 'AutomatorWP activated with the item id the add-on registered' );
$ua = calls_to( 'licensing.uncannyowl.com/activate-license' );
ok( $ua && json_decode( base64_decode( $ua[0]['headers']['X-UO-Licensing'] ), true )['item_id'] === 506, 'Automator call uses item 506 + header' );
ok( strpos( json_encode( get_option( 'dfy_lm' ) ), 'KEY-' ) === false, 'client plugin does not keep any license key' );
$s = site( $id );
ok( $s['status'] === 'active' && $s['token_enc'] === '', 'hub: site active, code used up' );
ok( ( $s['plugins']['elementor-pro']['state'] ?? '' ) === 'active', 'hub sees licenses active after first check-in' );
ok( DFY_LH_Store::meta( $s )['gf_site_key'] === 'SK123', 'hub stored Gravity Forms site key (encrypted)' );
ok( is_wp_error( json_decode( '1' ) ) === false && DFY_LM_Client::connect( $code )['ok'] === false, 'code cannot be used twice' );
ok( $s['start_source'] === 'connected' && abs( strtotime( $s['start_at'] . ' UTC' ) - time() ) < 60, '1-year period started at connection' );
ok( substr( $s['expires_at'], 0, 10 ) === gmdate( 'Y-m-d', time() + 365 * 86400 ), "expires {$s['expires_at']} = connection + 365 days" );
ok( DFY_LM_Client::expires_ts() && abs( DFY_LM_Client::expires_ts() - ( time() + 365 * 86400 ) ) < 120, 'client site knows the end date' );
$was = $s['start_at']; DFY_LH_Rest::new_token( $id ); DFY_LM_Client::connect( DFY_LH_Rest::connection_code( site( $id ) ) );
ok( site( $id )['start_at'] === $was, 'reconnecting with a new code does not restart the year' );

echo "\n3. Start date corrected to 340 days ago -> 30-day warning\n";
DFY_LH_Engine::set_start( $id, gmdate( 'Y-m-d H:i:s', time() - 340 * 86400 ), 'manual' );
DFY_LH_Engine::daily();
$s = site( $id );
ok( substr( $s['expires_at'], 0, 10 ) === gmdate( 'Y-m-d', strtotime( $s['start_at'] . ' UTC' ) + 365 * 86400 ), "expires {$s['expires_at']} = start + 365 days" );
ok( in_array( '[DFY Licenses] DFY licenses for Client A expire in 25 days', $GLOBALS['mails'], true ), '30-day warning email sent' );
DFY_LH_Engine::daily();
ok( count( array_filter( $GLOBALS['mails'], function ( $m ) { return strpos( $m, 'expire in' ) !== false; } ) ) === 1, 'warning not repeated' );
DFY_LM_Client::heartbeat();
ok( DFY_LM_Client::expires_ts() === strtotime( $s['expires_at'] . ' UTC' ), 'client learned the expiry date' );

echo "\n4. Expiry: client removes everything at its next check-in; DFY License Manager stays installed\n";
DFY_LH_Store::update( $id, [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
$GLOBALS['calls'] = [];
DFY_LM_Client::heartbeat();
ok( get_option( 'elementor_pro_license_key', 'gone' ) === 'gone', 'Elementor key removed' );
ok( calls_to( 'my.elementor.com/api/v2/license/deactivate' ) !== [], 'Elementor deactivate called' );
ok( calls_to( 'gravityapi.com/wp-json/gravityapi/v1/sites/SK123' ) !== [], 'Gravity Forms site unlinked' );
ok( calls_to( 'checkout.learndash.com' ) && calls_to( 'checkout.learndash.com' )[0]['m'] === 'DELETE', 'LearnDash domain removed' );
$wpf = calls_to( 'wpfusion.com' );
ok( $wpf && strpos( $wpf[0]['url'], 'edd_action=deactivate_license' ) !== false && strpos( $wpf[0]['url'], 'WPF-KEY-4444' ) !== false, 'WP Fusion deactivate with its key' );
ok( calls_to( 'licensing.uncannyowl.com/deactivate-license' ) !== [], 'Automator deactivate called' );
$awp = calls_to( 'automatorwp.com/edd-sl-api' );
ok( $awp && $awp[0]['body']['edd_action'] === 'deactivate_license' && $awp[0]['body']['item_id'] === '1234' && ! isset( get_option( 'automatorwp_settings' )['automatorwp_pro_license'] ), 'AutomatorWP Pro deactivated and field cleared' );
ok( DFY_LM_Client::connected() && DFY_LM_Client::ended() && ( DFY_LM_Client::get()['plugins']['wp-fusion']['state'] ?? '' ) === 'removed', 'client stays connected and shows the licenses as deactivated' );
ok( $GLOBALS['plugin_ops'] === [], 'DFY License Manager not deactivated or deleted' );
$n = count( $GLOBALS['calls'] ); DFY_LM_Client::heartbeat();
ok( count( $GLOBALS['calls'] ) === $n, 'later check-ins do not repeat the removal' );
$s = site( $id );
ok( $s['status'] === 'removed', 'hub: site closed' );
ok( in_array( '[DFY Licenses] DFY licenses removed: Client A', $GLOBALS['mails'], true ), 'removal email sent to the DFY team' );

echo "\n5. Customer deactivates DFY License Manager early\n";
reset_client( 'https://b.example.com' );
$id2 = DFY_LH_Store::create( [ 'label' => 'Client B', 'site_url' => 'https://b.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id2 );
el_connect(); DFY_LM_Client::connect( DFY_LH_Rest::connection_code( site( $id2 ) ) );
$GLOBALS['calls'] = [];
( $GLOBALS['hooks']['deact']['dfy-license-manager.php'] )();
ok( count( $GLOBALS['calls'] ) >= 6, 'all six vendors called on deactivation (' . count( $GLOBALS['calls'] ) . ' calls)' );
ok( site( $id2 )['status'] === 'removed', 'hub: site B closed' );
ok( $GLOBALS['plugin_ops'] === [], 'no self-delete during a normal deactivation' );

echo "\n6. Customer switched Elementor to their own key, then deactivated\n";
reset_client( 'https://c.example.com' );
$id3 = DFY_LH_Store::create( [ 'label' => 'Client C', 'site_url' => 'https://c.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id3 );
el_connect(); DFY_LM_Client::connect( DFY_LH_Rest::connection_code( site( $id3 ) ) );
update_option( 'elementor_pro_license_key', 'CUSTOMER-OWN-KEY' );
$GLOBALS['calls'] = [];
( $GLOBALS['hooks']['deact']['dfy-license-manager.php'] )();
$el = calls_to( 'my.elementor.com' );
ok( count( $el ) === 1 && $el[0]['body']['license'] === 'EL-KEY-1111', 'hub released OUR Elementor key only' );
ok( get_option( 'elementor_pro_license_key' ) === 'CUSTOMER-OWN-KEY', "customer's own key left alone" );
ok( site( $id3 )['status'] === 'removed', 'hub: site C closed' );

echo "\n7. Site goes silent (plugin deleted by FTP) -> hub removes from vendors directly\n";
reset_client( 'https://d.example.com' );
$id4 = DFY_LH_Store::create( [ 'label' => 'Client D', 'site_url' => 'https://d.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id4 );
el_connect(); DFY_LM_Client::connect( DFY_LH_Rest::connection_code( site( $id4 ) ) );
reset_client( 'https://elsewhere.example.com' ); // the site is gone
DFY_LH_Store::update( $id4, [ 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - 4 * 86400 ) ] );
$GLOBALS['mails'] = [];
DFY_LH_Engine::daily();
ok( site( $id4 )['status'] === 'active' && in_array( '[DFY Licenses] DFY site Client D stopped checking in', $GLOBALS['mails'], true ), 'day 4: alert only' );
DFY_LH_Store::update( $id4, [ 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - 8 * 86400 ) ] );
DFY_LH_Engine::daily();
ok( site( $id4 )['status'] === 'removed', 'day 8: removed by hub' );
$gf = calls_to( 'gravityapi.com' );
ok( $gf && $gf[0]['m'] === 'PUT' && $gf[0]['headers']['Authorization'] === 'GravityAPI ' . base64_encode( 'SK123:SS456' ), 'Gravity Forms removed with the stored site key' );
$ld = calls_to( 'learndash.com' );
ok( $ld && $ld[0]['headers']['Learndash-Site-Url'] === 'https://d.example.com/' && $ld[0]['headers']['Learndash-Hub-Email'] === 'dfy@example.com', 'LearnDash removed with the site URL + email' );
$el = calls_to( 'my.elementor.com' );
ok( $el && $el[0]['body']['url'] === 'https://d.example.com', 'Elementor removed for the exact home URL' );
$awp = calls_to( 'automatorwp.com' );
ok( $awp && $awp[0]['body']['item_id'] === '1234' && $awp[0]['body']['license'] === 'AWP-KEY-6666', 'AutomatorWP removed by the hub with the reported item id' );

echo "\n8. Safety checks\n";
reset_client( 'https://e.example.com' );
$id5 = DFY_LH_Store::create( [ 'label' => 'Client E', 'site_url' => 'https://e.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id5 );
el_connect(); DFY_LM_Client::connect( DFY_LH_Rest::connection_code( site( $id5 ) ) );
$c = DFY_LM_Client::get();
$res = DFY_LH_Rest::heartbeat( new WP_REST_Request( '{"urls":{}}', [ 'X-DFY-Site' => $id5, 'X-DFY-Time' => time(), 'X-DFY-Signature' => 'forged' ] ) );
ok( is_wp_error( $res ), 'forged signature rejected' );
// A staging copy of site E checks in.
$GLOBALS['home'] = 'https://staging.e.example.com';
DFY_LM_Client::heartbeat();
ok( site( $id5 )['urls']['home_url'] === 'https://e.example.com', 'copy cannot overwrite the live URL' );
( $GLOBALS['hooks']['deact']['dfy-license-manager.php'] )();
ok( site( $id5 )['status'] === 'active' && get_option( 'elementor_pro_license_key' ) === 'EL-KEY-1111', 'deactivating on a copy does not remove the live licenses' );
$GLOBALS['home'] = 'https://e.example.com';
// Test mode: expired but nothing removed.
DFY_LH_Store::save_settings( [ 'test_mode' => 1 ] );
DFY_LH_Store::update( $id5, [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 5 * 86400 ) ] );
DFY_LM_Client::heartbeat();
DFY_LH_Engine::daily();
ok( site( $id5 )['status'] === 'active' && get_option( 'elementor_pro_license_key' ) === 'EL-KEY-1111', 'test mode: nothing removed' );
ok( strpos( json_encode( site( $id5 )['log'] ), 'Test mode: would now remove' ) !== false, 'test mode logs what it would do' );
// Hub unreachable after expiry: client still honours the known date.
DFY_LH_Store::save_settings( [ 'test_mode' => 0 ] );
DFY_LM_Client::heartbeat(); // learns test_mode off -> removes
ok( get_option( 'elementor_pro_license_key', 'gone' ) === 'gone', 'test mode off: removed on next check-in' );

echo "\n9. Plugin installed after connecting gets its key at the next check-in\n";
reset_client( 'https://f.example.com' );
$id6 = DFY_LH_Store::create( [ 'label' => 'Client F', 'site_url' => 'https://f.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id6 );
$all = DFY_LH_Store::licenses(); DFY_LH_Store::save_license( 'wp-fusion', '', '' );   // pretend WP Fusion key not configured yet
DFY_LM_Client::connect( DFY_LH_Rest::connection_code( site( $id6 ) ) );
ok( ( site( $id6 )['plugins']['wp-fusion']['state'] ?? '' ) === 'not_installed' || ! isset( DFY_LM_Client::get()['ours']['wp-fusion'] ), 'WP Fusion skipped while no key' );
DFY_LH_Store::save_license( 'wp-fusion', 'WPF-KEY-4444', '' );
DFY_LH_Store::update( $id6, [ 'plugins' => array_merge( site( $id6 )['plugins'], [ 'wp-fusion' => [ 'state' => 'not_installed' ] ] ) ] );
DFY_LM_Client::heartbeat();
ok( isset( DFY_LM_Client::get()['ours']['wp-fusion'] ) && ( site( $id6 )['plugins']['wp-fusion']['state'] ?? '' ) === 'active', 'WP Fusion activated later' );

echo "\n10. Demo case: licenses activated by hand, hub has no keys -> taken over, removed after the year, vendor plugins stay active\n";
reset_client( 'https://g.example.com' );
foreach ( array_keys( DFY_License_Remote::VENDORS ) as $sl ) DFY_LH_Store::save_license( $sl, '', '' );
DFY_LH_Store::save_settings( [ 'test_mode' => 0 ] );
// The DFY team activated these by hand during the build:
update_option( 'elementor_pro_license_key', 'EL-HAND-1' ); update_option( '_elementor_pro_license_v2_data', [ 'license' => 'valid' ] );
update_option( 'rg_gforms_key', md5( 'GF-HAND-2' ) ); update_option( 'gf_site_key', 'SKG' ); update_option( 'gf_site_secret', 'SSG' );
update_option( 'wpf_options', [ 'license_key' => 'WPF-HAND-3', 'license_status' => 'valid' ] );
update_option( 'automatorwp_settings', [ 'automatorwp_pro_license' => 'AWP-HAND-4' ] );
set_transient( 'cmb2_edd_license_data_' . substr( md5( 'AWP-HAND-4' ), 0, 10 ), (object) [ 'license' => 'valid' ] );
update_option( 'PMXI_Plugin_Options', [ 'licenses' => [ 'PMXI_Plugin' => PMXI_Plugin::encode( 'WPAI-HAND-5' ) ], 'statuses' => [ 'PMXI_Plugin' => 'valid' ], 'other' => 1 ] );
$id7 = DFY_LH_Store::create( [ 'label' => 'Demo', 'site_url' => 'https://g.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id7 );
$GLOBALS['calls'] = [];
$r = DFY_LM_Client::connect( DFY_LH_Rest::connection_code( site( $id7 ) ) );
ok( $r['ok'] && ! calls_to( 'activate' ), 'connected without re-activating anything' );
$ours = DFY_LM_Client::get()['ours'];
ok( isset( $ours['elementor-pro'], $ours['gravityforms'], $ours['wp-fusion'], $ours['automatorwp-pro'], $ours['wp-all-import-pro'] ) && ! isset( $ours['learndash'] ), 'all five hand-activated licenses taken over (unlicensed LearnDash ignored): ' . implode( ',', array_keys( $ours ) ) );
$s7 = site( $id7 ); $m7 = DFY_LH_Store::meta( $s7 );
ok( ( $s7['plugins']['wp-fusion']['state'] ?? '' ) === 'active' && ( $s7['plugins']['automatorwp-pro']['state'] ?? '' ) === 'active', 'hub shows them active' );
ok( ( $m7['keys']['elementor-pro'] ?? '' ) === 'EL-HAND-1' && ( $m7['keys']['automatorwp-pro'] ?? '' ) === 'AWP-HAND-4' && ( $m7['keys']['wp-all-import-pro'] ?? '' ) === 'WPAI-HAND-5' && ! isset( $m7['keys']['gravityforms'] ), 'hub got the keys for backup removal' );
ok( strpos( (string) $s7['meta_enc'], 'EL-HAND-1' ) === false, 'reported keys stored encrypted' );
ok( empty( DFY_LM_Client::get()['send_keys'] ), 'keys sent only once' );
// The year passes.
DFY_LH_Engine::set_start( $id7, gmdate( 'Y-m-d H:i:s', time() - 366 * 86400 ), 'manual' );
ok( DFY_LH_Engine::is_expired( site( $id7 ) ), 'corrected start date counts' );
ok( DFY_LH_Rest::adopt_open( site( $id7 ) ) === false, 'no more take-overs after the period' );
$GLOBALS['calls'] = []; $GLOBALS['plugin_ops'] = [];
DFY_LM_Client::heartbeat();
ok( get_option( 'elementor_pro_license_key', 'gone' ) === 'gone' && get_option( 'rg_gforms_key' ) === '' && ( get_option( 'wpf_options' )['license_key'] ?? 'x' ) === false && ! isset( get_option( 'automatorwp_settings' )['automatorwp_pro_license'] ) && PMXI_Plugin::decode( get_option( 'PMXI_Plugin_Options' )['licenses']['PMXI_Plugin'] ) === '' && get_option( 'PMXI_Plugin_Options' )['other'] === 1, 'all five licenses disconnected from the site' );
ok( count( calls_to( 'elementor.com/api/v2/license/deactivate' ) ) === 1 && count( calls_to( 'gravityapi.com' ) ) === 1 && count( calls_to( 'wpfusion.com/?edd_action=deactivate_license' ) ) === 1 && count( array_filter( calls_to( 'automatorwp.com' ), function ( $c ) { return ( $c['body']['edd_action'] ?? '' ) === 'deactivate_license'; } ) ) === 1 && count( calls_to( 'update.wpallimport.com/check_license?edd_action=deactivate_license' ) ) === 1, 'each of the five vendors told to release the site' );
$vendor_ops = array_filter( $GLOBALS['plugin_ops'], function ( $o ) { return strpos( $o, 'dfy-license-manager' ) === false; } );
ok( ! $vendor_ops, 'vendor plugins were NOT deactivated (only DFY License Manager removed itself)' );
ok( site( $id7 )['status'] === 'removed', 'hub closed the site' );

echo "\n11. Upgrade: a site connected before 1.1.0 starts from its connection time\n";
$id8 = DFY_LH_Store::create( [ 'label' => 'Old', 'site_url' => 'https://h.example.com', 'asana_project' => '' ] );
DFY_LH_Store::update( $id8, [ 'status' => 'active', 'log' => [ [ 't' => '2026-11-17 06:40:00', 'm' => 'Site added.' ], [ 't' => '2026-11-17 06:43:00', 'm' => 'Connected from https://h.example.com. Keys sent for: none.' ] ] ] );
DFY_LH_Store::upgrade_110();
ok( site( $id8 )['start_at'] === '2026-11-17 06:43:00' && substr( site( $id8 )['expires_at'], 0, 10 ) === '2027-11-17', 'Demo-style site: start Nov 17 2026, ends Nov 17 2027' );

echo "\n12. Add site -> licenses activated on the client automatically (no code)\n";
foreach ( $KEYS as $sl => $k ) DFY_LH_Store::save_license( $sl, $k, $sl === 'learndash' ? 'dfy@example.com' : '' );
reset_client( 'https://k.example.com' );
$GLOBALS['client_plugin_missing'] = true;
$id9 = DFY_LH_Store::create( [ 'label' => 'Client K', 'site_url' => 'https://k.example.com/', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id9 );
DFY_LH_Rest::ping( $id9 );
ok( site( $id9 )['status'] === 'pending' && strpos( json_encode( site( $id9 )['log'] ), 'not active on the site yet' ) !== false, 'plugin not installed yet: hub waits and says so' );
$GLOBALS['client_plugin_missing'] = false;
DFY_LH_Rest::ping( $id9 );   // = "Add site" / "Connect now" once the plugin is there
$s9 = site( $id9 );
ok( $s9['status'] === 'active' && DFY_LM_Client::connected(), 'site connected by itself' );
ok( wpf_get_option( 'license_status' ) === 'valid' && get_option( 'rg_gforms_key' ) === md5( 'GF-KEY-2222' ) && ( get_option( 'automatorwp_settings' )['automatorwp_pro_license'] ?? '' ) === 'AWP-KEY-6666', 'licenses activated on the client' );
ok( $s9['start_source'] === 'connected' && $s9['expires_at'], '1-year period started' );
// Someone else claims a site that is not in the hub, or fakes the challenge.
reset_client( 'https://evil.example.com' );
ok( DFY_LM_Client::auto_connect( true )['ok'] === false && ! DFY_LM_Client::connected(), 'site not added in the hub cannot connect' );
$id10 = DFY_LH_Store::create( [ 'label' => 'Victim', 'site_url' => 'https://victim.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id10 );
$fake = DFY_LH_Rest::claim( new WP_REST_Request( json_encode( [ 'challenge' => str_repeat( 'a', 48 ), 'urls' => [ 'home_url' => 'https://victim.example.com' ] ] ), [] ) );
ok( is_wp_error( $fake ) && site( $id10 )['status'] === 'pending', 'faked claim for another site refused (hub reads the challenge from the real URL)' );
// Staging copy of a connected site, added in the hub under its own URL.
reset_client( 'https://k.example.com' ); DFY_LM_Client::auto_connect( true ) /* k is active already, so no pending match */;
update_option( 'dfy_lm', [ 'hub' => HUB, 'site_id' => $id9, 'secret' => DFY_Crypto::encrypt( 'x' ), 'home' => 'https://k.example.com', 'ours' => [ 'wp-fusion' => 'x' ] ] );
$GLOBALS['home'] = 'https://staging-k.example.com';
$id11 = DFY_LH_Store::create( [ 'label' => 'K staging', 'site_url' => 'https://staging-k.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id11 );
DFY_LH_Rest::ping( $id11 );
ok( site( $id11 )['status'] === 'active' && DFY_LM_Client::get()['site_id'] === $id11 && ! DFY_LM_Client::is_copy(), 'staging copy gets its own connection' );
ok( site( $id9 )['status'] === 'active', 'the live site is untouched' );

echo "\n13. Elementor on dfy-demo -> shown as not licensed until Connect & Activate, then taken over\n";
reset_client( 'https://m.example.com' );
DFY_LH_Store::save_license( 'elementor-pro', '', '' );
$id12 = DFY_LH_Store::create( [ 'label' => 'Client M', 'site_url' => 'https://m.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id12 );
DFY_LH_Rest::ping( $id12 );
ok( ( site( $id12 )['plugins']['elementor-pro']['state'] ?? '' ) === 'unlicensed', 'hub lists Elementor Pro as installed but not licensed' );
DFY_LH_Store::save_license( 'elementor-pro', 'EL-KEY-1111', '' ); DFY_LM_Client::heartbeat();
ok( ( site( $id12 )['plugins']['elementor-pro']['state'] ?? '' ) === 'unlicensed' && ! calls_to( 'license/activate' ), 'a saved Elementor key is never sent' );
el_connect(); DFY_LM_Client::heartbeat();
ok( ( site( $id12 )['plugins']['elementor-pro']['state'] ?? '' ) === 'active', 'Connect & Activate -> taken over at the next check-in' );

echo "\n14. Elementor Pro via Connect & Activate (agency account) -> taken over; after the year our account is disconnected, the customer's is kept\n";
reset_client( 'https://n.example.com' ); $GLOBALS['umeta'] = [];
DFY_LH_Store::save_license( 'elementor-pro', '', '' );
$id13 = DFY_LH_Store::create( [ 'label' => 'Client N', 'site_url' => 'https://n.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id13 );
DFY_LH_Rest::ping( $id13 );
ok( ( site( $id13 )['plugins']['elementor-pro']['state'] ?? '' ) === 'unlicensed' && strpos( site( $id13 )['plugins']['elementor-pro']['msg'], 'Connect & Activate' ) !== false, 'hub says: use Connect & Activate' );
// DFY team clicks Connect & Activate as WP user 1 with the agency account:
$GLOBALS['umeta'][1]['wp_elementor_connect_common_data'] = [ 'access_token' => jwt( 'dfy-agency' ), 'client_id' => 'x' ];
update_option( 'elementor_pro_license_key', 'EL-CONNECT-KEY' ); update_option( '_elementor_pro_license_v2_data', [ 'license' => 'valid' ] );
DFY_LM_Client::heartbeat();
ok( isset( DFY_LM_Client::get()['ours']['elementor-pro'] ) && DFY_LM_Client::get()['el_accounts'] === [ 'dfy-agency' ], 'Elementor taken over; DFY account remembered' );
ok( ( site( $id13 )['plugins']['elementor-pro']['state'] ?? '' ) === 'active' && ( DFY_LH_Store::meta( site( $id13 ) )['keys']['elementor-pro'] ?? '' ) === 'EL-CONNECT-KEY', 'hub shows it active and holds the key for backup removal' );
// Later the customer connects their own Elementor account (user 2) for the template library.
$GLOBALS['umeta'][2]['wp_elementor_connect_common_data'] = [ 'access_token' => jwt( 'customer-own' ) ];
DFY_LH_Store::update( $id13, [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
DFY_LM_Client::heartbeat();
ok( get_option( 'elementor_pro_license_key', 'gone' ) === 'gone' && count( calls_to( 'my.elementor.com/api/v2/license/deactivate' ) ) >= 1, 'Elementor license deactivated after the year' );
ok( ! isset( $GLOBALS['umeta'][1]['wp_elementor_connect_common_data'] ), 'DFY Elementor account disconnected (customer cannot re-activate with it)' );
ok( isset( $GLOBALS['umeta'][2]['wp_elementor_connect_common_data'] ), "customer's own Elementor connection kept" );

echo "\n15. dfy-demo case: an EXPIRED Gravity Forms key on the site is not taken over; the agency key is activated\n";
reset_client( 'https://p.example.com' );
DFY_LH_Store::save_license( 'gravityforms', 'GF-KEY-2222', '' );
update_option( 'rg_gforms_key', md5( 'GF-EXPIRED' ) );
$id14 = DFY_LH_Store::create( [ 'label' => 'Client P', 'site_url' => 'https://p.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id14 );
DFY_LH_Rest::ping( $id14 );
ok( get_option( 'rg_gforms_key' ) === md5( 'GF-KEY-2222' ) && ( site( $id14 )['plugins']['gravityforms']['state'] ?? '' ) === 'active', 'expired key replaced by the agency key' );
// Site connected with the old (1.2) logic, which took over the expired key: the hub re-sends the agency key once.
$c = DFY_LM_Client::get(); update_option( 'rg_gforms_key', md5( 'GF-EXPIRED' ) ); $c['ours']['gravityforms'] = md5( 'GF-EXPIRED' ); update_option( 'dfy_lm', $c );
DFY_LM_Client::heartbeat();
ok( get_option( 'rg_gforms_key' ) === md5( 'GF-KEY-2222' ) && DFY_LM_Client::get()['ours']['gravityforms'] === md5( 'GF-KEY-2222' ), 'taken-over expired license fixed at the next check-in' );

echo "\n16. Start date: healed for old connected sites, set by hand from the list, and the site learns it now\n";
$id15 = DFY_LH_Store::create( [ 'label' => 'Old 2', 'site_url' => 'https://q.example.com', 'asana_project' => '' ] );
DFY_LH_Store::update( $id15, [ 'status' => 'active', 'log' => [ [ 't' => '2026-03-02 10:00:00', 'm' => 'Connected from https://q.example.com. Keys sent for: none.' ] ] ] );
DFY_LH_Engine::daily();
ok( site( $id15 )['start_at'] === '2026-03-02 10:00:00' && substr( site( $id15 )['expires_at'], 0, 10 ) === '2027-03-02', 'connected site without a start date gets its connection date' );
DFY_LH_Engine::set_start( $id14, '2026-01-15 00:00:00', 'manual' ); DFY_LH_Rest::ping( $id14, 'checkin' );
ok( abs( DFY_LM_Client::expires_ts() - strtotime( '2027-01-15 00:00:00 UTC' ) ) < 5, 'manual start date reaches the client right away' );

echo "\n17. Manual deactivation: licenses removed at once, premium plugins stay active\n";
$GLOBALS['plugin_ops'] = [];
DFY_LH_Store::update( $id14, [ 'command' => 'remove' ] ); DFY_LH_Rest::ping( $id14, 'checkin' ); DFY_LH_Engine::remote_remove( site( $id14 ), 'manual', true );
ok( get_option( 'rg_gforms_key' ) === '' && site( $id14 )['status'] === 'removed', 'licenses deactivated and the site closed' );
ok( ! array_filter( $GLOBALS['plugin_ops'], function ( $o ) { return strpos( $o, 'dfy-license-manager' ) === false; } ), 'no premium plugin deactivated' );

echo "\n18. Deactivate -> Activate licenses again (plugin never deleted)\n";
reset_client( 'https://r.example.com' );
DFY_LH_Store::save_settings( [ 'contact_url' => 'https://dfy.example.com/contact' ] );
$id16 = DFY_LH_Store::create( [ 'label' => 'Client R', 'site_url' => 'https://r.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id16 );
DFY_LH_Rest::ping( $id16 );
DFY_LH_Store::update( $id16, [ 'command' => 'remove' ] ); DFY_LH_Rest::ping( $id16, 'checkin' );
ok( site( $id16 )['status'] === 'removed' && DFY_LM_Client::ended() && wpf_get_option( 'license_status' ) !== 'valid', 'deactivated; client shows ended' );
ok( DFY_LM_Client::get()['contact'] === 'https://dfy.example.com/contact', 'contact link reached the client' );
$msg = DFY_LH_Engine::reactivate( $id16, 'tester' ); DFY_LH_Rest::ping( $id16, 'checkin' );
ok( site( $id16 )['status'] === 'active' && wpf_get_option( 'license_status' ) === 'valid' && get_option( 'rg_gforms_key' ) === md5( 'GF-KEY-2222' ) && ! DFY_LM_Client::ended(), 'activated again: ' . $msg );
ok( ( site( $id16 )['plugins']['wp-fusion']['state'] ?? '' ) === 'active', 'hub shows them active again' );
// After the year: reactivation starts a new period.
DFY_LH_Store::update( $id16, [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 86400 ) ] ); DFY_LH_Rest::ping( $id16, 'checkin' );
ok( site( $id16 )['status'] === 'removed' && DFY_LM_Client::ended(), 'expired -> deactivated' );
$msg = DFY_LH_Engine::reactivate( $id16, 'tester' ); DFY_LH_Rest::ping( $id16, 'checkin' );
ok( strpos( $msg, 'new 1-year period' ) !== false && ! DFY_LH_Engine::is_expired( site( $id16 ) ) && wpf_get_option( 'license_status' ) === 'valid', 'reactivated with a new 1-year period' );

echo "\n19. Site deleted in the hub: the client stops using old credentials and can be added again\n";
DFY_LH_Store::update( $id16, [ 'command' => 'remove' ] ); DFY_LH_Rest::ping( $id16, 'checkin' );
DFY_LH_Store::delete( $id16 );
DFY_LM_Client::heartbeat();
ok( ! DFY_LM_Client::connected() && DFY_LM_Client::ended(), 'client disconnected, still shows ended licenses' );
$id17 = DFY_LH_Store::create( [ 'label' => 'Client R again', 'site_url' => 'https://r.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id17 );
DFY_LH_Rest::ping( $id17 );
ok( site( $id17 )['status'] === 'active' && wpf_get_option( 'license_status' ) === 'valid', 'added again -> connects and activates' );

echo "\n20. Reference sites (completed, no plugin)\n";
$r = DFY_LH_Admin::import_lines( "Alpha Club | 2025-07-03 | https://app.pipefy.com/open-cards/1 | |\nBeta Network | 2026-03-06 | https://app.asana.com/1/1/project/2 | Jane Doe |\nAlpha Club | 2025-07-03 | https://app.pipefy.com/open-cards/1 | |\nNo date project | | https://app.asana.com/1/1/project/3 | |\nbad | 2026-13-45 | |" );
ok( $r['added'] === 3 && $r['skipped'] === 1 && count( $r['bad'] ) === 1, "import: {$r['added']} added, {$r['skipped']} duplicate, " . count( $r['bad'] ) . ' bad' );
$mv = array_values( array_filter( DFY_LH_Store::all(), function ( $x ) { return $x['label'] === 'Alpha Club'; } ) )[0];
ok( $mv['status'] === 'reference' && substr( $mv['expires_at'], 0, 10 ) === '2026-07-03' && $mv['start_source'] === 'completed', 'Alpha Club: 2025-07-03 -> expires 2026-07-03' );
$GLOBALS['mails'] = [];
DFY_LH_Engine::daily(); DFY_LH_Engine::daily();
ok( count( array_filter( $GLOBALS['mails'], function ( $m ) { return strpos( $m, 'reference site(s)' ) !== false; } ) ) === 1, 'expired reference sites: one summary email' );
ok( strpos( DFY_LH_Admin::urgency( $mv ), 'remove the licenses by hand' ) !== false, 'ended reference site flagged red' );
$f = $mv['flags']; $f['manual_removed'] = time(); DFY_LH_Store::update( (int) $mv['id'], [ 'flags' => $f ] );
ok( DFY_LH_Admin::urgency( DFY_LH_Store::get( (int) $mv['id'] ) ) === '', 'after "Mark removed" it is no longer red' );
ok( ! DFY_LH_Rest::claim( new WP_REST_Request( json_encode( [ 'challenge' => str_repeat( 'a', 48 ), 'urls' => [ 'home_url' => 'https://nothing.example.com' ] ] ), [] ) ) instanceof stdClass, 'reference sites are never matched by automatic connection' );

echo "\n21. Older DFY project: start date found on the site\n";
DFY_LH_Store::save_settings( [ 'test_mode' => 0 ] );
reset_client( 'https://old.example.com' );
update_option( 'elementor_install_history', [ '3.20.0' => strtotime( '2026-02-03 10:00:00 UTC' ), '3.25.0' => strtotime( '2026-06-01 UTC' ) ] );
$GLOBALS['site_dates'] = [ 'tables' => [ 'wp_gf_form', 'wp_automatorwp_automations' ], 'gf' => '2026-02-05 09:00:00', 'awp' => '2026-02-10 00:00:00', 'ld' => null, 'users' => '2019-05-01 00:00:00' ];
update_option( 'wpf_options', [ 'license_key' => 'WPF-OLD', 'license_status' => 'valid' ] ); // licensed by hand back then
$id18 = DFY_LH_Store::create( [ 'label' => 'Old project', 'site_url' => 'https://old.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id18 );
$f = site( $id18 )['flags']; $f['detect_start'] = 1; DFY_LH_Store::update( $id18, [ 'flags' => $f ] );
DFY_LH_Rest::ping( $id18 );
$s18 = site( $id18 );
ok( $s18['start_source'] === 'detected' && substr( $s18['start_at'], 0, 10 ) === '2026-02-03' && substr( $s18['expires_at'], 0, 10 ) === '2027-02-03', 'start = Elementor first install (2026-02-03), not the site creation date; ends 2027-02-03' );
ok( count( $s18['flags']['evidence'] ) >= 4 && $s18['flags']['evidence'][0]['slug'] === 'site', 'all dates kept for the site page (oldest first)' );
ok( isset( DFY_LM_Client::get()['ours']['wp-fusion'] ) && get_option( 'rg_gforms_key' ) === md5( 'GF-KEY-2222' ), 'hand-activated WP Fusion taken over; unlicensed Gravity Forms activated with the agency key' );

echo "\n22. Older project whose year is already over: licenses kept\n";
reset_client( 'https://older.example.com' );
update_option( 'elementor_install_history', [ '3.10.0' => strtotime( '2025-03-01 UTC' ) ] );
$GLOBALS['site_dates'] = [ 'tables' => [], 'users' => '2025-02-01 00:00:00' ];
update_option( 'wpf_options', [ 'license_key' => 'WPF-OLD2', 'license_status' => 'valid' ] );
$GLOBALS['calls'] = [];
$id19 = DFY_LH_Store::create( [ 'label' => 'Older project', 'site_url' => 'https://older.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id19 );
$f = site( $id19 )['flags']; $f['detect_start'] = 1; DFY_LH_Store::update( $id19, [ 'flags' => $f ] );
DFY_LH_Rest::ping( $id19 );
ok( substr( site( $id19 )['expires_at'], 0, 10 ) === '2026-03-01' && get_option( 'rg_gforms_key', '' ) !== md5( 'GF-KEY-2222' ) && ! array_filter( $GLOBALS['calls'], function ( $c ) { return ( is_array( $c['body'] ) ? ( $c['body']['edd_action'] ?? '' ) : '' ) === 'activate_license' || strpos( $c['url'], 'activate_license' ) !== false && strpos( $c['url'], 'deactivate' ) === false; } ), 'started 2025-03-01, already over: no keys sent' );
DFY_LM_Client::heartbeat(); DFY_LH_Engine::daily(); DFY_LM_Client::heartbeat();
ok( wpf_get_option( 'license_status' ) === 'valid' && site( $id19 )['status'] === 'active' && ! DFY_LM_Client::ended() && isset( DFY_LM_Client::get()['ours']['wp-fusion'] ), 'older project: licenses taken over and KEPT (never removed automatically, even with test mode off)' );
ok( DFY_LM_Client::expires_ts() === null, 'the site is not given an end date to act on by itself' );
DFY_LH_Store::update( $id19, [ 'command' => 'remove' ] ); DFY_LH_Rest::ping( $id19, 'checkin' );
ok( wpf_get_option( 'license_status' ) !== 'valid', '"Deactivate licenses" by hand still works' );

echo "\n23. Normal new site: start is today even though dates exist\n";
reset_client( 'https://fresh.example.com' );
update_option( 'elementor_install_history', [ '3.10.0' => strtotime( '2025-03-01 UTC' ) ] );
$id20 = DFY_LH_Store::create( [ 'label' => 'Fresh', 'site_url' => 'https://fresh.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id20 );
DFY_LH_Rest::ping( $id20 );
ok( site( $id20 )['start_source'] === 'connected' && ! DFY_LH_Engine::is_expired( site( $id20 ) ), 'start = connection time (dates only shown)' );
$GLOBALS['site_dates'] = [];

echo "\n24. Customer name and email from the Asana link; export\n";
DFY_LH_Store::save_settings( [ 'asana_token' => DFY_Crypto::encrypt( 'pat-test' ) ] );
$GLOBALS['asana_projects'] = [
  '555' => [ 'name' => 'Demo.Com - Charlie Example', 'tasks' => [
      [ 'gid' => '1', 'name' => 'PROJECT DISCUSSIONS', 'custom_fields' => [] ],
      [ 'gid' => '2', 'name' => 'WEB PROJECT SCOPE', 'custom_fields' => [
          [ 'name' => 'Customer First Name', 'display_value' => 'Charlie' ], [ 'name' => 'Customer Last Name', 'display_value' => 'Example' ],
          [ 'name' => 'CONTACT EMAIL', 'display_value' => 'charlie@example.com' ], [ 'name' => 'DEV SITE PASSWORD', 'display_value' => 'secret-pass' ] ] ] ] ],
  '666' => [ 'name' => 'Other Club - Dana Sample', 'tasks' => [] ],
];
$c = DFY_LH_Asana::customer( 'https://app.asana.com/1/1/project/555/board/9' );
ok( $c['name'] === 'Charlie Example' && $c['email'] === 'charlie@example.com' && $c['project'] === 'Demo.Com - Charlie Example', 'name + email read from the project' );
ok( strpos( json_encode( $c ), 'secret-pass' ) === false, 'other Asana fields (passwords) are not read' );
$c2 = DFY_LH_Asana::customer( 'https://app.asana.com/0/666/1' );
ok( $c2['name'] === 'Dana Sample' && $c2['email'] === '', 'no fields -> name taken from the project name' );
ok( is_wp_error( DFY_LH_Asana::customer( 'https://app.pipefy.com/open-cards/1' ) ), 'Pipefy links are skipped' );
$id21 = DFY_LH_Store::create( [ 'label' => 'asana-site.example.com', 'site_url' => 'https://asana-site.example.com', 'asana_project' => '' ] );
$f = site( $id21 )['flags']; $f['asana_url'] = 'https://app.asana.com/1/1/project/555/list/1'; DFY_LH_Store::update( $id21, [ 'flags' => $f ] );
DFY_LH_Admin::fill_from_asana( $id21 );
$s21 = site( $id21 );
ok( $s21['flags']['client'] === 'Charlie Example' && $s21['flags']['email'] === 'charlie@example.com' && $s21['label'] === 'Demo.Com', 'site filled: customer, email, project name' );
[ $sites ] = DFY_LH_Admin::query( [ 'status' => 'ended' ] );
ok( $sites && ! array_filter( $sites, function ( $x ) { return ! ( $x['status'] === 'removed' || DFY_LH_Engine::is_expired( $x ) ); } ), 'filter "Ended / expired": ' . count( $sites ) . ' sites, all ended' );
[ $act ] = DFY_LH_Admin::query( [ 's' => 'charlie@example.com' ] );
ok( count( $act ) === 1, 'search finds the site by customer email' );
$csv = DFY_LH_Admin::csv( [ [ 'Name', 'Email' ], [ 'Charlie "C" Example', 'charlie@example.com' ], [ '=HYPERLINK("x")', '-5' ] ] );
ok( substr( $csv, 0, 3 ) === "\xEF\xBB\xBF" && strpos( $csv, '"Charlie ""C"" Example","charlie@example.com"' ) !== false && strpos( $csv, "\"'=HYPERLINK" ) !== false && strpos( $csv, '"-5"' ) !== false, 'CSV: Excel BOM, quoting, formulas neutralised, numbers kept' );
$GLOBALS['asana_projects'] = [];

echo "\n25. Dates copied from the DFY template are ignored\n";
$tplEvidence = function () { update_option( 'elementor_install_history', [ '3.7.0' => strtotime( '2022-08-09 UTC' ) ] ); $GLOBALS['site_dates'] = [ 'tables' => [ 'wp_gf_form' ], 'gf' => '2022-08-16 00:00:00', 'users' => '2022-09-07 00:00:00' ]; };
reset_client( 'https://tpl-a.example.com' ); $tplEvidence();
$a = DFY_LH_Store::create( [ 'label' => 'Tpl A', 'site_url' => 'https://tpl-a.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $a );
$f = site( $a )['flags']; $f['detect_start'] = 1; DFY_LH_Store::update( $a, [ 'flags' => $f ] ); DFY_LH_Rest::ping( $a );
reset_client( 'https://tpl-b.example.com' ); $tplEvidence(); $GLOBALS['site_dates']['tables'][] = 'wp_automatorwp_automations'; $GLOBALS['site_dates']['awp'] = '2026-03-04 00:00:00';
$b = DFY_LH_Store::create( [ 'label' => 'Tpl B', 'site_url' => 'https://tpl-b.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $b );
$f = site( $b )['flags']; $f['detect_start'] = 1; DFY_LH_Store::update( $b, [ 'flags' => $f ] ); DFY_LH_Rest::ping( $b );
ok( substr( site( $b )['start_at'], 0, 10 ) === '2026-03-04', 'template dates (same as another site) skipped; first date of its own used: ' . substr( site( $b )['start_at'], 0, 10 ) );
reset_client( 'https://tpl-c.example.com' ); $tplEvidence();
$c3 = DFY_LH_Store::create( [ 'label' => 'Tpl C', 'site_url' => 'https://tpl-c.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $c3 );
$f = site( $c3 )['flags']; $f['detect_start'] = 1; DFY_LH_Store::update( $c3, [ 'flags' => $f ] ); DFY_LH_Rest::ping( $c3 );
ok( site( $c3 )['start_source'] === 'connected' && ! DFY_LH_Engine::is_expired( site( $c3 ) ) && DFY_LH_Engine::keeps( site( $c3 ) ), 'only template dates -> no guess, starts today, licenses kept' );
$GLOBALS['site_dates'] = [];

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
