<?php
namespace ElementorPro\License {
    class API {
        static function activate_license( $k ) { return \dfy_vendor_post( 'https://my.elementor.com/api/v2/license/activate', [ 'license' => $k, 'url' => home_url() ] ); }
        static function deactivate_license() { return \dfy_vendor_post( 'https://my.elementor.com/api/v2/license/deactivate', [ 'license' => Admin::get_license_key(), 'url' => home_url() ] ); }
        static function set_license_data( $d ) { update_option( Admin::LICENSE_DATA_OPTION_NAME, $d ); }
        static function is_license_active() { return ( get_option( Admin::LICENSE_DATA_OPTION_NAME )['license'] ?? '' ) === 'valid'; }
    }
    class Admin {
        const LICENSE_KEY_OPTION_NAME = 'elementor_pro_license_key';
        const LICENSE_DATA_OPTION_NAME = '_elementor_pro_license_v2_data';
        const LICENSE_DATA_FALLBACK_OPTION_NAME = '_elementor_pro_license_v2_data_fallback';
        static function get_license_key() { return trim( (string) get_option( self::LICENSE_KEY_OPTION_NAME ) ); }
        static function set_license_key( $k ) { return update_option( self::LICENSE_KEY_OPTION_NAME, $k ); }
    }
}
namespace LearnDash\Hub\Component {
    class API {
        function verify_license( $e, $k, $f ) { \dfy_vendor_post( 'https://checkout.learndash.com/wp-json/learndash/v2/site/auth', [ 'site_url' => site_url(), 'license_key' => $k, 'email' => $e ] ); return true; }
        function update_license_status( $r, $e, $k ) { update_site_option( 'nss_plugin_license_sfwd_lms', $k ); update_site_option( 'nss_plugin_license_email_sfwd_lms', $e ); }
        function remove_domain() { wp_remote_request( 'https://checkout.learndash.com/wp-json/learndash/v2/site/auth', [ 'method' => 'DELETE', 'headers' => [ 'Learndash-Site-Url' => network_site_url(), 'Learndash-Hub-License-Key' => get_site_option( 'nss_plugin_license_sfwd_lms' ) ] ] ); return []; }
        function clear_auth() { delete_site_option( 'nss_plugin_license_sfwd_lms' ); delete_site_option( 'nss_plugin_license_email_sfwd_lms' ); }
    }
}
namespace {
    define( 'ELEMENTOR_PRO_VERSION', '3.30.0' );
    define( 'LEARNDASH_VERSION', '5.1.9' );
    define( 'AUTOMATOR_PRO_ITEM_ID', 506 );
    define( 'AUTOMATOR_PRO_LICENSING_URL', 'https://licensing.uncannyowl.com/' );
    class GFForms {}
    function learndash_is_learndash_license_valid() { return get_site_option( 'nss_plugin_license_sfwd_lms' ) !== 'LD-OLD-EXPIRED'; }
    class GFCommon { static function get_version_info( $c ) { return get_option( 'rg_gforms_key' ) === md5( 'GF-EXPIRED' ) ? [ 'is_valid_key' => true, 'status' => 'gravityapi_expired_license' ] : [ 'is_valid_key' => true, 'status' => 'valid_key' ]; } }
    class GFFormsModel {
        static function save_key( $k ) {
            if ( $k === '' ) {
                update_option( 'rg_gforms_key', '' );
                wp_remote_request( 'https://gravityapi.com/wp-json/gravityapi/v1/sites/' . get_option( 'gf_site_key' ), [ 'method' => 'PUT', 'body' => '{"license_key_md5":""}' ] );
            } else {
                update_option( 'rg_gforms_key', md5( $k ) ); update_option( 'gf_site_key', 'SK123' ); update_option( 'gf_site_secret', 'SS456' );
            }
        }
    }
    class WPF_Settings_Stub {
        function edd_activate( $k ) {
            $r = wp_remote_get( 'https://wpfusion.com', [ 'body' => [ 'edd_action' => 'activate_license', 'license' => $k, 'url' => home_url() ] ] );
            $d = json_decode( wp_remote_retrieve_body( $r ) ); $this->set( 'license_status', $d->license ); $this->set( 'license_key', $k ); return true;
        }
        function set( $k, $v ) { $o = (array) get_option( 'wpf_options', [] ); $o[ $k ] = $v; update_option( 'wpf_options', $o ); }
    }
    function wp_fusion() { return (object) [ 'settings' => new WPF_Settings_Stub() ]; }
    function wpf_get_option( $k ) { return ( (array) get_option( 'wpf_options', [] ) )[ $k ] ?? null; }
    // AutomatorWP core + Pro add-on (CMB2 EDD license field).
    function apply_filters( $tag, $v ) {
        if ( $tag !== 'automatorwp_licenses_meta_boxes' ) return $v;
        return [ 'automatorwp-pro-license' => [ 'fields' => [ 'automatorwp_pro_license' => [ 'type' => 'edd_license', 'file' => '/wp-content/plugins/automatorwp-pro/automatorwp-pro.php', 'item_id' => 1234, 'server' => 'https://automatorwp.com/edd-sl-api', 'version' => '4.0.0' ] ] ] ];
    }
    class RGC_CMB2_Field_EDD_License_Stub {
        function api_request( $server, $key, $args, $action = 'check_license' ) {
            $r = wp_remote_post( $server, [ 'body' => [ 'edd_action' => $action, 'license' => $key, 'url' => home_url(), 'item_id' => $args['item_id'] ] ] );
            $d = json_decode( wp_remote_retrieve_body( $r ) );
            if ( $action === 'activate_license' ) { set_transient( 'cmb2_edd_license_data_' . substr( md5( $key ), 0, 10 ), $d ); }
            return $d->license;
        }
    }
    $GLOBALS['cmb2_field_edd_license'] = new RGC_CMB2_Field_EDD_License_Stub();
    function rgc_cmb2_edd_license_status( $k ) { $d = get_transient( 'cmb2_edd_license_data_' . substr( md5( $k ), 0, 10 ) ); return $d ? $d->license : false; }
    function dfy_vendor_post( $url, $body ) { $r = wp_remote_post( $url, [ 'body' => $body ] ); return json_decode( wp_remote_retrieve_body( $r ), true ); }
}

namespace {
    // WP All Import Pro: key encoded in PMXI_Plugin_Options['licenses']['PMXI_Plugin'].
    class PMXI_Plugin {
        static $i; static function getInstance() { return self::$i ?: ( self::$i = new self() ); }
        static function encode( $v ) { return base64_encode( 'salt' . $v . md5( 'salt' ) ); }
        static function decode( $v ) { if ( preg_match( '/^[a-f0-9]{32}$/', $v ) ) return $v; $d = base64_decode( $v ); return substr( $d, 4, -32 ); }
        function updateOption( $opt ) {
            $o = (array) get_option( 'PMXI_Plugin_Options', [] );
            foreach ( (array) ( $opt['licenses'] ?? [] ) as $k => $v ) $opt['licenses'][ $k ] = self::encode( self::decode( $v ) );
            update_option( 'PMXI_Plugin_Options', $opt + $o );
        }
    }
}
