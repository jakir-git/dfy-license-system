<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * On-site license drivers. Each one uses the vendor plugin's own code where it
 * exists, so the vendor sees exactly the same request as when you click
 * Activate / Deactivate in its settings screen.
 */
class DFY_LM_Vendors {

    /** Vendor plugins currently loaded on this site. */
    public static function installed(): array {
        $out = [];
        foreach ( array_keys( DFY_License_Remote::VENDORS ) as $slug ) if ( self::loaded( $slug ) ) $out[] = $slug;
        return $out;
    }

    public static function loaded( string $slug ): bool {
        switch ( $slug ) {
            case 'elementor-pro':         return defined( 'ELEMENTOR_PRO_VERSION' ) && class_exists( 'ElementorPro\License\API' );
            case 'gravityforms':          return class_exists( 'GFForms' ) && class_exists( 'GFFormsModel' );
            case 'learndash':             return class_exists( 'LearnDash\Hub\Component\API' );
            case 'wp-fusion':             return function_exists( 'wp_fusion' );
            case 'uncanny-automator-pro': return defined( 'AUTOMATOR_PRO_ITEM_ID' ) && defined( 'AUTOMATOR_PRO_LICENSING_URL' );
            case 'automatorwp-pro':       return isset( $GLOBALS['cmb2_field_edd_license'] ) && self::awp_field( true ) !== null;
            case 'wp-all-import-pro':     return class_exists( 'PMXI_Plugin' ) && method_exists( 'PMXI_Plugin', 'getInstance' );
        }
        return false;
    }

    public static function urls(): array {
        return [ 'home_url' => home_url(), 'site_url' => site_url(), 'network_url' => network_site_url() ];
    }

    /** Details the hub needs to remove licenses even if this site disappears. */
    public static function meta(): array {
        return array_filter( [
            'gf_site_key'       => (string) get_option( 'gf_site_key', '' ),
            'gf_site_secret'    => (string) get_option( 'gf_site_secret', '' ),
            'automator_url'     => defined( 'AUTOMATOR_PRO_LICENSING_URL' ) ? (string) AUTOMATOR_PRO_LICENSING_URL : '',
            'automator_item_id' => defined( 'AUTOMATOR_PRO_ITEM_ID' ) ? (string) AUTOMATOR_PRO_ITEM_ID : '',
            'elementor_version' => defined( 'ELEMENTOR_PRO_VERSION' ) ? (string) ELEMENTOR_PRO_VERSION : '',
            'learndash_version' => defined( 'LEARNDASH_VERSION' ) ? (string) LEARNDASH_VERSION : '',
            'site_lang'         => (string) get_bloginfo( 'language' ),
            'awp_server'        => (string) ( self::awp_field()['server'] ?? '' ),
            'awp_item_id'       => (string) ( self::awp_field()['item_id'] ?? '' ),
            'awp_item_name'     => (string) ( self::awp_field()['item_name'] ?? '' ),
        ] );
    }

    /** Fingerprint we keep instead of the key, to know later whether the stored key is still ours. */
    public static function fingerprint( string $slug, string $key ): string {
        return $slug === 'gravityforms' ? md5( trim( $key ) ) : hash( 'sha256', trim( $key ) );
    }

    /** The key the vendor plugin currently has stored. */
    public static function stored_key( string $slug ): string {
        switch ( $slug ) {
            case 'elementor-pro':         return trim( (string) get_option( self::el_const( 'LICENSE_KEY_OPTION_NAME', 'elementor_pro_license_key' ), '' ) );
            case 'gravityforms':          return (string) get_option( 'rg_gforms_key', '' ); // md5 of the key
            case 'learndash':             return trim( (string) get_site_option( 'nss_plugin_license_sfwd_lms', '' ) );
            case 'wp-fusion':             $o = (array) get_option( 'wpf_options', [] ); return trim( (string) ( $o['license_key'] ?? '' ) );
            case 'uncanny-automator-pro': return trim( (string) get_option( 'uap_automator_pro_license_key', '' ) );
            case 'automatorwp-pro':       $f = self::awp_field(); $o = (array) get_option( 'automatorwp_settings', [] ); return $f ? trim( (string) ( $o[ $f['id'] ] ?? '' ) ) : '';
            case 'wp-all-import-pro':     return self::wpai_key();
        }
        return '';
    }

