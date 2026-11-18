<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * REST endpoints the client sites call:
 *   POST /wp-json/dfy-lh/v1/connect    one-time connection code → licenses + site secret
 *   POST /wp-json/dfy-lh/v1/claim      automatic connection: the site proves it is the URL added in the hub
 *   POST /wp-json/dfy-lh/v1/heartbeat  daily check-in (signed)
 *   POST /wp-json/dfy-lh/v1/event      "I removed my licenses" report (signed)
 */
class DFY_LH_Rest {

    const NS = 'dfy-lh/v1';

    /** Never activated by key: Elementor Pro (Agency plan) only accepts "Connect & Activate" on the site. */
    const CONNECT_ONLY = [ 'elementor-pro' ];
    const EL_MSG = 'Not licensed yet. Elementor Pro is activated with Connect & Activate: on the site go to Elementor → License → Connect & Activate with the DFY Elementor account. DFY License Manager takes it over within a minute and removes it after the year.';

    public static function routes(): void {
        foreach ( [ 'connect', 'claim', 'heartbeat', 'event' ] as $r ) {
            register_rest_route( self::NS, "/{$r}", [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, $r ],
                'permission_callback' => '__return_true', // authenticated by connection token / HMAC signature below
            ] );
        }
    }

    /** Connection code shown in the hub, pasted into the client plugin. */
    public static function connection_code( array $site ): string {
        $token = DFY_Crypto::decrypt( (string) $site['token_enc'] );
        if ( $token === '' ) return '';
        return 'DFY1.' . base64_encode( wp_json_encode( [ 'hub' => rest_url( self::NS . '/' ), 'id' => $site['id'], 'token' => $token ] ) );
    }

    public static function new_token( int $id ): void {
        DFY_LH_Store::update( $id, [ 'token_enc' => DFY_Crypto::encrypt( bin2hex( random_bytes( 24 ) ) ) ] );
    }

    public static function connect( WP_REST_Request $req ) {
        $b    = (array) json_decode( $req->get_body(), true );
        $site = DFY_LH_Store::get( (int) ( $b['site_id'] ?? 0 ) );
        $tok  = $site ? DFY_Crypto::decrypt( (string) $site['token_enc'] ) : '';
        if ( ! $site || $tok === '' || ! hash_equals( $tok, (string) ( $b['token'] ?? '' ) ) ) {
            return new WP_Error( 'dfy_bad_code', 'This connection code is not valid or was already used. Create a new one in DFY License Hub.', [ 'status' => 403 ] );
        }
        if ( $site['status'] === 'removed' ) {
            return new WP_Error( 'dfy_closed', 'The DFY license period for this site has ended.', [ 'status' => 403 ] );
        }
        return self::attach( $site, $b );
    }

    /**
     * Automatic connection. The site sends a random challenge; the hub fetches it
     * back from the URL entered in "Add site". Only that site can answer, so no
     * code has to be copied.
     */
    public static function claim( WP_REST_Request $req ) {
        $b    = (array) json_decode( $req->get_body(), true );
        $host = self::host( (string) ( $b['urls']['home_url'] ?? '' ) );
        $ch   = (string) ( $b['challenge'] ?? '' );
        if ( $host === '' || strlen( $ch ) < 32 ) return new WP_Error( 'dfy_bad_claim', 'Bad request.', [ 'status' => 400 ] );
        $site = null;
        foreach ( DFY_LH_Store::all( 'pending' ) as $s ) if ( self::host( $s['site_url'] ) === $host ) { $site = $s; break; }
        if ( ! $site ) return new WP_Error( 'dfy_no_site', 'This site has not been added in DFY License Hub yet.', [ 'status' => 404 ] );

        // Ask the registered URL (never a URL from the request) for the challenge.
        // POST + unique URL so no page cache (e.g. LiteSpeed caches REST GETs) can answer instead of the site.
        $res = wp_remote_post( self::client_url( $site['site_url'], 'challenge' ) . '&nc=' . bin2hex( random_bytes( 8 ) ), [ 'timeout' => 20, 'headers' => [ 'Cache-Control' => 'no-cache' ] ] );
        $got = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $got ) || ! hash_equals( $ch, (string) ( $got['challenge'] ?? '' ) ) ) {
            $why = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) . ( wp_remote_retrieve_header( $res, 'x-litespeed-cache' ) ? ', LiteSpeed cache ' . wp_remote_retrieve_header( $res, 'x-litespeed-cache' ) : '' );
            DFY_LH_Store::log( $site['id'], 'Automatic connection refused: ' . self::host( $site['site_url'] ) . " could not confirm it made the request ({$why})." );
            return new WP_Error( 'dfy_unverified', 'The hub could not confirm this site. Check that the URL in DFY License Hub is the live site URL.', [ 'status' => 403 ] );
        }
        return self::attach( $site, $b );
    }

    /** Address of a DFY License Manager endpoint on the client site (works without pretty permalinks). */
    public static function client_url( string $site_url, string $route ): string {
        return trailingslashit( $site_url ) . '?rest_route=/dfy-lm/v1/' . $route;
    }

    /** Ask the client site to connect now (used right after "Add site"). */
    /** @return bool  true when the site answered that it is connected */
    public static function ping( int $id, string $mode = 'connect' ): bool {
        $site = DFY_LH_Store::get( $id );
        if ( ! $site ) return false;
        if ( $mode === 'checkin' ) {
            // Connected site: ask it to check in now (new end date, removal or activation).
            if ( $site['status'] !== 'active' ) return false;
            $res = wp_remote_post( self::client_url( $site['site_url'], 'ping' ) . '&nc=' . bin2hex( random_bytes( 8 ) ), [ 'timeout' => 60, 'headers' => [ 'Cache-Control' => 'no-cache' ] ] );
            $d   = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
            return is_array( $d ) && ! empty( $d['connected'] );
        }
        if ( $site['status'] !== 'pending' ) return false;
        $res = wp_remote_post( self::client_url( $site['site_url'], 'ping' ) . '&nc=' . bin2hex( random_bytes( 8 ) ), [ 'timeout' => 60, 'headers' => [ 'Cache-Control' => 'no-cache' ] ] );
        $code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
        if ( DFY_LH_Store::get( $id )['status'] === 'pending' ) {
            DFY_LH_Store::log( $id, $code === 404 || $code === 0
                ? 'DFY License Manager is not active on the site yet. It connects by itself as soon as it is installed and activated.'
                : 'Asked the site to connect, but it has not connected yet (HTTP ' . $code . ').' );
        }
        return DFY_LH_Store::get( $id )['status'] === 'active';
    }

    /** DFY plugins on the site that are neither licensed there nor have an agency key in the hub. */
    private static function mark_unlicensed( array $plugins, array $installed ): array {
        foreach ( array_intersect( $installed, array_keys( DFY_License_Remote::VENDORS ) ) as $slug ) {
            if ( ! isset( $plugins[ $slug ] ) || ( $plugins[ $slug ]['state'] ?? '' ) === 'not_installed' ) {
                $plugins[ $slug ] = [ 'state' => 'unlicensed', 'msg' => in_array( $slug, self::CONNECT_ONLY, true )
                    ? self::EL_MSG
                    : 'Installed but not licensed. Add its key on the Licenses tab; it is activated at the next check-in.', 'at' => current_time( 'mysql', true ) ];
            }
        }
        return $plugins;
    }

    public static function host( string $url ): string {
        return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
    }

    /** Hand out the secret + licenses and start the 1-year period. */
    private static function attach( array $site, array $b ) {
        $urls      = self::urls( $b['urls'] ?? [] );

        // Evidence of when the DFY plugins were first set up on the site (for older projects).
        $flags = $site['flags'];
        $flags['evidence']     = self::clean_evidence( $b['evidence'] ?? [] );
        $flags['connected_at'] = current_time( 'mysql', true );
        DFY_LH_Store::update( $site['id'], [ 'flags' => $flags ] );
        if ( ! $site['start_at'] ) {
            $found = ! empty( $flags['detect_start'] ) ? self::suggested_start( $flags['evidence'] ) : null;
            if ( $found ) {
                DFY_LH_Engine::set_start( (int) $site['id'], $found['date'], 'detected' );
                DFY_LH_Store::log( $site['id'], "Older project: start date taken from the site — {$found['source']} (" . DFY_LH_Engine::fmt( $found['date'] ) . ').' );
            } else {
                if ( ! empty( $flags['detect_start'] ) ) DFY_LH_Store::log( $site['id'], 'Older project, but the site has no usable date; the period starts today. Set the right date by hand if you know it.' );
                DFY_LH_Engine::set_start( (int) $site['id'], current_time( 'mysql', true ), 'connected' ); // the 1-year period starts now
            }
        }
        $site = DFY_LH_Store::get( $site['id'] );
        $over = DFY_LH_Engine::is_expired( $site ); // older project whose year is already over: send no keys
        $installed = array_values( array_intersect( (array) ( $b['installed'] ?? [] ), array_keys( DFY_License_Remote::VENDORS ) ) );
        $licenses  = [];
        $plugins   = $site['plugins'];
        foreach ( DFY_LH_Store::licenses() as $slug => $l ) {
            if ( $l['key'] === '' || $over || in_array( $slug, self::CONNECT_ONLY, true ) ) continue;
            if ( in_array( $slug, $installed, true ) ) {
                $licenses[ $slug ] = $l;
                $plugins[ $slug ]  = [ 'state' => 'sent', 'msg' => 'Key sent to the site.', 'at' => current_time( 'mysql', true ) ];
            } elseif ( ! isset( $plugins[ $slug ] ) ) {
                $plugins[ $slug ] = [ 'state' => 'not_installed', 'msg' => 'Plugin not installed on the site.', 'at' => current_time( 'mysql', true ) ];
            }
        }

        $plugins = self::mark_unlicensed( $plugins, $installed );
        $secret  = bin2hex( random_bytes( 32 ) );
        DFY_LH_Store::update( $site['id'], [
            'token_enc'  => '',
            'secret_enc' => DFY_Crypto::encrypt( $secret ),
            'status'     => 'active',
            'urls'       => $urls,
            'plugins'    => $plugins,
            'last_seen'  => current_time( 'mysql', true ),
            'meta_enc'   => DFY_Crypto::enc_json( self::meta( $b['meta'] ?? [], [] ) ),
        ] );
        $host_entered = strtolower( (string) wp_parse_url( $site['site_url'], PHP_URL_HOST ) );
        $host_seen    = strtolower( (string) wp_parse_url( $urls['home_url'], PHP_URL_HOST ) );
        DFY_LH_Store::log( $site['id'], "Connected from {$urls['home_url']}. Keys sent for: " . ( $licenses ? DFY_LH_Engine::names( array_keys( $licenses ) ) : 'none (no matching plugins installed)' ) . '.' .
            ( $host_entered && $host_seen && $host_entered !== $host_seen ? " Note: the site reports {$host_seen}, but {$host_entered} was entered in the hub." : '' ) );

        if ( $over ) DFY_LH_Store::log( $site['id'], 'The 1-year period from that date has already ended (' . DFY_LH_Engine::fmt( $site['expires_at'] ) . '): no keys sent. ' . ( DFY_LH_Store::setting( 'test_mode' ) ? 'Test mode is on, so nothing is removed automatically.' : 'The DFY licenses on the site are removed at its next check-in.' ) );
        $site = DFY_LH_Store::get( $site['id'] );
        return [ 'secret' => $secret, 'site_id' => (int) $site['id'], 'licenses' => $licenses ] + self::state( $site );
    }

    public static function heartbeat( WP_REST_Request $req ) {
        $site = self::verify( $req );
        if ( is_wp_error( $site ) ) return $site;
        $b = (array) json_decode( $req->get_body(), true );
        if ( self::is_copy( $site, $b ) ) return [ 'expires_at' => null, 'command' => 'copy', 'test_mode' => true ];

        $plugins = $site['plugins'];
        foreach ( (array) ( $b['plugins'] ?? [] ) as $slug => $p ) {
            if ( ! isset( DFY_License_Remote::VENDORS[ $slug ] ) ) continue;
            $state = sanitize_key( (string) ( $p['state'] ?? '' ) );
            // Once removed, a site cannot mark itself active again.
            if ( ( $plugins[ $slug ]['state'] ?? '' ) === 'removed' && $state !== 'removed' ) continue;
            $plugins[ $slug ] = [ 'state' => $state, 'msg' => sanitize_text_field( (string) ( $p['msg'] ?? '' ) ), 'at' => current_time( 'mysql', true ) ];
            if ( in_array( $slug, self::CONNECT_ONLY, true ) && in_array( $state, [ 'activate_failed', 'inactive', 'missing' ], true ) ) $plugins[ $slug ] = [ 'state' => 'unlicensed', 'msg' => self::EL_MSG, 'at' => current_time( 'mysql', true ) ];
        }
        $flags = $site['flags'];
        if ( ! empty( $flags['silent_alerted'] ) ) {
            unset( $flags['silent_alerted'] );
            DFY_LH_Store::log( $site['id'], 'Checking in again.' );
        }
        // DFY plugins installed after connecting get their key now (only during the license period).
        $licenses = [];
        if ( $site['status'] === 'active' && ! DFY_LH_Engine::is_expired( $site ) && $site['command'] === '' ) {
            $all  = DFY_LH_Store::licenses();
            $mine = (array) ( DFY_LH_Store::meta( $site )['keys'] ?? [] ); // keys this site reported (licenses it took over)
            foreach ( array_intersect( (array) ( $b['installed'] ?? [] ), array_keys( DFY_License_Remote::VENDORS ) ) as $slug ) {
                $st = $plugins[ $slug ]['state'] ?? '';
                $retry = $st === 'inactive' && self::adopt_open( $site ) && empty( $site['flags'][ 'resent_' . $slug ] );
                if ( $retry ) { $site['flags'][ 'resent_' . $slug ] = 1; $flags[ 'resent_' . $slug ] = 1; }
                // 'removed' here means the licenses were activated again in the hub ("Activate licenses").
                if ( in_array( $slug, self::CONNECT_ONLY, true ) ) { if ( in_array( $st, [ '', 'not_installed', 'removed' ], true ) ) $plugins[ $slug ] = [ 'state' => 'unlicensed', 'msg' => self::EL_MSG, 'at' => current_time( 'mysql', true ) ]; continue; }
                if ( ! $retry && ! in_array( $st, [ '', 'not_installed', 'unlicensed', 'removed' ], true ) ) continue;
                $key = (string) ( $mine[ $slug ] ?? '' ) !== '' && $slug !== 'gravityforms' ? (string) $mine[ $slug ] : (string) ( $all[ $slug ]['key'] ?? '' );
                if ( $key === '' ) continue;
                $licenses[ $slug ] = [ 'key' => $key, 'email' => (string) ( $all[ $slug ]['email'] ?? '' ) ];
                $plugins[ $slug ]  = [ 'state' => 'sent', 'msg' => 'Key sent to the site.', 'at' => current_time( 'mysql', true ) ];
            }
            if ( $licenses ) DFY_LH_Store::log( $site['id'], 'Keys sent: ' . DFY_LH_Engine::names( array_keys( $licenses ) ) . '.' );
        }
        $plugins = self::mark_unlicensed( $plugins, (array) ( $b['installed'] ?? [] ) );
        if ( isset( $b['evidence'] ) ) $flags['evidence'] = self::clean_evidence( $b['evidence'] );
        DFY_LH_Store::update( $site['id'], [
            'last_seen' => current_time( 'mysql', true ),
            'urls'      => self::urls( $b['urls'] ?? $site['urls'] ),
            'plugins'   => $plugins,
            'flags'     => $flags,
            'meta_enc'  => DFY_Crypto::enc_json( self::keys( $b['keys'] ?? [], self::meta( $b['meta'] ?? [], DFY_LH_Store::meta( $site ) ), $site ) ),
        ] );
        return self::state( DFY_LH_Store::get( $site['id'] ) ) + ( $licenses ? [ 'licenses' => $licenses ] : [] );
    }

    public static function event( WP_REST_Request $req ) {
        $site = self::verify( $req );
        if ( is_wp_error( $site ) ) return $site;
        $b = (array) json_decode( $req->get_body(), true );
        if ( self::is_copy( $site, $b ) ) return [ 'ok' => false, 'copy' => true ];
        if ( ( $b['type'] ?? '' ) === 'removed' ) {
            DFY_LH_Store::update( $site['id'], [ 'last_seen' => current_time( 'mysql', true ) ] );
            DFY_LH_Engine::record_client_removal( DFY_LH_Store::get( $site['id'] ), (string) ( $b['reason'] ?? '' ), (array) ( $b['results'] ?? [] ) );
        }
        return [ 'ok' => true ];
    }

    /* ── helpers ───────────────────────────────────────────── */

    private static function state( array $site ): array {
        return [
            'expires_at' => $site['expires_at'] ? gmdate( 'c', strtotime( $site['expires_at'] . ' UTC' ) ) : null,
            'command'    => DFY_LH_Engine::command_for( $site ),
            'test_mode'  => (bool) DFY_LH_Store::setting( 'test_mode' ),
            'adopt'      => self::adopt_open( $site ),
            'contact'    => (string) DFY_LH_Store::setting( 'contact_url' ),
        ];
    }

    /**
     * Licenses that are already on the site (activated by hand by the DFY team)
     * are taken over by DFY License Manager, so they are removed after the year too.
     * Allowed until 30 days after the clock starts; after that, new licenses are the customer's own.
     */
    public static function adopt_open( array $site ): bool {
        if ( ! in_array( $site['status'], [ 'active', 'pending' ], true ) || $site['command'] !== '' ) return false;
        // Older projects connect long after their start date: their licenses must still be taken over
        // (also when the year is already over, so they can be removed), for 30 days after connecting.
        $since = max( $site['start_at'] ? strtotime( $site['start_at'] . ' UTC' ) : 0, ! empty( $site['flags']['connected_at'] ) ? strtotime( $site['flags']['connected_at'] . ' UTC' ) : 0 );
        if ( DFY_LH_Engine::is_expired( $site ) && ( $site['start_source'] !== 'detected' || empty( $site['flags']['connected_at'] ) ) ) return false;
        return ! $site['start_at'] || time() < $since + 30 * DAY_IN_SECONDS;
    }

    /** @return array<int,array{slug:string,label:string,date:string,source:string}> */
    private static function clean_evidence( $in ): array {
        $out = [];
        foreach ( array_slice( (array) $in, 0, 30 ) as $e ) {
            if ( ! is_array( $e ) || empty( $e['date'] ) || ! strtotime( (string) $e['date'] . ' UTC' ) ) continue;
            $slug = sanitize_key( (string) ( $e['slug'] ?? '' ) );
            $out[] = [ 'slug' => $slug, 'label' => sanitize_text_field( (string) ( $e['label'] ?? $slug ) ), 'date' => gmdate( 'Y-m-d H:i:s', strtotime( (string) $e['date'] . ' UTC' ) ), 'source' => sanitize_text_field( (string) ( $e['source'] ?? '' ) ) ];
        }
        return $out;
    }

    /** Earliest date a DFY premium plugin left on the site (site creation and "last check" dates are only shown, not used). */
    public static function suggested_start( array $evidence ): ?array {
        foreach ( $evidence as $e ) {
            if ( $e['slug'] === 'site' || $e['slug'] === 'wp-fusion' ) continue;
            if ( isset( DFY_License_Remote::VENDORS[ $e['slug'] ] ) ) return $e; // evidence is oldest first
        }
        return null;
    }

    /** Keys of licenses the site took over, kept encrypted so the hub can remove them if the site disappears. */
    private static function keys( $in, array $meta, array $site ): array {
        if ( ! self::adopt_open( $site ) ) return $meta;
        $new = [];
        foreach ( (array) $in as $slug => $k ) {
            if ( isset( DFY_License_Remote::VENDORS[ $slug ] ) && is_string( $k ) && trim( $k ) !== '' && strlen( $k ) < 200 ) {
                if ( ( $meta['keys'][ $slug ] ?? '' ) !== trim( $k ) ) $new[] = $slug;
                $meta['keys'][ $slug ] = trim( $k );
            }
        }
        if ( $new ) DFY_LH_Store::log( $site['id'], 'Key received for licenses the site took over: ' . DFY_LH_Engine::names( $new ) . ' (stored encrypted for backup removal).' );
        return $meta;
    }

    /** @return array|WP_Error the site row */
    private static function verify( WP_REST_Request $req ) {
        $id   = (int) $req->get_header( 'x_dfy_site' );
        $time = (string) $req->get_header( 'x_dfy_time' );
        $sig  = (string) $req->get_header( 'x_dfy_signature' );
        $site = $id ? DFY_LH_Store::get( $id ) : null;
        $sec  = $site ? DFY_Crypto::decrypt( (string) $site['secret_enc'] ) : '';
        if ( ! $site || $sec === '' || abs( time() - (int) $time ) > 900
            || ! hash_equals( DFY_Crypto::sign( $sec, $time, $req->get_body() ), $sig ) ) {
            return new WP_Error( 'dfy_bad_signature', 'Unknown site or bad signature.', [ 'status' => 403 ] );
        }
        return $site;
    }

    /**
     * A staging/local copy of the site carries the same secret. Ignore it so it
     * cannot overwrite the live site's URLs or mark the live licenses removed.
     */
    private static function is_copy( array $site, array $b ): bool {
        $live = strtolower( (string) wp_parse_url( (string) ( $site['urls']['home_url'] ?? '' ), PHP_URL_HOST ) );
        $from = strtolower( (string) wp_parse_url( (string) ( $b['urls']['home_url'] ?? '' ), PHP_URL_HOST ) );
        if ( $live === '' || $from === '' || $live === $from ) return false;
        $flags = $site['flags'];
        if ( empty( $flags[ 'copy_' . md5( $from ) ] ) ) {
            $flags[ 'copy_' . md5( $from ) ] = 1;
            DFY_LH_Store::update( $site['id'], [ 'flags' => $flags ] );
            DFY_LH_Store::log( $site['id'], "Ignored a check-in from {$from}: it looks like a copy of {$live}. If the site moved domain, create a new connection code and reconnect it." );
        }
        return true;
    }

    private static function urls( $u ): array {
        $u = (array) $u;
        $o = [];
        foreach ( [ 'home_url', 'site_url', 'network_url' ] as $k ) $o[ $k ] = esc_url_raw( (string) ( $u[ $k ] ?? '' ) );
        return $o;
    }

    /** Vendor details the hub needs to remove licenses without the site. */
    private static function meta( $in, array $old ): array {
        $allowed = [ 'gf_site_key', 'gf_site_secret', 'automator_url', 'automator_item_id', 'elementor_version', 'learndash_version', 'site_lang', 'awp_server', 'awp_item_id', 'awp_item_name' ];
        foreach ( (array) $in as $k => $v ) {
            if ( in_array( $k, $allowed, true ) && is_scalar( $v ) && (string) $v !== '' ) $old[ $k ] = sanitize_text_field( (string) $v );
        }
        return $old;
    }
}
