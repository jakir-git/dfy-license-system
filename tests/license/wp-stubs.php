<?php
// Minimal WordPress stand-ins: options, wpdb, HTTP router (hub REST, Asana, vendors).
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'MINUTE_IN_SECONDS', 60 );
define( 'AUTH_KEY', 'test-auth' ); define( 'SECURE_AUTH_SALT', 'test-salt' );
define( 'HUB', 'https://hub.example.com/wp-json/dfy-lh/v1/' ); define( 'DFY_LM_HUB', HUB );

$GLOPTS = []; $GLOBALS['home'] = 'https://client.example.com'; $GLOBALS['calls'] = []; $GLOBALS['mails'] = [];
$GLOBALS['asana_tasks'] = []; $GLOBALS['asana_subtasks'] = []; $GLOBALS['asana_comments'] = []; $GLOBALS['hooks'] = []; $GLOBALS['plugin_ops'] = [];
$GLOBALS['transients'] = []; $GLOBALS['umeta'] = [];
function maybe_unserialize( $v ) { return is_string( $v ) && ( $u = @unserialize( $v ) ) !== false ? $u : $v; }
function delete_user_meta( $u, $k ) { unset( $GLOBALS['umeta'][ $u ][ $k ] ); return true; }
function jwt( $sub ) { return 'h.' . rtrim( strtr( base64_encode( json_encode( [ 'sub' => $sub ] ) ), '+/', '-_' ), '=' ) . '.s'; }

