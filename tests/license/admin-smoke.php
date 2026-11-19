<?php
$SRC = $argv[1];
require __DIR__ . '/wp-stubs.php';
require __DIR__ . '/vendor-stubs.php';
foreach ( [ 'esc_html', 'esc_attr', 'esc_textarea', 'esc_url', 'esc_js' ] as $f ) if ( ! function_exists( $f ) ) eval( "function $f(\$s){ return htmlspecialchars((string)\$s, ENT_QUOTES); }" );
function current_user_can( $c ) { return true; } function wp_nonce_field( $a ) { echo "<nonce $a>"; } function submit_button( $t = '', $c = '' ) { echo "<button>$t</button>"; }
function checked( $a, $b, $e = true ) { return $a == $b ? 'checked' : ''; } function human_time_diff( $t ) { return '1 hour'; } function wp_unslash( $s ) { return $s; }
function wp_create_nonce( $a ) { return 'n'; } function nocache_headers() {} function wp_next_scheduled_x() {} function selected( $a, $b, $e = true ) { return $a == $b ? ' selected' : ''; } function get_current_user_id() { return 1; } function get_user_meta() { return ''; } function nl2br_( $s ) { return $s; }
require $SRC . '/dfy-license-hub/dfy-license-hub.php';
require $SRC . '/dfy-license-manager/dfy-license-manager.php';
require_once $SRC . '/dfy-license-hub/includes/class-lh-admin.php';
require_once $SRC . '/dfy-license-manager/includes/class-lm-admin.php';
DFY_LH_Store::save_license( 'learndash', 'LD-1', 'a@b.c' );
$id = DFY_LH_Store::create( [ 'label' => 'A', 'site_url' => 'https://client.example.com', 'asana_project' => '' ] ); DFY_LH_Rest::new_token( $id );
$len = [];
foreach ( [ [], [ 'tab' => 'add' ], [ 'tab' => 'licenses' ], [ 'tab' => 'settings' ], [ 'site' => $id ] ] as $g ) { $_GET = $g; ob_start(); DFY_LH_Admin::render(); $len[] = strlen( ob_get_clean() ); }
$_GET = []; ob_start(); DFY_LM_Admin::render(); $len[] = strlen( ob_get_clean() );
DFY_LM_Client::connect( DFY_LH_Rest::connection_code( DFY_LH_Store::get( $id ) ) );
DFY_LH_Store::update( $id, [ 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 10 * 86400 ) ] ); DFY_LM_Client::heartbeat();
ob_start(); DFY_LM_Admin::render(); DFY_LM_Admin::expiry_notice(); DFY_LM_Admin::deactivate_confirm(); $o = ob_get_clean(); $len[] = strlen( $o );
ob_start(); DFY_LM_Admin::expiry_popup(); $popup = ob_get_clean();
$_GET = [ 'site' => $id ]; ob_start(); DFY_LH_Admin::render(); $hub = ob_get_clean();
DFY_LH_Store::update( $id, [ 'flags' => [ 'asana_url' => 'https://app.asana.com/1/x/project/1' ] ] );
$id2 = DFY_LH_Store::create( [ 'label' => 'Zeta Club', 'site_url' => 'https://zeta.example.com', 'asana_project' => '' ] );
DFY_LH_Engine::set_start( $id2, '2025-03-05 00:00:00', 'manual' );
$list = function ( $g ) { $_GET = $g; ob_start(); DFY_LH_Admin::render(); return ob_get_clean(); };
$a = $list( [] ); $b = $list( [ 's' => 'zeta' ] ); $c2 = $list( [ 'by' => 'start', 'm' => 3, 'y' => 2025 ] ); $d = $list( [ 'by' => 'start', 'm' => 4 ] ); $e = $list( [ 'orderby' => 'label', 'order' => 'asc' ] );
$checks = [
  'no waiting-for-launch text' => strpos( $a, 'waiting for launch' ) === false,
  'asana button' => strpos( $a, 'Asana ↗' ) !== false,
  'deactivate button' => strpos( $a, 'Deactivate licenses' ) !== false,
  'start date button' => strpos( $a, 'Set start date' ) !== false,
  'month options numbered' => strpos( $a, '03 - Mar' ) !== false,
  'year option' => strpos( $a, '<option value="2025"' ) !== false,
  'search finds Zeta only' => strpos( $b, 'Zeta Club' ) !== false && strpos( $b, '>A</strong>' ) === false,
  'month+year filter' => strpos( $c2, 'Zeta Club' ) !== false && strpos( $c2, '>A</strong>' ) === false,
  'other month empty' => strpos( $d, 'No sites match' ) !== false,
  'sort by name asc' => strpos( $e, '>A</strong>' ) < strpos( $e, 'Zeta Club' ),
  'numeric dates' => strpos( $a, '2025-03-05 (Mar 5, 2025)' ) !== false,
];
// Default order: newest (not connected yet) first, then by completion/start date; red rows for ending soon.
$idNew = DFY_LH_Store::create( [ 'label' => 'Brand New Site', 'site_url' => 'https://brandnew.example.com', 'asana_project' => '' ] );
DFY_LH_Admin::import_lines( "Undated Ref | | https://app.asana.com/1/1/project/77 | |" );
$def = $list( [] );
preg_match_all( '~<tr( class="dfy-(?:urgent|soon)")?><td>(\d+)</td><td><a [^>]+><strong>([^<]+)</strong>~', $def, $mm, PREG_SET_ORDER );
$order = array_map( function ( $m ) { return $m[3]; }, $mm );
$checks += [
  'popup 30 days before' => strpos( $popup, 'dfy-lm-popup' ) !== false && strpos( $popup, 'end in 10 days' ) !== false && strpos( $popup, 'buy a license from the plugin author' ) !== false && strpos( $popup, 'I understand' ) !== false,
  'new site first' => ( $order[0] ?? '' ) === 'Brand New Site',
  'then newest start date' => array_search( 'A', $order ) < array_search( 'Zeta Club', $order ),
  'undated reference goes last' => end( $order ) === 'Undated Ref',
  'serial numbers' => ( $mm[0][2] ?? '' ) === '1' && ( $mm[1][2] ?? '' ) === '2',
  'ending in 10 days is yellow' => strpos( $def, 'class="dfy-soon"><td>' ) !== false && strpos( $def, 'Ends in 10 days' ) !== false,
  'not urgent is not red' => (bool) array_filter( $mm, function ( $m ) { return $m[3] === 'Brand New Site' && $m[1] === ''; } ),
];
// Client screen after the licenses ended + reference rows in the hub list.
DFY_LH_Store::save_settings( [ 'contact_url' => 'https://dfy.example.com/contact' ] );
DFY_LH_Store::update( $id, [ 'command' => 'remove' ] ); DFY_LM_Client::heartbeat();
$_GET = [ 'page' => 'x' ]; ob_start(); DFY_LM_Admin::expiry_notice(); $note = ob_get_clean();
$_GET = []; ob_start(); DFY_LM_Admin::render(); $page = ob_get_clean();
DFY_LH_Admin::import_lines( "Alpha Club | 2025-07-03 | https://app.pipefy.com/open-cards/1 | |" );
$ref = $list( [ 'status' => 'reference' ] ); $add = $list( [ 'tab' => 'add' ] ); $setp = $list( [ 'tab' => 'settings' ] ); $home = $list( [] );
$checks += [
  'client ended notice' => strpos( $note, 'have ended' ) !== false && strpos( $note, 'contact BuddyBoss DFY' ) !== false && strpos( $note, 'https://dfy.example.com/contact' ) !== false,
  'client page shows Deactivated' => strpos( $page, 'Deactivated' ) !== false && strpos( $page, 'stays installed' ) !== false,
  'vendor link in notice' => strpos( $note, 'learndash.com' ) !== false,
  'hub: Activate licenses button' => strpos( $list( [] ), 'Activate licenses' ) !== false,
  'reference row' => strpos( $ref, 'Reference only' ) === false && strpos( $ref, 'no DFY License Manager' ) !== false && strpos( $ref, '>Expired<' ) !== false && strpos( $ref, 'Pipefy ↗' ) !== false && strpos( $ref, 'Alpha Club' ) !== false && strpos( $ref, '>A</strong>' ) === false,
  'import form on Settings' => strpos( $setp, 'Add completed sites for reference' ) !== false && strpos( $add, 'Add completed sites for reference' ) === false,
  'add popup on every screen' => strpos( $home, '<dialog class="dfy-modal" id="dfy-add-site"' ) !== false && strpos( $home, 'data-dfy-open="add"' ) !== false,
  'add form: Asana right after URL' => strpos( $add, 'name="asana"' ) > strpos( $add, 'name="site_url"' ) && strpos( $add, 'name="asana"' ) < strpos( $add, 'name="label"' ),
  'no Add site tab' => strpos( $home, '>Add site</a>' ) === false,
  'expires soon pill' => strpos( $home, '>Expires soon<' ) !== false,
];
$f = DFY_LH_Store::get( $id )['flags']; $f['evidence'] = [ [ 'slug' => 'site', 'label' => 'Site', 'date' => '2019-05-01 00:00:00', 'source' => 'First user account on the site (site created)' ], [ 'slug' => 'elementor-pro', 'label' => 'Elementor Pro', 'date' => '2026-02-03 10:00:00', 'source' => 'Elementor first installed (Elementor install history)' ] ];
DFY_LH_Store::update( $id, [ 'flags' => $f ] );
$sp = $list( [ 'site' => $id ] );
$checks += [
  'evidence table' => strpos( $sp, 'Dates found on the site' ) !== false && strpos( $sp, 'suggested start' ) !== false,
  'suggestion skips site creation' => strpos( $sp, 'Elementor first installed (Elementor install history) <span class="dfy-tag dfy-tag--ok">← suggested start' ) !== false,
  'older project checkbox removed' => strpos( $add, 'name="older"' ) === false,
];
$f = DFY_LH_Store::get( $id )['flags']; $f['client'] = 'Charlie Example'; $f['email'] = 'charlie@example.com'; DFY_LH_Store::update( $id, [ 'flags' => $f ] );
$lst = $list( [] );
$checks += [
  'email shown in list' => strpos( $lst, 'mailto:charlie@example.com' ) !== false && strpos( $lst, 'Charlie Example' ) !== false,
  'export button' => strpos( $lst, 'dfy_lh_export' ) !== false && strpos( $lst, 'Export these' ) !== false,
  'ended filter option' => strpos( $lst, 'Ended / expired' ) !== false,
  'add form customer fields' => strpos( $add, 'name="client"' ) !== false && strpos( $add, 'name="email"' ) !== false,
];
$set = $list( [ 'tab' => 'settings' ] );
$checks += [
  'settings: encryption status' => strpos( $set, 'No permanent key' ) !== false,
  'settings: cron test button' => strpos( $set, 'Send test email through WP-Cron' ) !== false && strpos( $set, 'Daily license check' ) !== false,
  'licenses: no Uncanny field' => strpos( $list( [ 'tab' => 'licenses' ] ), 'key[uncanny-automator-pro]' ) === false,
  'settings: customer email mode' => strpos( $set, 'name="customer_mail"' ) !== false && strpos( $set, 'name="customer_test_to"' ) !== false,
];
foreach ( $checks as $k => $v ) echo ( $v ? 'ok   ' : 'FAIL ' ) . "list: $k\n";
echo implode( ',', $len ), "\n", ( strpos( $o, 'premium licenses end on' ) !== false ? 'notice ok' : 'notice MISSING' ), "\n", ( strpos( $hub, 'Licenses on this site' ) !== false && strpos( $hub, 'active' ) !== false ? 'hub site ok' : 'hub site MISSING' ), "\n";