    /** Fingerprint of the key the vendor has stored now ('' when none). */
    public static function current_fingerprint( string $slug ): string {
        $k = self::stored_key( $slug );
        if ( $k === '' ) return '';
        return $slug === 'gravityforms' ? $k : hash( 'sha256', $k );
    }

    /** A license is already active on the site (e.g. activated by hand by the DFY team). */
    public static function has_license( string $slug ): bool {
        return self::loaded( $slug ) && self::stored_key( $slug ) !== '' && self::is_licensed( $slug ) !== false;
    }

    /** Is the key the vendor has stored the one DFY gave it? */
    public static function still_ours( string $slug, string $fp ): ?bool {
        $k = self::stored_key( $slug );
        if ( $k === '' ) return null;                       // nothing stored
        $cur = $slug === 'gravityforms' ? $k : hash( 'sha256', $k );
        return hash_equals( $fp, $cur );
    }

    /** @return bool|null  true = licensed, false = not, null = cannot tell */
    public static function is_licensed( string $slug ): ?bool {
        switch ( $slug ) {
            case 'elementor-pro':
                return method_exists( 'ElementorPro\License\API', 'is_license_active' ) ? (bool) \ElementorPro\License\API::is_license_active() : null;
            case 'gravityforms':          return self::gf_valid();
            case 'learndash':             return get_site_option( 'nss_plugin_license_sfwd_lms', '' ) !== '';
            case 'wp-fusion':             return function_exists( 'wpf_get_option' ) ? wpf_get_option( 'license_status' ) === 'valid' : null;
            case 'uncanny-automator-pro': return get_option( 'uap_automator_pro_license_status', '' ) === 'valid';
            case 'automatorwp-pro':       return function_exists( 'rgc_cmb2_edd_license_status' ) ? rgc_cmb2_edd_license_status( self::stored_key( 'automatorwp-pro' ) ) === 'valid' : null;
            case 'wp-all-import-pro':     $o = (array) get_option( 'PMXI_Plugin_Options', [] ); return ( $o['statuses']['PMXI_Plugin'] ?? '' ) === 'valid';
        }
        return null;
    }

    /**
     * Gravity Forms: a saved key is not enough (an expired key stays saved).
     * Valid = Gravity Forms' own license info says the key is valid and not expired/revoked.
     * @return bool|null
     */
    private static function gf_valid(): ?bool {
        if ( get_option( 'rg_gforms_key', '' ) === '' ) return false;
        if ( ! class_exists( 'GFCommon' ) || ! method_exists( 'GFCommon', 'get_version_info' ) ) return null;
        try {
            $info = \GFCommon::get_version_info( true ); // cached by Gravity Forms (refreshed by it periodically)
        } catch ( \Throwable $e ) { return null; }
        if ( ! is_array( $info ) || ! array_key_exists( 'is_valid_key', $info ) ) return null;
        if ( ! $info['is_valid_key'] ) return false;
        $status = strtolower( (string) ( $info['status'] ?? '' ) );
        return $status === '' || $status === 'valid_key' || ( strpos( $status, 'expired' ) === false && strpos( $status, 'revoked' ) === false && strpos( $status, 'invalid' ) === false );
    }

    /* ── activate ──────────────────────────────────────────── */

    /** @return array{ok:bool,msg:string} */
    public static function activate( string $slug, array $lic ): array {
        $key = trim( (string) ( $lic['key'] ?? '' ) );
        if ( $key === '' ) return self::r( false, 'No key received.' );
        if ( ! self::loaded( $slug ) ) return self::r( false, 'Plugin is not active on this site.' );
        try {
            switch ( $slug ) {
                case 'elementor-pro':         return self::el_activate( $key );
                case 'gravityforms':          return self::gf_activate( $key );
                case 'learndash':             return self::ld_activate( $key, (string) ( $lic['email'] ?? '' ) );
                case 'wp-fusion':             return self::wpf_activate( $key );
                case 'uncanny-automator-pro': return self::ua_call( 'activate-license', $key );
                case 'automatorwp-pro':       return self::awp_activate( $key );
                case 'wp-all-import-pro':     return self::wpai_activate( $key );
            }
        } catch ( \Throwable $e ) {
            return self::r( false, 'Error: ' . $e->getMessage() );
        }
        return self::r( false, 'Unknown plugin.' );
    }

    private static function el_const( string $name, string $fallback ): string {
        $c = 'ElementorPro\License\Admin::' . $name;
        return defined( $c ) ? (string) constant( $c ) : $fallback;
    }