class WP_Error { public $c, $m, $d; function __construct( $c = '', $m = '', $d = [] ) { $this->c = $c; $this->m = $m; $this->d = $d; } function get_error_message() { return $this->m; } function get_error_code() { return $this->c; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['GLOPTS'] ) ? $GLOBALS['GLOPTS'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['GLOPTS'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['GLOPTS'][ $k ] ); return true; }
function get_site_option( $k, $d = false ) { return get_option( "site:$k", $d ); }
function update_site_option( $k, $v ) { return update_option( "site:$k", $v ); }
function delete_site_option( $k ) { return delete_option( "site:$k" ); }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['transients'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); }
function wp_parse_args( $a, $d = [] ) { return array_merge( $d, (array) $a ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function current_time( $t, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_email( $s ) { return (string) $s; }
if ( ! function_exists( "wp_unslash" ) ) { function wp_unslash( $s ) { return $s; } }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
if ( ! function_exists( 'wp_nonce_url' ) ) { function wp_nonce_url( $u, $a = '' ) { return $u . ( strpos( $u, '?' ) === false ? '?' : '&' ) . '_wpnonce=n'; } }
function esc_url_raw( $s ) { return (string) $s; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function add_query_arg( $a, $u ) { $p = parse_url( $u ); parse_str( $p['query'] ?? '', $q ); $q = array_merge( $q, $a ); $o = []; foreach ( $q as $k => $v ) $o[] = $k . '=' . $v; return explode( '?', $u )[0] . '?' . implode( '&', $o ); } // like WP: values are not encoded
function rest_url( $p ) { return 'https://hub.example.com/wp-json/' . $p; }
function admin_url( $p = '' ) { return 'https://hub.example.com/wp-admin/' . $p; }
function home_url() { return $GLOBALS['home']; }
function site_url() { return $GLOBALS['home']; }
function network_site_url() { return $GLOBALS['home'] . '/'; }
function get_bloginfo( $k ) { return $k === 'language' ? 'en-US' : 'Client'; }
function wp_mail( $to, $s, $b ) { $GLOBALS['mails'][] = $s; return true; }
function wp_next_scheduled( $h ) { return false; } function wp_schedule_event() {} function wp_clear_scheduled_hook( $h ) {}
function register_activation_hook( $f, $cb ) {} function register_deactivation_hook( $f, $cb ) { $GLOBALS['hooks']['deact'][ basename( $f ) ] = $cb; }
function add_action( ...$a ) {} function add_filter( ...$a ) {} function is_admin() { return false; } function wp_doing_ajax() { return false; }
function register_rest_route( ...$a ) {}
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function deactivate_plugins( $p, $silent = false ) { $GLOBALS['plugin_ops'][] = "deactivate:$p:" . ( $silent ? 'silent' : 'loud' ); }
function delete_plugins( $p ) { $GLOBALS['plugin_ops'][] = 'delete:' . implode( ',', $p ); return true; }

class WP_REST_Request {
    private $b, $h;
    function __construct( $b, $h ) { $this->b = $b; $this->h = []; foreach ( $h as $k => $v ) $this->h[ str_replace( '-', '_', strtolower( $k ) ) ] = $v; }
    function get_body() { return $this->b; }
    function get_header( $k ) { return $this->h[ $k ] ?? null; }
}

class FakeWpdb {
    public $prefix = 'wp_', $insert_id = 0, $rows = [], $usermeta = 'wp_usermeta', $posts = 'wp_posts', $users = 'wp_users';
    function suppress_errors( $b = true ) { return false; }
    function esc_like( $s ) { return $s; }
    /** Evidence queries: answered from $GLOBALS['site_dates'] (keys: tables, el_lib, ld, gf, awp, wpai, users). */
    function get_var( $sql ) {
        $d = $GLOBALS['site_dates'] ?? [];
        if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $sql, $m ) ) return in_array( $m[1], $d['tables'] ?? [], true ) ? $m[1] : null;
        foreach ( [ 'elementor_library' => 'el_lib', 'sfwd-courses' => 'ld', 'gf_form' => 'gf', 'automatorwp_automations' => 'awp', 'pmxi_imports' => 'wpai', 'wp_users' => 'users' ] as $needle => $k ) {
            if ( strpos( $sql, $needle ) !== false ) return $d[ $k ] ?? null;
        }
        return null;
    }
    function get_charset_collate() { return ''; }
    function prepare( $sql, ...$a ) { foreach ( $a as $v ) $sql = preg_replace( '/%[ds]/', is_int( $v ) ? (string) $v : "'" . $v . "'", $sql, 1 ); return $sql; }
    function insert( $t, $d ) { $this->insert_id = count( $this->rows ) + 1; $d['id'] = $this->insert_id; $d += [ 'launch_task' => '', 'token_enc' => '', 'secret_enc' => '', 'meta_enc' => '', 'start_at' => null, 'start_source' => '', 'expires_at' => null, 'last_seen' => null, 'command' => '' ]; $this->rows[ $this->insert_id ] = $d; return 1; }
    function update( $t, $d, $w ) { $this->rows[ $w['id'] ] = array_merge( $this->rows[ $w['id'] ], $d ); return 1; }
    function delete( $t, $w ) { unset( $this->rows[ $w['id'] ] ); }
    function get_row( $sql, $o ) { preg_match( '/id = (\d+)/', $sql, $m ); return $this->rows[ (int) $m[1] ] ?? null; }
    function get_results( $sql, $o ) {
        if ( strpos( $sql, 'wp_usermeta' ) !== false ) {
            $out = [];
            foreach ( $GLOBALS['umeta'] as $uid => $m ) foreach ( $m as $k => $v ) if ( substr( $k, -strlen( 'elementor_connect_common_data' ) ) === 'elementor_connect_common_data' ) $out[] = [ 'user_id' => $uid, 'meta_key' => $k, 'meta_value' => serialize( $v ) ];
            return $out;
        }
        $r = array_values( $this->rows );
        if ( preg_match( "/status = '(\w+)'/", $sql, $m ) ) $r = array_values( array_filter( $r, function ( $x ) use ( $m ) { return $x['status'] === $m[1]; } ) );
        return array_reverse( $r );
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

/* ── HTTP router ─────────────────────────────────────────── */
function wp_remote_post( $url, $a = [] ) { return wp_remote_request( $url, [ 'method' => 'POST' ] + $a ); }
function wp_remote_get( $url, $a = [] ) {
    if ( ! empty( $a['body'] ) && is_array( $a['body'] ) ) { $url = add_query_arg( $a['body'], $url ); unset( $a['body'] ); }
    return wp_remote_request( $url, [ 'method' => 'GET' ] + $a );
}
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function resp( $code, $data ) { return [ 'response' => [ 'code' => $code ], 'body' => is_string( $data ) ? $data : json_encode( $data ) ]; }

function wp_remote_request( $url, $a = [] ) {
    $m = strtoupper( $a['method'] ?? 'GET' );
    if ( strpos( $url, HUB ) === 0 ) {
        if ( ! empty( $GLOBALS['hub_down'] ) ) return new WP_Error( 'http', 'Connection refused' );
        $route = substr( $url, strlen( HUB ) );
        $res   = DFY_LH_Rest::$route( new WP_REST_Request( is_string( $a['body'] ) ? $a['body'] : json_encode( $a['body'] ), $a['headers'] ?? [] ) );
        if ( is_wp_error( $res ) ) return resp( $res->d['status'] ?? 500, [ 'code' => $res->c, 'message' => $res->m ] );
        return resp( 200, $res );
    }
    // Client site endpoints (DFY License Manager), called by the hub.
    if ( strpos( $url, '?rest_route=/dfy-lm/v1/' ) !== false ) {
        $GLOBALS['client_calls'][] = $url;
        if ( parse_url( $url, PHP_URL_HOST ) !== parse_url( $GLOBALS['home'], PHP_URL_HOST ) || ! empty( $GLOBALS['client_plugin_missing'] ) ) return resp( 404, [ 'code' => 'rest_no_route' ] );
        if ( strpos( $url, '/challenge' ) !== false ) { $c = DFY_LM_Client::challenge(); return $c === '' ? resp( 404, [] ) : resp( 200, [ 'challenge' => $c ] ); }
        if ( strpos( $url, '/ping' ) !== false ) return resp( 200, DFY_LM_Client::handle_ping() );
    }
    if ( strpos( $url, 'https://app.asana.com/api/1.0/projects/' ) === 0 && ! empty( $GLOBALS['asana_projects'] ) ) {
        $GLOBALS['asana_calls'] = ( $GLOBALS['asana_calls'] ?? 0 ) + 1;
        if ( ! preg_match( '~/projects/(\d+)(/tasks)?~', $url, $mm ) || empty( $GLOBALS['asana_projects'][ $mm[1] ] ) ) return resp( 404, [ 'errors' => [ [ 'message' => 'Not found' ] ] ] );
        $p = $GLOBALS['asana_projects'][ $mm[1] ];
        if ( empty( $mm[2] ) ) return resp( 200, [ 'data' => [ 'gid' => $mm[1], 'name' => $p['name'] ] ] );
        return resp( 200, [ 'data' => $p['tasks'] ] );
    }
    if ( strpos( $url, 'https://app.asana.com/api/1.0' ) === 0 ) {
        $p = substr( $url, strlen( 'https://app.asana.com/api/1.0' ) );
        if ( $m === 'GET' && preg_match( '~^/projects/(\d+)/tasks~', $p, $mm ) ) return resp( 200, [ 'data' => $GLOBALS['asana_tasks'][ $mm[1] ] ?? [], 'next_page' => null ] );
        if ( $m === 'GET' && preg_match( '~^/tasks/(\d+)/subtasks~', $p, $mm ) ) { $GLOBALS['asana_sub_calls'] = ( $GLOBALS['asana_sub_calls'] ?? 0 ) + 1; return resp( 200, [ 'data' => $GLOBALS['asana_subtasks'][ $mm[1] ] ?? [], 'next_page' => null ] ); }
        if ( $m === 'GET' && preg_match( '~^/tasks/(\d+)\?~', $p, $mm ) ) {
            foreach ( array_merge( ...array_values( $GLOBALS['asana_tasks'] + $GLOBALS['asana_subtasks'] ) ) as $t ) if ( $t['gid'] === $mm[1] ) { $GLOBALS['asana_direct'] = ( $GLOBALS['asana_direct'] ?? 0 ) + 1; return resp( 200, [ 'data' => $t ] ); }
        }
        if ( $m === 'POST' && preg_match( '~^/tasks/(\d+)/stories~', $p, $mm ) ) { $GLOBALS['asana_comments'][] = [ $mm[1], json_decode( $a['body'], true )['data']['text'] ]; return resp( 201, [ 'data' => [] ] ); }
        return resp( 404, [ 'errors' => [ [ 'message' => 'not found' ] ] ] );
    }
    // Vendors: record and answer.
    $GLOBALS['calls'][] = [ 'm' => $m, 'url' => $url, 'headers' => $a['headers'] ?? [], 'body' => $a['body'] ?? null ];
    $is_deact = strpos( $url, 'deactivat' ) !== false || $m === 'DELETE' || $m === 'PUT';
    if ( strpos( $url, 'gravityapi.com' ) !== false || strpos( $url, 'learndash.com' ) !== false ) return resp( 200, [ 'ok' => true ] );
    return resp( 200, [ 'success' => true, 'license' => $is_deact ? 'deactivated' : 'valid', 'expires' => '2027-12-31 23:59:59' ] );
}

/** Reset only the client site's state (keep the hub's options and table). */
function reset_client( $home = 'https://client.example.com' ) {
    foreach ( array_keys( $GLOBALS['GLOPTS'] ) as $k ) if ( strpos( $k, 'dfy_lh' ) !== 0 ) unset( $GLOBALS['GLOPTS'][ $k ] );
    $GLOBALS['home'] = $home; $GLOBALS['calls'] = []; $GLOBALS['plugin_ops'] = []; $GLOBALS['transients'] = [];
}
function wp_remote_retrieve_header( $r, $h ) { return ''; }
