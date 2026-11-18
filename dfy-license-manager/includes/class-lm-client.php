<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Talks to DFY License Hub, activates the DFY licenses, and removes them when
 * the period ends, when the hub says so, or when this plugin is deactivated.
 */
class DFY_LM_Client {

    const OPT = 'dfy_lm';

    public static function get(): array {
        return wp_parse_args( (array) get_option( self::OPT, [] ), [
            'hub' => '', 'site_id' => 0, 'secret' => '', 'home' => '',
            'expires_at' => null, 'test_mode' => false,
            'ours' => [], 'plugins' => [], 'send_keys' => [], 'el_accounts' => [],
            'connected_at' => 0, 'last_ok' => 0, 'last_error' => '', 'ended' => [], 'contact' => '',
        ] );
    }

    private static function put( array $c ): void { update_option( self::OPT, $c, false ); }

    public static function connected(): bool {
        $c = self::get();
        return $c['hub'] !== '' && $c['secret'] !== '';
    }

    /** A staging/local copy of the live site: stay passive here. */
    public static function is_copy(): bool {
        $c = self::get();
        if ( $c['home'] === '' ) return false;
        return strtolower( (string) wp_parse_url( $c['home'], PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
    }

    public static function expires_ts(): ?int {
        $e = self::get()['expires_at'];
        return $e ? (int) strtotime( $e ) : null;
    }

    /* ── connect ───────────────────────────────────────────── */

    /** @return array{ok:bool,msg:string} */
    public static function connect( string $code ): array {
        $code = trim( $code );
        if ( strpos( $code, 'DFY1.' ) !== 0 ) return [ 'ok' => false, 'msg' => 'That is not a DFY connection code.' ];
        $d = json_decode( (string) base64_decode( substr( $code, 5 ), true ), true );
        if ( ! is_array( $d ) || empty( $d['hub'] ) || empty( $d['id'] ) || empty( $d['token'] ) ) return [ 'ok' => false, 'msg' => 'The connection code is damaged. Copy it again.' ];
        $hub = trailingslashit( esc_url_raw( (string) $d['hub'] ) );
        if ( wp_parse_url( $hub, PHP_URL_SCHEME ) !== 'https' && ! self::local_host( $hub ) ) return [ 'ok' => false, 'msg' => 'The hub must use https.' ];

        $res = self::post( $hub . 'connect', [
            'site_id'   => (int) $d['id'],
            'token'     => (string) $d['token'],
            'urls'      => DFY_LM_Vendors::urls(),
            'installed' => DFY_LM_Vendors::installed(),
            'meta'      => DFY_LM_Vendors::meta(),
            'evidence'  => DFY_LM_Vendors::evidence(),
        ] );
        if ( is_wp_error( $res ) ) return [ 'ok' => false, 'msg' => $res->get_error_message() ];
        $res['site_id'] = $res['site_id'] ?? (int) $d['id'];
        return self::attach( $hub, $res );
    }

    /**
     * The hub this plugin connects to by itself:
     * DFY_LM_HUB in wp-config.php, else hub-config.php shipped with the plugin build
     * (not in the Git repository; see hub-config.example.php).
     */
    public static function default_hub(): string {
        if ( defined( 'DFY_LM_HUB' ) && DFY_LM_HUB ) return trailingslashit( (string) DFY_LM_HUB );
        if ( ! defined( 'DFY_LM_DEFAULT_HUB' ) && is_readable( dirname( __DIR__ ) . '/hub-config.php' ) ) include dirname( __DIR__ ) . '/hub-config.php';
        return defined( 'DFY_LM_DEFAULT_HUB' ) && DFY_LM_DEFAULT_HUB ? trailingslashit( (string) DFY_LM_DEFAULT_HUB ) : '';
    }

    /**
     * Connect without a code: ask the hub, which checks this site is the URL
     * added in "Add site" by reading back a one-time challenge from this site.
     * @return array{ok:bool,msg:string}
     */
    public static function auto_connect( bool $force = false ): array {
        if ( self::connected() && ! self::is_copy() ) return [ 'ok' => true, 'msg' => 'Already connected.' ];
        if ( ! $force && get_transient( 'dfy_lm_claim_wait' ) ) return [ 'ok' => false, 'msg' => 'Waiting before trying again.' ];
        if ( get_transient( 'dfy_lm_challenge' ) ) return [ 'ok' => false, 'msg' => 'A connection attempt is already running.' ];
        $ch = bin2hex( random_bytes( 24 ) );
        set_transient( 'dfy_lm_challenge', $ch, 2 * MINUTE_IN_SECONDS );
        $hub = self::default_hub();
        if ( $hub === '' ) {
            delete_transient( 'dfy_lm_challenge' );
            update_option( 'dfy_lm_claim_error', 'No DFY License Hub address is set (hub-config.php or DFY_LM_HUB).', false );
            return [ 'ok' => false, 'msg' => 'No DFY License Hub address is set. Use the connection code instead.' ];
        }
        $res = self::post( $hub . 'claim', [
            'challenge' => $ch,
            'urls'      => DFY_LM_Vendors::urls(),
            'installed' => DFY_LM_Vendors::installed(),
            'meta'      => DFY_LM_Vendors::meta(),
            'evidence'  => DFY_LM_Vendors::evidence(),
        ] );
        delete_transient( 'dfy_lm_challenge' );
        if ( is_wp_error( $res ) ) {
            set_transient( 'dfy_lm_claim_wait', 1, 10 * MINUTE_IN_SECONDS );
            update_option( 'dfy_lm_claim_error', $res->get_error_message(), false );
            return [ 'ok' => false, 'msg' => $res->get_error_message() ];
        }
        delete_option( 'dfy_lm_claim_error' );
        return self::attach( $hub, $res );
    }

    /**
     * The hub pings this site: connect now (not connected yet) or check in now
     * (new end date, or a removal command). Only ever talks to its own hub.
     */
    public static function handle_ping(): array {
        if ( get_transient( 'dfy_lm_ping' ) ) return [ 'connected' => self::connected(), 'msg' => 'Busy, try again in a moment.' ];
        set_transient( 'dfy_lm_ping', 1, 20 );
        if ( self::connected() && ! self::is_copy() ) {
            self::heartbeat();
            delete_transient( 'dfy_lm_ping' );
            return [ 'connected' => self::connected() ];
        }
        $r = self::auto_connect( true );
        delete_transient( 'dfy_lm_ping' );
        return [ 'connected' => $r['ok'], 'msg' => $r['msg'] ];
    }

    /** One-time challenge the hub reads back during auto_connect(). */
    public static function challenge(): string { return (string) get_transient( 'dfy_lm_challenge' ); }

    /** Store the connection, activate the keys, report back. */
    private static function attach( string $hub, array $res ): array {
        if ( self::is_copy() ) delete_option( self::OPT ); // a copy of another DFY site now gets its own connection
        $c = self::get();
        $c['hub']          = $hub;
        $c['site_id']      = (int) ( $res['site_id'] ?? $c['site_id'] );
        $c['secret']       = DFY_Crypto::encrypt( (string) $res['secret'] );
        $c['home']         = home_url();
        $c['connected_at'] = time();
        $c['expires_at']   = $res['expires_at'] ?? null;
        $c['test_mode']    = ! empty( $res['test_mode'] );

        self::put( $c );
        $lines = self::activate_licenses( (array) ( $res['licenses'] ?? [] ) );
        unset( $res ); // keys are not kept by this plugin
        self::heartbeat();
        return [ 'ok' => true, 'msg' => $lines ? 'Connected. ' . implode( ' ', $lines ) : 'Connected. None of the DFY premium plugins are active on this site yet; activate them and click "Check in now".' ];
    }

    /** Activate keys received from the hub. Keys themselves are never stored by this plugin. */
    private static function activate_licenses( array $licenses ): array {
        $c     = self::get();
        $lines = [];
        foreach ( $licenses as $slug => $lic ) {
            if ( ! isset( DFY_License_Remote::VENDORS[ $slug ] ) || ! is_array( $lic ) ) continue;
            if ( DFY_LM_Vendors::has_license( $slug ) ) {
                // Already licensed (activated by hand during the build): keep it and track it instead of re-activating.
                self::adopt( $c, $slug );
                $lines[] = DFY_License_Remote::label( $slug ) . ': already licensed; now managed by DFY License Manager.';
                continue;
            }
            $r = DFY_LM_Vendors::activate( $slug, $lic );
            $c['plugins'][ $slug ] = [ 'state' => $r['ok'] ? 'active' : 'activate_failed', 'msg' => $r['msg'] ];
            if ( $r['ok'] ) { $c['ours'][ $slug ] = DFY_LM_Vendors::fingerprint( $slug, (string) $lic['key'] ); $c['ended'] = []; }
            $lines[] = DFY_License_Remote::label( $slug ) . ': ' . $r['msg'];
        }
        self::put( $c );
        return $lines;
    }

    /** Start tracking a license that is already on the site. */
    private static function adopt( array &$c, string $slug ): void {
        $c['ours'][ $slug ]    = DFY_LM_Vendors::current_fingerprint( $slug );
        $c['ended']            = [];
        if ( $slug === 'elementor-pro' ) $c['el_accounts'] = DFY_LM_Vendors::el_accounts(); // the DFY Elementor account used for "Connect & Activate"
        $c['plugins'][ $slug ] = [ 'state' => 'active', 'msg' => 'Was already licensed on this site; now managed by DFY License Manager.' ];
        if ( $slug !== 'gravityforms' ) $c['send_keys'][ $slug ] = 1; // Gravity Forms is removed with its site key, not the license key
    }

    /** Take over every DFY plugin license already active here that we do not track yet. @return string[] slugs */
    private static function adopt_existing(): array {
        $c   = self::get();
        $new = [];
        foreach ( DFY_LM_Vendors::installed() as $slug ) {
            if ( isset( $c['ours'][ $slug ] ) || ! DFY_LM_Vendors::has_license( $slug ) ) continue;
            self::adopt( $c, $slug );
            $new[] = $slug;
        }
        if ( $new ) self::put( $c );
        return $new;
    }

    /* ── check-in ──────────────────────────────────────────── */

    public static function heartbeat( int $depth = 0 ): void {
        if ( ! self::connected() || self::is_copy() || $depth > 2 ) return;
        $c = self::get();

        $states = [];
        foreach ( $c['ours'] as $slug => $fp ) {
            $mine = DFY_LM_Vendors::still_ours( $slug, $fp );
            $lic  = DFY_LM_Vendors::is_licensed( $slug );
            if ( $mine === false )      $states[ $slug ] = [ 'state' => 'replaced', 'msg' => 'Customer switched to their own key.' ];
            elseif ( $mine === null )   $states[ $slug ] = [ 'state' => 'missing', 'msg' => 'No key stored on the site any more.' ];
            else                        $states[ $slug ] = [ 'state' => $lic === false ? 'inactive' : 'active', 'msg' => DFY_LM_Vendors::loaded( $slug ) ? '' : 'Plugin deactivated on the site.' ];
        }
        foreach ( $c['plugins'] as $slug => $p ) if ( ! isset( $states[ $slug ] ) ) $states[ $slug ] = $p;

        $res = self::signed( 'heartbeat', [
            'urls'      => DFY_LM_Vendors::urls(),
            'installed' => DFY_LM_Vendors::installed(),
            'plugins' => $states,
            'meta'    => DFY_LM_Vendors::meta(),
            'keys'    => self::keys_to_send( $c ),
            'evidence' => DFY_LM_Vendors::evidence(),
            'version' => DFY_LM_VERSION,
        ] );
        $c = self::get();
        if ( is_wp_error( $res ) ) {
            if ( $res->get_error_code() === 'dfy_bad_signature' ) {
                // The hub no longer knows this site (deleted there). Stop checking in with old
                // credentials; if the site is added again, it connects by itself.
                foreach ( [ 'hub', 'secret' ] as $k ) $c[ $k ] = '';
                $c['site_id'] = 0;
                $c['last_error'] = 'Removed from DFY License Hub.';
                self::put( $c );
                return;
            }
            $c['last_error'] = $res->get_error_message();
            self::put( $c );
            self::maybe_expire_offline();
            return;
        }
        $c['last_ok']    = time();
        $c['last_error'] = '';
        $c['plugins']    = $states;
        $c['send_keys']  = [];
        if ( ( $res['command'] ?? '' ) !== 'copy' ) {
            $c['expires_at'] = $res['expires_at'] ?? null;
            $c['test_mode']  = ! empty( $res['test_mode'] );
            $c['contact']    = esc_url_raw( (string) ( $res['contact'] ?? '' ) );
        }
        self::put( $c );
        if ( ! empty( $res['licenses'] ) ) {
            // A DFY plugin was installed after connecting: activate it, then report its state.
            self::activate_licenses( (array) $res['licenses'] );
            unset( $res['licenses'] );
            self::heartbeat( $depth + 1 );
            return;
        }
        if ( ! empty( $res['adopt'] ) && self::adopt_existing() ) {
            self::heartbeat( $depth + 1 ); // report the licenses we just took over
            return;
        }

        if ( ( $res['command'] ?? '' ) === 'remove' ) {
            self::remove_all( 'command' );   // no-op once nothing of ours is left
        } elseif ( self::expired() && ! $c['test_mode'] ) {
            self::remove_all( 'expired' );
        }
    }

    /** Keys of licenses we just took over, sent once so the hub can remove them even if this site disappears. */
    private static function keys_to_send( array $c ): array {
        $out = [];
        foreach ( array_keys( (array) ( $c['send_keys'] ?? [] ) ) as $slug ) {
            $k = DFY_LM_Vendors::stored_key( $slug );
            if ( $k !== '' ) $out[ $slug ] = $k;
        }
        return $out;
    }

    public static function expired(): bool {
        $e = self::expires_ts();
        return $e !== null && time() >= $e;
    }

    /** Hub unreachable: still honour the expiry date we already know. */
    private static function maybe_expire_offline(): void {
        $c = self::get();
        if ( self::expired() && ! $c['test_mode'] ) self::remove_all( 'expired' );
    }

    /* ── removal ───────────────────────────────────────────── */

    /**
     * Remove every DFY license from this site and report to the hub.
     * DFY License Manager itself stays installed and connected: it then shows the
     * licenses as deactivated with a notice, and the hub can activate them again.
     * $reason: expired | command | plugin_deactivated | plugin_uninstalled
     */
    public static function remove_all( string $reason ): array {
        if ( ! self::connected() || self::is_copy() ) return [];
        $c = self::get();
        if ( ! $c['ours'] ) return [];                       // nothing of ours left on the site
        if ( get_transient( 'dfy_lm_removing' ) ) return [];
        set_transient( 'dfy_lm_removing', 1, 5 * MINUTE_IN_SECONDS );

        $results = [];
        foreach ( $c['ours'] as $slug => $fp ) {
            $results[ $slug ] = DFY_LM_Vendors::deactivate( $slug, (string) $fp );
        }
        self::signed( 'event', [ 'type' => 'removed', 'reason' => $reason, 'results' => $results, 'urls' => DFY_LM_Vendors::urls() ] );

        $c = self::get();
        foreach ( $results as $slug => $r ) {
            if ( ! empty( $r['ok'] ) || ! empty( $r['replaced'] ) ) unset( $c['ours'][ $slug ] );
            $c['plugins'][ $slug ] = ! empty( $r['replaced'] )
                ? [ 'state' => 'replaced', 'msg' => 'You use your own license key; it was left alone.' ]
                : [ 'state' => ! empty( $r['ok'] ) ? 'removed' : 'remove_failed', 'msg' => (string) ( $r['msg'] ?? '' ) ];
        }
        $c['send_keys']   = [];
        $c['el_accounts'] = ! isset( $c['ours']['elementor-pro'] ) ? [] : $c['el_accounts'];
        $c['ended']       = [ 'time' => time(), 'reason' => $reason ];
        self::put( $c );
        update_option( 'dfy_lm_last_removal', [ 'time' => time(), 'reason' => $reason, 'results' => $results ], false );
        delete_transient( 'dfy_lm_removing' );
        return $results;
    }

    /** True once the DFY licenses were removed and not activated again. */
    public static function ended(): bool {
        $c = self::get();
        return ! empty( $c['ended'] ) && ! $c['ours'];
    }

    /* ── HTTP ──────────────────────────────────────────────── */

    private static function signed( string $route, array $body ) {
        $c      = self::get();
        $secret = DFY_Crypto::decrypt( (string) $c['secret'] );
        if ( $c['hub'] === '' || $secret === '' ) return new WP_Error( 'dfy_lm', 'Not connected.' );
        $json = wp_json_encode( $body );
        $time = (string) time();
        return self::post( $c['hub'] . $route, $json, [
            'X-DFY-Site'      => (string) $c['site_id'],
            'X-DFY-Time'      => $time,
            'X-DFY-Signature' => DFY_Crypto::sign( $secret, $time, $json ),
        ] );
    }

    /** @param array|string $body */
    private static function post( string $url, $body, array $headers = [] ) {
        $res = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => $headers + [ 'Content-Type' => 'application/json' ],
            'body'    => is_string( $body ) ? $body : wp_json_encode( $body ),
        ] );
        if ( is_wp_error( $res ) ) return new WP_Error( 'dfy_lm', 'Could not reach DFY License Hub: ' . $res->get_error_message() );
        $code = (int) wp_remote_retrieve_response_code( $res );
        $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( $code >= 300 || ! is_array( $data ) ) {
            return new WP_Error( is_array( $data ) && ! empty( $data['code'] ) ? (string) $data['code'] : 'dfy_lm', is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : "DFY License Hub answered HTTP {$code}." );
        }
        return $data;
    }

    private static function local_host( string $url ): bool {
        $h = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        return in_array( $h, [ 'localhost', '127.0.0.1' ], true ) || preg_match( '/\.(local|test|localhost)$/', $h );
    }
}