    private static function el_activate( string $key ): array {
        $data = \ElementorPro\License\API::activate_license( $key );
        if ( is_wp_error( $data ) ) return self::r( false, 'Elementor: ' . $data->get_error_message() );
        if ( ( $data['license'] ?? '' ) !== 'valid' ) {
            $why = (string) ( $data['error'] ?? $data['license'] ?? $data['message'] ?? $data['code'] ?? '' );
            return self::r( false, 'Elementor did not accept activation by key' . ( $why !== '' ? " ({$why})" : '' ) . '. On this site go to Elementor → License → Connect & Activate with the DFY Elementor account; DFY License Manager takes it over within a minute.' );
        }
        if ( method_exists( 'ElementorPro\License\Admin', 'set_license_key' ) ) \ElementorPro\License\Admin::set_license_key( $key );
        else update_option( self::el_const( 'LICENSE_KEY_OPTION_NAME', 'elementor_pro_license_key' ), $key );
        if ( method_exists( 'ElementorPro\License\API', 'set_license_data' ) ) \ElementorPro\License\API::set_license_data( $data );
        return self::r( true, 'Activated.' );
    }

    private static function gf_activate( string $key ): array {
        \GFFormsModel::save_key( $key ); // stores md5(key) and registers the site with Gravity API
        if ( get_option( 'rg_gforms_key' ) !== md5( $key ) ) return self::r( false, 'Gravity Forms did not save the key.' );
        if ( method_exists( 'GFCommon', 'get_version_info' ) ) {
            $info = \GFCommon::get_version_info( false );
            if ( is_array( $info ) && array_key_exists( 'is_valid_key', $info ) && ! $info['is_valid_key'] ) {
                return self::r( false, 'Gravity Forms says the key is not valid.' );
            }
            if ( self::gf_valid() === false ) return self::r( false, 'Gravity Forms says the license is expired or revoked (status: ' . ( is_array( $info ) ? (string) ( $info['status'] ?? '' ) : '' ) . ').' );
        }
        return self::r( true, 'Activated.' );
    }

    private static function ld_activate( string $key, string $email ): array {
        if ( $email === '' ) return self::r( false, 'LearnDash needs the license email (set it in DFY License Hub).' );
        $api = new \LearnDash\Hub\Component\API();
        $res = $api->verify_license( $email, $key, true );
        if ( method_exists( $api, 'update_license_status' ) ) $api->update_license_status( $res, $email, $key );
        if ( is_wp_error( $res ) ) return self::r( false, 'LearnDash: ' . $res->get_error_message() );
        return self::r( true, 'Activated.' );
    }

    private static function wpf_activate( string $key ): array {
        wp_fusion()->settings->edd_activate( $key );
        $status = function_exists( 'wpf_get_option' ) ? wpf_get_option( 'license_status' ) : '';
        return $status === 'valid' ? self::r( true, 'Activated.' ) : self::r( false, 'WP Fusion answered: ' . ( $status ?: 'no answer' ) );
    }

