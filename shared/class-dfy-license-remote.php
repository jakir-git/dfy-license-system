<?php
/**
 * DFY_License_Remote — removes a site from a vendor license with a direct
 * HTTP call, without needing the vendor plugin to be running.
 *
 * Shared by DFY License Hub (server-side backup removal) and
 * DFY License Manager (fallback when the vendor plugin is already inactive).
 *
 * Each call mirrors the vendor plugin's own "deactivate license" request,
 * read from the vendor's source:
 *  - Elementor Pro:  license/api.php  API::deactivate_license()
 *  - Gravity Forms:  includes/class-gravity-api.php  update_current_site('')
 *  - LearnDash:      includes/licensing/src/component/class-api.php  remove_domain()
 *  - WP Fusion:      includes/admin/class-settings.php  edd_deactivate()
 *  - Automator Pro:  src/core/admin/licensing/licensing.php  licensing_call('deactivate-license')
 *  - AutomatorWP Pro: automatorwp/libraries/cmb2-field-edd-license  api_request(..., 'deactivate_license')
 *  - WP All Import Pro: controllers/admin/settings.php  EDD at update.wpallimport.com/check_license, item 'WP All Import'
 *  - Restrict Content Pro (3.5.x): SolidWP/iThemes updater lib/updater/server.php  package-deactivate at api.ithemes.com,
 *    signed with a site-bound auth token made from the SolidWP username + password (never a bare key).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'DFY_License_Remote' ) ) :

class DFY_License_Remote {

    const VENDORS = [
        'elementor-pro'         => 'Elementor Pro',
        'gravityforms'          => 'Gravity Forms',
        'learndash'             => 'LearnDash LMS',
        'wp-fusion'             => 'WP Fusion',
        'automatorwp-pro'       => 'AutomatorWP Pro',
        'uncanny-automator-pro' => 'Uncanny Automator Pro',
        'wp-all-import-pro'     => 'WP All Import Pro',
        'restrict-content-pro'  => 'Restrict Content Pro (SolidWP)',
    ];

    const SOLID_API = 'https://api.ithemes.com/updater/';

    const WPAI_API = 'https://update.wpallimport.com/check_license';

    /** Where customers buy their own license. */
    const VENDOR_SITES = [
        'elementor-pro' => 'https://elementor.com/pro/', 'gravityforms' => 'https://www.gravityforms.com/', 'learndash' => 'https://www.learndash.com/',
        'wp-fusion' => 'https://wpfusion.com/', 'automatorwp-pro' => 'https://automatorwp.com/', 'wp-all-import-pro' => 'https://www.wpallimport.com/',
        'uncanny-automator-pro' => 'https://automatorplugin.com/', 'restrict-content-pro' => 'https://solidwp.com/',
    ];

    public static function label( string $slug ): string {
        return self::VENDORS[ $slug ] ?? $slug;
    }

    /**
     * @param string $slug  Vendor slug.
     * @param array  $lic   ['key' => ..., 'email' => ...]
     * @param array  $site  ['home_url','site_url','network_url'] exactly as the client site reported them.
     * @param array  $meta  Vendor details reported by the site (GF site key/secret, Automator URL/item id, versions).
     * @return array{ok:bool, msg:string}
     */
    public static function remove( string $slug, array $lic, array $site, array $meta = [] ): array {
        $key  = trim( (string) ( $lic['key'] ?? '' ) );
        $home = (string) ( $site['home_url'] ?? '' );
        if ( ! in_array( $slug, [ 'gravityforms', 'restrict-content-pro' ], true ) && ( $key === '' || $home === '' ) ) {
            return self::r( false, 'Missing license key or site URL.' );
        }
        switch ( $slug ) {
            case 'elementor-pro':         return self::elementor( $key, $home, $meta );
            case 'gravityforms':          return self::gravityforms( $meta );
            case 'learndash':             return self::learndash( $key, (string) ( $lic['email'] ?? '' ), (string) ( $site['network_url'] ?? $site['site_url'] ?? $home ), $meta );
            case 'wp-fusion':             return self::edd( 'https://wpfusion.com/?edd_action=deactivate', 'WP Fusion', $key, $home, 'GET' );
            case 'uncanny-automator-pro': return self::automator( $key, $home, $meta );
            case 'automatorwp-pro':       return self::automatorwp( $key, $home, $meta );
            case 'wp-all-import-pro':     return self::edd( self::WPAI_API, 'WP All Import', $key, $home, 'GET' );
            case 'restrict-content-pro':  return self::solid( 'package-deactivate', (string) ( $lic['email'] ?? '' ), $key, (string) ( $lic['token'] ?? '' ), $meta );
        }
        return self::r( false, "Unknown vendor '{$slug}'." );
    }

    private static function elementor( string $key, string $home, array $meta ): array {
        $res = wp_remote_post( 'https://my.elementor.com/api/v2/license/deactivate', [
            'timeout' => 40,
            'body'    => [
                'license'     => $key,
                'url'         => $home,
                'item_name'   => 'Elementor Pro',
                'api_version' => (string) ( $meta['elementor_version'] ?? '3.25.0' ),
                'site_lang'   => (string) ( $meta['site_lang'] ?? 'en-US' ),
            ],
        ] );
        return self::license_json( $res );
    }

    private static function gravityforms( array $meta ): array {
        $sk = (string) ( $meta['gf_site_key'] ?? '' );
        $ss = (string) ( $meta['gf_site_secret'] ?? '' );
        if ( $sk === '' || $ss === '' ) {
            return self::r( false, 'The site never reported its Gravity Forms site key, so this one must be removed in your gravityforms.com account.' );
        }
        $res = wp_remote_request( 'https://gravityapi.com/wp-json/gravityapi/v1/sites/' . rawurlencode( $sk ), [
            'method'  => 'PUT',
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'GravityAPI ' . base64_encode( "{$sk}:{$ss}" ),
                'Content-Type'  => 'application/json; charset=UTF-8',
            ],
            'body'    => wp_json_encode( [ 'license_key_md5' => '', 'timestamp' => time() ] ),
        ] );
        return self::http_ok( $res );
    }

    private static function learndash( string $key, string $email, string $url, array $meta ): array {
        if ( $email === '' ) return self::r( false, 'LearnDash needs the license account email (set it on the Licenses tab).' );
        $res = wp_remote_request( 'https://checkout.learndash.com/wp-json/learndash/v2/site/auth', [
            'method'  => 'DELETE',
            'timeout' => 20,
            'headers' => [
                'Learndash-Site-Url'        => $url,
                'Learndash-Hub-License-Key' => $key,
                'Learndash-Hub-Email'       => $email,
                'Learndash-Version'         => (string) ( $meta['learndash_version'] ?? '4.20.0' ),
            ],
        ] );
        return self::http_ok( $res );
    }

    private static function automator( string $key, string $home, array $meta ): array {
        $base = (string) ( $meta['automator_url'] ?? '' );
        $item = (int) ( $meta['automator_item_id'] ?? 0 );
        if ( $base !== '' && $item ) {
            $res = wp_remote_post( $base . 'deactivate-license', [
                'timeout' => 15,
                'body'    => '',
                'headers' => [
                    'X-UO-Licensing'   => base64_encode( wp_json_encode( [ 'license' => $key, 'item_id' => $item, 'url' => $home ] ) ),
                    'X-UO-Destination' => 'ap',
                ],
            ] );
            return self::license_json( $res );
        }
        // Older Automator Pro versions: plain EDD Software Licensing.
        return self::edd( 'https://automatorplugin.com/', 'Uncanny Automator Pro', $key, $home, 'POST' );
    }

    /**
     * SolidWP (iThemes) updater auth token for one site, exactly as Ithemes_Updater_Server::get_password_hash()
     * builds it: phpass( pbkdf2(password) . username . site URL . WordPress version ). It only works for that
     * site URL + WordPress version, so the SolidWP password itself never leaves the hub.
     */
    public static function solid_token( string $user, string $pass, string $site, string $wp ): string {
        if ( $user === '' || $pass === '' || $site === '' || $wp === '' ) return '';
        if ( ! class_exists( 'PasswordHash' ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) && file_exists( ABSPATH . WPINC . '/class-phpass.php' ) ) require_once ABSPATH . WPINC . '/class-phpass.php';
        if ( ! class_exists( 'PasswordHash' ) ) return '';
        $hash   = hash_pbkdf2( 'sha256', $pass, strtolower( trim( $user ) ) . 'wdHVwU&HcYcWnllo%kTUUnxpScy4%ICM29', 131072, 64 );
        $salted = substr( $hash . $user . $site . $wp, 0, max( strlen( $hash ), 512 ) );
        return ( new \PasswordHash( 8, true ) )->HashPassword( $salted );
    }

    /** The SolidWP updater's site URL: http, no trailing slash. */
    public static function solid_site( string $url ): string {
        return preg_replace( '|/$|', '', preg_replace( '/^https/', 'http', $url ) );
    }

    /**
     * Same request as Ithemes_Updater_Server::request() for package-activate / package-deactivate.
     * $pass is the SolidWP password (hub) — or pass a ready $token (made for this site by the hub).
     */
    public static function solid( string $action, string $user, string $pass, string $token, array $meta ): array {
        $site = (string) ( $meta['solid_site'] ?? '' );
        $wp   = (string) ( $meta['wp_version'] ?? '' );
        $pkg  = (string) ( $meta['solid_package'] ?? '' );
        if ( $user === '' || $site === '' || $wp === '' || $pkg === '' ) return self::r( false, 'The site never reported its SolidWP licensing details, so Restrict Content Pro must be removed in the SolidWP account (Licenses).' );
        if ( $token === '' ) $token = self::solid_token( $user, $pass, $site, $wp );
        if ( $token === '' ) return self::r( false, 'SolidWP username or password missing on the Licenses tab.' );
        $query = [ 'user' => $user, 'wp' => $wp, 'site' => $site, 'timestamp' => time(), 'auth_version' => '2' ];
        $pk    = [ $pkg => array_filter( [ 'ver' => (string) ( $meta['rcp_version'] ?? '' ), 'key' => (string) ( $meta['solid_key'] ?? '' ), 'active' => 1 ], function ( $v ) { return $v !== ''; } ) ];
        $res   = wp_remote_post( self::SOLID_API . $action . '/?' . http_build_query( $query, '', '&' ), [
            'timeout' => 20,
            'body'    => [ 'request' => wp_json_encode( [ 'auth_token' => $token, 'packages' => $pk, 'iterations' => 8 ] ) ],
        ] );
        return self::solid_result( $action, $pkg, $res );
    }

    /** Read a SolidWP updater answer for one package. @return array{ok:bool,msg:string,key?:string} */
    public static function solid_result( string $action, string $pkg, $res ): array {
        if ( is_wp_error( $res ) ) return self::r( false, 'SolidWP: ' . $res->get_error_message() );
        $body = is_array( $res ) && isset( $res['packages'] ) ? $res : json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $body ) ) return self::r( false, 'SolidWP did not answer.' );
        if ( ! empty( $body['error'] ) ) return self::r( false, 'SolidWP: ' . (string) ( $body['error']['message'] ?? 'error' ) );
        $p = (array) ( $body['packages'][ $pkg ] ?? [] );
        if ( ! empty( $p['error']['message'] ) ) return self::r( false, 'SolidWP: ' . (string) $p['error']['message'] );
        if ( $action === 'package-activate' ) {
            return ! empty( $p['key'] ) ? [ 'ok' => true, 'msg' => 'Activated.', 'key' => (string) $p['key'] ] : self::r( false, 'SolidWP did not license the site (' . ( $p['status'] ?? 'no key returned' ) . ').' );
        }
        return ( $p['status'] ?? '' ) === 'inactive' || ( $p && empty( $p['key'] ) ) ? self::r( true, 'Site removed from the SolidWP license.' ) : self::r( false, 'SolidWP answered: ' . substr( wp_json_encode( $p ), 0, 200 ) );
    }

    /** AutomatorWP add-ons: EDD Software Licensing at automatorwp.com, item id/name as registered by the add-on. */
    private static function automatorwp( string $key, string $home, array $meta ): array {
        $args = [ 'edd_action' => 'deactivate_license', 'license' => $key, 'url' => $home ];
        if ( ! empty( $meta['awp_item_id'] ) ) $args['item_id'] = (string) $meta['awp_item_id'];
        elseif ( ! empty( $meta['awp_item_name'] ) ) $args['item_name'] = (string) $meta['awp_item_name'];
        else return self::r( false, 'The site never reported the AutomatorWP Pro item, so this one must be removed in your automatorwp.com account.' );
        $res = wp_remote_post( (string) ( $meta['awp_server'] ?? '' ) ?: 'https://automatorwp.com/edd-sl-api', [ 'timeout' => 15, 'body' => $args ] );
        return self::license_json( $res );
    }

    private static function edd( string $url, string $item, string $key, string $home, string $method ): array {
        $args = [ 'edd_action' => 'deactivate_license', 'license' => $key, 'item_name' => $item, 'url' => $home ];
        $res  = $method === 'GET'
            ? wp_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), $url ), [ 'timeout' => 15 ] )
            : wp_remote_post( $url, [ 'timeout' => 15, 'body' => $args ] );
        return self::license_json( $res );
    }

    /** EDD-style JSON: license = deactivated | failed. */
    private static function license_json( $res ): array {
        if ( is_wp_error( $res ) ) return self::r( false, 'Request failed: ' . $res->get_error_message() );
        $code = (int) wp_remote_retrieve_response_code( $res );
        $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( $code >= 400 || ! is_array( $data ) ) {
            return self::r( false, "Vendor answered HTTP {$code}" . ( is_array( $data ) ? '' : ' with no JSON' ) . '.' );
        }
        return self::judge( $data );
    }

    /** Read an EDD-style answer: license = deactivated | failed | … */
    public static function judge( array $data ): array {
        $lic = (string) ( $data['license'] ?? '' );
        if ( $lic === 'deactivated' || ( ! empty( $data['success'] ) && $lic !== 'failed' ) ) return self::r( true, 'Site removed from the license.' );
        if ( in_array( $lic, [ 'failed', 'site_inactive', 'inactive' ], true ) ) return self::r( true, 'The site was already not active on this license.' );
        return self::r( false, 'Unexpected answer: ' . substr( wp_json_encode( $data ), 0, 200 ) );
    }

    private static function http_ok( $res ): array {
        if ( is_wp_error( $res ) ) return self::r( false, 'Request failed: ' . $res->get_error_message() );
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( $code >= 200 && $code < 300 ) return self::r( true, 'Site removed from the license.' );
        if ( $code === 404 ) return self::r( true, 'The vendor no longer has this site registered.' );
        return self::r( false, "Vendor answered HTTP {$code}: " . substr( (string) wp_remote_retrieve_body( $res ), 0, 200 ) );
    }

    private static function r( bool $ok, string $msg ): array { return [ 'ok' => $ok, 'msg' => $msg ]; }
}

endif;