    /** Automator Pro: same request as Licensing::licensing_call(). */
    private static function ua_call( string $endpoint, string $key ): array {
        if ( $endpoint === 'activate-license' ) update_option( 'uap_automator_pro_license_key', $key );
        if ( method_exists( 'Uncanny_Automator\Admin_Menu', 'licensing_call' ) ) {
            $data = \Uncanny_Automator\Admin_Menu::licensing_call( $endpoint, $key, AUTOMATOR_PRO_ITEM_ID, AUTOMATOR_PRO_LICENSING_URL );
        } else {
            $res  = wp_remote_post( AUTOMATOR_PRO_LICENSING_URL . $endpoint, [
                'timeout' => 15, 'body' => '',
                'headers' => [
                    'X-UO-Licensing'   => base64_encode( wp_json_encode( [ 'license' => $key, 'item_id' => AUTOMATOR_PRO_ITEM_ID, 'url' => home_url() ] ) ),
                    'X-UO-Destination' => 'ap',
                ],
            ] );
            $data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ) );
        }
        $d = json_decode( wp_json_encode( $data ), true );
        if ( ! is_array( $d ) ) return self::r( false, 'Automator licensing server did not answer.' );

        if ( $endpoint === 'activate-license' ) {
            if ( ( $d['license'] ?? '' ) !== 'valid' ) return self::r( false, 'Automator answered: ' . ( $d['error'] ?? $d['license'] ?? 'unknown' ) );
            update_option( 'uap_automator_pro_license_status', 'valid' );
            update_option( 'uap_automator_pro_license_expiry', (string) ( $d['expires'] ?? '' ) );
            return self::r( true, 'Activated.' );
        }
        $r = DFY_License_Remote::judge( $d );
        if ( $r['ok'] ) {
            update_option( 'uap_automator_pro_license_status', '' );
            update_option( 'uap_automator_pro_license_expiry', 'inactive' );
            update_option( 'uap_automator_pro_license_key', '' );
        }
        return $r;
    }

    /* ── deactivate ────────────────────────────────────────── */

    /**
     * Remove this site from the DFY license for one plugin.
     * Only touches the key DFY gave; if the customer switched to their own key,
     * reports 'replaced' and leaves their license alone.
     *
     * @return array{ok:bool,msg:string,replaced?:bool}
     */
    public static function deactivate( string $slug, string $fp ): array {
        $ours = self::still_ours( $slug, $fp );
        if ( $ours === false ) return [ 'ok' => false, 'replaced' => true, 'msg' => 'The customer now uses their own key here; it was left alone. Our license must be released by the hub.' ];
        if ( $ours === null ) return [ 'ok' => true, 'msg' => 'No license key stored any more.' ];

        try {
            if ( self::loaded( $slug ) ) {
                switch ( $slug ) {
                    case 'elementor-pro':
                        $r = \ElementorPro\License\API::deactivate_license();
                        $out = is_wp_error( $r ) ? self::r( false, 'Elementor: ' . $r->get_error_message() ) : DFY_License_Remote::judge( (array) $r );
                        if ( $out['ok'] ) self::el_forget();
                        return $out;
                    case 'gravityforms':
                        \GFFormsModel::save_key( '' ); // clears the key and unlinks the site at Gravity API
                        return get_option( 'rg_gforms_key', '' ) === '' ? self::r( true, 'Site removed from the license.' ) : self::r( false, 'Gravity Forms kept the key.' );
                    case 'learndash':
                        $api = new \LearnDash\Hub\Component\API();
                        $r   = $api->remove_domain();
                        if ( is_wp_error( $r ) ) return self::r( false, 'LearnDash: ' . $r->get_error_message() );
                        if ( method_exists( $api, 'clear_auth' ) ) $api->clear_auth();
                        return self::r( true, 'Site removed from the license.' );
                    case 'wp-fusion':
                        $out = DFY_License_Remote::remove( 'wp-fusion', [ 'key' => self::stored_key( 'wp-fusion' ) ], self::urls() );
                        if ( $out['ok'] ) {
                            wp_fusion()->settings->set( 'license_status', 'invalid' );
                            wp_fusion()->settings->set( 'license_key', false );
                            delete_option( 'wpf_license_check' );
                        }
                        return $out;
                    case 'uncanny-automator-pro':
                        return self::ua_call( 'deactivate-license', self::stored_key( 'uncanny-automator-pro' ) );
                    case 'automatorwp-pro':
                        return self::offline( 'automatorwp-pro' );
                    case 'wp-all-import-pro':
                        return self::offline( 'wp-all-import-pro' );
                }
            }
            // Vendor plugin is not running: call the vendor directly with what it stored.
            return self::offline( $slug );
        } catch ( \Throwable $e ) {
            return self::offline( $slug, 'Error: ' . $e->getMessage() . '. ' );
        }
    }

    private static function offline( string $slug, string $prefix = '' ): array {
        $lic = [ 'key' => self::stored_key( $slug ), 'email' => (string) get_site_option( 'nss_plugin_license_email_sfwd_lms', '' ) ];
        $r   = DFY_License_Remote::remove( $slug, $lic, self::urls(), self::meta() );
        if ( $r['ok'] ) {
            if ( $slug === 'elementor-pro' ) self::el_forget();
            if ( $slug === 'gravityforms' ) update_option( 'rg_gforms_key', '' );
            if ( $slug === 'learndash' ) { delete_site_option( 'nss_plugin_license_sfwd_lms' ); delete_site_option( 'nss_plugin_license_email_sfwd_lms' ); delete_option( 'learndash_hub_license_status' ); }
            if ( $slug === 'wp-fusion' ) { $o = (array) get_option( 'wpf_options', [] ); $o['license_key'] = false; $o['license_status'] = 'invalid'; update_option( 'wpf_options', $o ); }
            if ( $slug === 'uncanny-automator-pro' ) { update_option( 'uap_automator_pro_license_key', '' ); update_option( 'uap_automator_pro_license_status', '' ); }
            if ( $slug === 'automatorwp-pro' ) self::awp_forget( $lic['key'] );
            if ( $slug === 'wp-all-import-pro' ) self::wpai_save( '', 'deactivated' );
        }
        $r['msg'] = $prefix . $r['msg'];
        return $r;
    }

    /* ── AutomatorWP Pro ───────────────────────────────────── */

    /**
     * The add-on registers its license as a CMB2 "edd_license" field on the
     * AutomatorWP → Licenses page (option automatorwp_settings). Read it from
     * there so the field id and EDD item come from the add-on itself.
     * Remembered in an option so removal still works if the add-on is deactivated.
     */
    public static function awp_field( bool $live_only = false ): ?array {
        static $found = null;
        if ( $found === null && function_exists( 'apply_filters' ) ) {
            foreach ( (array) apply_filters( 'automatorwp_licenses_meta_boxes', [] ) as $box ) {
                foreach ( (array) ( $box['fields'] ?? [] ) as $fid => $f ) {
                    if ( ( $f['type'] ?? '' ) !== 'edd_license' ) continue;
                    if ( strpos( basename( (string) ( $f['file'] ?? '' ) ), 'automatorwp-pro' ) !== 0 ) continue;
                    $found = [
                        'id'        => (string) $fid,
                        'server'    => (string) ( $f['server'] ?? 'https://automatorwp.com/edd-sl-api' ),
                        'item_id'   => (string) ( $f['item_id'] ?? '' ),
                        'item_name' => (string) ( $f['item_name'] ?? '' ),
                        'file'      => (string) ( $f['file'] ?? '' ),
                        'version'   => (string) ( $f['version'] ?? '' ),
                    ];
                    if ( get_option( 'dfy_lm_awp' ) !== $found ) update_option( 'dfy_lm_awp', $found, false );
                    break 2;
                }
            }
            if ( $found === null ) $found = false;
        }
        if ( $found ) return $found;
        if ( $live_only ) return null;
        $saved = get_option( 'dfy_lm_awp' );
        return is_array( $saved ) ? $saved : null;
    }

    private static function awp_activate( string $key ): array {
        $f = self::awp_field();
        $o = (array) get_option( 'automatorwp_settings', [] );
        $o[ $f['id'] ] = $key;
        update_option( 'automatorwp_settings', $o );
        $args = [ 'license' => $key, 'item_id' => $f['item_id'], 'item_name' => $f['item_name'], 'file' => $f['file'], 'version' => $f['version'] ];
        $status = $GLOBALS['cmb2_field_edd_license']->api_request( $f['server'], $key, $args, 'activate_license' );
        if ( $status === 'valid' ) return self::r( true, 'Activated.' );
        unset( $o[ $f['id'] ] );
        update_option( 'automatorwp_settings', $o );
        return self::r( false, 'AutomatorWP answered: ' . ( is_string( $status ) ? $status : 'no answer' ) );
    }

    private static function awp_forget( string $key ): void {
        $f = self::awp_field();
        if ( ! $f ) return;
        $o = (array) get_option( 'automatorwp_settings', [] );
        unset( $o[ $f['id'] ] );
        update_option( 'automatorwp_settings', $o );
        $k = substr( md5( $key ), 0, 10 );
        delete_transient( "cmb2_edd_license_data_$k" );
        delete_transient( "cmb2_edd_license_try_$k" );
    }

    /* ── WP All Import Pro ──────────────────────────────────── */
    // Key in PMXI_Plugin_Options['licenses']['PMXI_Plugin'] (encoded by the plugin),
    // status in ['statuses']['PMXI_Plugin'] ('valid'). EDD server: update.wpallimport.com/check_license.

    private static function wpai_key(): string {
        $o   = (array) get_option( 'PMXI_Plugin_Options', [] );
        $raw = (string) ( $o['licenses']['PMXI_Plugin'] ?? '' );
        if ( $raw === '' ) return '';
        if ( class_exists( 'PMXI_Plugin' ) && method_exists( 'PMXI_Plugin', 'decode' ) ) return trim( (string) PMXI_Plugin::decode( $raw ) );
        return trim( $raw );
    }

    /** Save key + status the way the plugin does (its updateOption() encodes the key). */
    private static function wpai_save( string $key, string $status ): void {
        $o = (array) get_option( 'PMXI_Plugin_Options', [] );
        $lic = (array) ( $o['licenses'] ?? [] ); $st = (array) ( $o['statuses'] ?? [] );
        $lic['PMXI_Plugin'] = $key; $st['PMXI_Plugin'] = $status;
        try {
            if ( class_exists( 'PMXI_Plugin' ) && method_exists( 'PMXI_Plugin', 'getInstance' ) ) {
                PMXI_Plugin::getInstance()->updateOption( [ 'licenses' => $lic, 'statuses' => $st ] );
                return;
            }
        } catch ( \Throwable $e ) { /* fall through */ }
        if ( $key !== '' && class_exists( 'PMXI_Plugin' ) && method_exists( 'PMXI_Plugin', 'encode' ) ) $lic['PMXI_Plugin'] = PMXI_Plugin::encode( $key );
        $o['licenses'] = $lic; $o['statuses'] = $st;
        update_option( 'PMXI_Plugin_Options', $o, false );
    }

    private static function wpai_activate( string $key ): array {
        $args = [ 'edd_action' => 'activate_license', 'license' => $key, 'item_name' => 'WP All Import', 'url' => home_url() ];
        $res  = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), DFY_License_Remote::WPAI_API ), [ 'timeout' => 15 ] );
        if ( is_wp_error( $res ) ) return self::r( false, 'WP All Import: ' . $res->get_error_message() );
        $d = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        $status = (string) ( $d['license'] ?? '' );
        if ( $status !== 'valid' ) return self::r( false, 'WP All Import answered: ' . ( $d['error'] ?? ( $status ?: 'no answer' ) ) );
        self::wpai_save( $key, 'valid' );
        return self::r( true, 'Activated.' );
    }

    /* ── when were the DFY plugins first set up here? ───────── */

    /**
     * WordPress does not record when a license was activated, so we collect the
     * earliest dates the DFY plugins themselves left on the site. For an older DFY
     * project these sit close to the day the DFY team installed and licensed them.
     *
     * @return array<int,array{slug:string,label:string,date:string,source:string}> dates in UTC 'Y-m-d H:i:s', oldest first
     */
    public static function evidence(): array {
        global $wpdb;
        $out = [];
        $add = function ( string $slug, $ts, string $source ) use ( &$out ) {
            $ts = is_numeric( $ts ) ? (int) $ts : (int) strtotime( (string) $ts . ' UTC' );
            if ( $ts < 946684800 || $ts > time() + DAY_IN_SECONDS ) return; // ignore empty / nonsense dates
            $out[] = [ 'slug' => $slug, 'label' => $slug === 'site' ? 'Site' : DFY_License_Remote::label( $slug ), 'date' => gmdate( 'Y-m-d H:i:s', $ts ), 'source' => $source ];
        };
        $one = function ( string $sql ) use ( $wpdb ) {
            if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) return null;
            $wpdb->suppress_errors( true );
            $v = $wpdb->get_var( $sql );
            $wpdb->suppress_errors( false );
            return $v;
        };
        $table = function ( string $name ) use ( $wpdb, $one ): bool {
            return isset( $wpdb ) && (string) $one( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $name ) ) ) === $wpdb->prefix . $name;
        };

        // Elementor keeps a timestamp for every version it was installed/upgraded to.
        $hist = get_option( 'elementor_install_history', [] );
        if ( is_array( $hist ) && $hist ) $add( 'elementor-pro', min( array_map( 'intval', $hist ) ), 'Elementor first installed (Elementor install history)' );
        $hist = get_option( 'elementor_pro_install_history', [] );
        if ( is_array( $hist ) && $hist ) $add( 'elementor-pro', min( array_map( 'intval', $hist ) ), 'Elementor Pro first installed (install history)' );
        if ( isset( $wpdb->posts ) ) {
            $add( 'elementor-pro', $one( "SELECT MIN(post_date_gmt) FROM {$wpdb->posts} WHERE post_type = 'elementor_library' AND post_date_gmt > '2000-01-01'" ), 'First Elementor template created' );
            $add( 'learndash', $one( "SELECT MIN(post_date_gmt) FROM {$wpdb->posts} WHERE post_type IN ('sfwd-courses','sfwd-lessons','sfwd-topic','sfwd-quiz') AND post_date_gmt > '2000-01-01'" ), 'First LearnDash course/lesson created' );
        }
        if ( $table( 'gf_form' ) ) $add( 'gravityforms', $one( "SELECT MIN(date_created) FROM {$wpdb->prefix}gf_form" ), 'First Gravity Forms form created' );
        if ( $table( 'automatorwp_automations' ) ) $add( 'automatorwp-pro', $one( "SELECT MIN(date) FROM {$wpdb->prefix}automatorwp_automations WHERE date > '2000-01-01'" ), 'First AutomatorWP automation created' );
        if ( $table( 'pmxi_imports' ) ) $add( 'wp-all-import-pro', $one( "SELECT MIN(registered_on) FROM {$wpdb->prefix}pmxi_imports WHERE registered_on > '2000-01-01'" ), 'First WP All Import import created' );
        $lc = get_option( 'wpf_license_check' );
        if ( $lc ) $add( 'wp-fusion', $lc, 'WP Fusion last license check (latest date only)' );
        if ( isset( $wpdb->users ) ) $add( 'site', $one( "SELECT MIN(user_registered) FROM {$wpdb->users} WHERE user_registered > '2000-01-01'" ), 'First user account on the site (site created)' );

        usort( $out, function ( $a, $b ) { return strcmp( $a['date'], $b['date'] ); } );
        return $out;
    }

    /* ── Elementor Connect ─────────────────────────────────── */
    // Elementor Pro is activated with "Connect & Activate": the Elementor account's
    // tokens are stored per WordPress user in the user option elementor_connect_common_data
    // (Elementor core: Common_App / Base_User_App). The account id is the JWT "sub".

    /** @return array<int,array{user:int,meta_key:string,sub:string}> users connected to an Elementor account */
    public static function el_connections(): array {
        global $wpdb;
        if ( ! isset( $wpdb->usermeta ) || ! method_exists( $wpdb, 'get_results' ) ) return [];
        $rows = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE '%elementor\\_connect\\_common\\_data'", ARRAY_A );
        $out  = [];
        foreach ( (array) $rows as $r ) {
            $d   = maybe_unserialize( $r['meta_value'] );
            $sub = is_array( $d ) ? self::jwt_sub( (string) ( $d['access_token'] ?? '' ) ) : '';
            if ( $sub !== '' ) $out[] = [ 'user' => (int) $r['user_id'], 'meta_key' => (string) $r['meta_key'], 'sub' => $sub ];
        }
        return $out;
    }

    /** Elementor account ids connected on this site right now. */
    public static function el_accounts(): array {
        return array_values( array_unique( array_column( self::el_connections(), 'sub' ) ) );
    }

    private static function jwt_sub( string $t ): string {
        $p = explode( '.', $t );
        if ( count( $p ) !== 3 ) return '';
        $j = json_decode( (string) base64_decode( strtr( $p[1], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $p[1] ) % 4 ) % 4 ) ), true );
        return is_array( $j ) && isset( $j['sub'] ) ? (string) $j['sub'] : '';
    }

    /** Disconnect our (DFY) Elementor account from every WordPress user; other accounts are left alone. */
    public static function el_disconnect( array $accounts ): int {
        if ( ! $accounts ) return 0;
        $n = 0;
        foreach ( self::el_connections() as $c ) {
            if ( in_array( $c['sub'], $accounts, true ) ) { delete_user_meta( $c['user'], $c['meta_key'] ); $n++; }
        }
        return $n;
    }

    private static function el_forget(): void {
        self::el_disconnect( (array) ( get_option( 'dfy_lm', [] )['el_accounts'] ?? [] ) );
        delete_option( self::el_const( 'LICENSE_KEY_OPTION_NAME', 'elementor_pro_license_key' ) );
        delete_option( self::el_const( 'LICENSE_DATA_OPTION_NAME', '_elementor_pro_license_v2_data' ) );
        delete_option( self::el_const( 'LICENSE_DATA_FALLBACK_OPTION_NAME', '_elementor_pro_license_v2_data_fallback' ) );
        delete_transient( 'elementor_pro_license_data' ); // legacy cache, as Elementor's own reset does
        delete_option( '_elementor_pro_api_requests_lock' );
    }

    private static function r( bool $ok, string $msg ): array { return [ 'ok' => $ok, 'msg' => $msg ]; }
}
