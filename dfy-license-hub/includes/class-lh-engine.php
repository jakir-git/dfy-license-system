<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The rules: when the clock starts, when sites are warned, when licenses go.
 */
class DFY_LH_Engine {

    const DAY = 86400;

    /* ── clock ─────────────────────────────────────────────── */

    public static function set_start( int $id, string $start_utc, string $source ): void {
        $days    = max( 1, (int) DFY_LH_Store::setting( 'term_days' ) );
        $expires = gmdate( 'Y-m-d H:i:s', strtotime( $start_utc . ' UTC' ) + $days * self::DAY );
        $site    = DFY_LH_Store::get( $id );
        $flags   = $site ? $site['flags'] : [];
        foreach ( array_keys( $flags ) as $f ) if ( strpos( $f, 'warned_' ) === 0 || in_array( $f, [ 'expired_noted', 'manual_removed' ], true ) ) unset( $flags[ $f ] );
        DFY_LH_Store::update( $id, [ 'start_at' => $start_utc, 'start_source' => $source, 'expires_at' => $expires, 'flags' => $flags ] );
        DFY_LH_Store::log( $id, "Clock started ({$source}) on " . self::fmt( $start_utc ) . '; licenses expire on ' . self::fmt( $expires ) . '.' );
    }

    /** A connected site always has a start date: the moment it connected (from its log), if it was never set. */
    public static function heal_start( array $site ): void {
        if ( $site['start_at'] || $site['status'] !== 'active' ) return;
        $when = null;
        foreach ( $site['log'] as $l ) if ( strpos( (string) $l['m'], 'Connected from' ) === 0 ) { $when = (string) $l['t']; break; }
        self::set_start( (int) $site['id'], $when ?: current_time( 'mysql', true ), 'connected' );
    }

    public static function is_expired( array $site, ?int $now = null ): bool {
        return ! empty( $site['expires_at'] ) && ( $now ?? time() ) >= strtotime( $site['expires_at'] . ' UTC' );
    }

    /** The command a site receives on its next check-in. */
    public static function command_for( array $site ): string {
        if ( $site['status'] === 'removed' ) return 'remove';
        if ( $site['command'] === 'remove' ) return 'remove';
        if ( self::is_expired( $site ) && ! DFY_LH_Store::setting( 'test_mode' ) ) return 'remove';
        return '';
    }

    /* ── daily run ─────────────────────────────────────────── */

    public static function daily(): void {
        $ended = [];
        foreach ( DFY_LH_Store::all() as $site ) {
            if ( $site['status'] === 'removed' ) continue;
            if ( $site['status'] === 'reference' ) { if ( self::check_reference( $site ) ) $ended[] = $site; continue; }
            self::check_site( $site );
        }
        if ( $ended ) {
            // One summary email for all reference sites whose year ended (not one email each).
            $lines = array_map( function ( $s ) { return '- ' . $s['label'] . ' (ended ' . self::fmt( $s['expires_at'] ) . ')' . ( ! empty( $s['flags']['asana_url'] ) ? ' ' . $s['flags']['asana_url'] : '' ); }, $ended );
            self::notify( count( $ended ) . ' reference site(s): DFY license year ended',
                "These completed DFY sites do not run DFY License Manager, so please remove them from the vendor licenses by hand:\n\n" . implode( "\n", $lines ) );
        }
        update_option( 'dfy_lh_last_run', time(), false );
    }

    public static function check_site( array $site, ?int $now = null ): void {
        $now = $now ?? time();
        if ( ! $site['start_at'] && $site['status'] === 'active' ) { self::heal_start( $site ); $site = DFY_LH_Store::get( $site['id'] ); }
        $s   = DFY_LH_Store::settings();

        $flags = $site['flags'];

        // Expiry warnings to the DFY team.
        if ( $site['expires_at'] && ! self::is_expired( $site, $now ) ) {
            $left  = (int) ceil( ( strtotime( $site['expires_at'] . ' UTC' ) - $now ) / self::DAY );
            $warns = array_filter( array_map( 'intval', explode( ',', (string) $s['warn_days'] ) ) );
            rsort( $warns );
            foreach ( $warns as $w ) {
                if ( $left <= $w && empty( $flags[ "warned_{$w}" ] ) ) {
                    foreach ( $warns as $w2 ) if ( $w2 >= $w ) $flags[ "warned_{$w2}" ] = 1;
                    self::notify( "DFY licenses for {$site['label']} expire in {$left} days",
                        "The premium licenses on {$site['site_url']} (" . self::plugin_names( $site ) . ') will be removed on ' . self::fmt( $site['expires_at'] ) . '.' );
                    DFY_LH_Store::log( $site['id'], "Sent the {$w}-day expiry warning." );
                    break;
                }
            }
        }

        // Expired: the site removes its own licenses at its next check-in; after the grace period the hub does it.
        if ( self::is_expired( $site, $now ) ) {
            if ( empty( $flags['expired_noted'] ) ) {
                $flags['expired_noted'] = 1;
                DFY_LH_Store::log( $site['id'], $s['test_mode'] ? 'Expired (test mode: the site will not be told to remove licenses).' : 'Expired. The site will remove its licenses at its next check-in.' );
            }
            DFY_LH_Store::update( $site['id'], [ 'flags' => $flags ] );
            $grace_end = strtotime( $site['expires_at'] . ' UTC' ) + (int) $s['grace_days'] * self::DAY;
            if ( $now >= $grace_end || $site['status'] === 'pending' ) self::remote_remove( DFY_LH_Store::get( $site['id'] ), 'the license period ended and the site did not remove them itself' );
            return;
        }

        // Silent site (plugin deleted outside WordPress, site down, moved…).
        if ( $site['status'] === 'active' && $site['last_seen'] ) {
            $silent = ( $now - strtotime( $site['last_seen'] . ' UTC' ) ) / self::DAY;
            if ( $silent >= (int) $s['silent_alert'] && empty( $flags['silent_alerted'] ) ) {
                $flags['silent_alerted'] = 1;
                self::notify( "DFY site {$site['label']} stopped checking in",
                    "{$site['site_url']} has not checked in since " . self::fmt( $site['last_seen'] ) . '. The DFY License Manager plugin may have been deleted, or the site is down.' .
                    ( (int) $s['silent_remove'] > 0 ? " If it stays silent for {$s['silent_remove']} days, its licenses will be removed from the vendors." : '' ) );
                DFY_LH_Store::log( $site['id'], 'Stopped checking in; alert sent.' );
            }
            DFY_LH_Store::update( $site['id'], [ 'flags' => $flags ] );
            if ( (int) $s['silent_remove'] > 0 && $silent >= (int) $s['silent_remove'] ) {
                self::remote_remove( DFY_LH_Store::get( $site['id'] ), "the site has not checked in for {$s['silent_remove']} days" );
            }
        }
    }

    /* ── removal ───────────────────────────────────────────── */

    /** Remove the site from every vendor license directly (backup path). */
    public static function remote_remove( array $site, string $reason, bool $manual = false ): array {
        $todo = array_keys( array_filter( $site['plugins'], function ( $p ) { return ! in_array( $p['state'] ?? '', [ 'removed', 'not_installed', 'unlicensed' ], true ); } ) );
        if ( ! $todo ) {
            if ( $site['status'] !== 'removed' && $site['plugins'] ) self::finish( $site, $reason );
            elseif ( ! $site['plugins'] && empty( $site['flags']['never_connected'] ) ) {
                $site['flags']['never_connected'] = 1;
                DFY_LH_Store::update( $site['id'], [ 'flags' => $site['flags'] ] );
                DFY_LH_Store::log( $site['id'], 'Nothing to remove: the site never connected, so DFY License Manager activated no licenses there.' );
            }
            return [];
        }
        if ( DFY_LH_Store::setting( 'test_mode' ) && ! $manual ) {
            if ( empty( $site['flags']['test_logged'] ) ) {
                $site['flags']['test_logged'] = 1;
                DFY_LH_Store::update( $site['id'], [ 'flags' => $site['flags'] ] );
                DFY_LH_Store::log( $site['id'], 'Test mode: would now remove ' . self::names( $todo ) . " because {$reason}." );
            }
            return [];
        }

        $lic     = DFY_LH_Store::licenses();
        $meta    = DFY_LH_Store::meta( $site );
        $results = [];
        foreach ( $todo as $slug ) {
            if ( $slug === 'gravityforms' && ( $site['plugins'][ $slug ]['state'] ?? '' ) === 'replaced' ) {
                // Gravity Forms links a site to one license at a time; the customer's key replaced ours.
                $results[ $slug ] = [ 'ok' => true, 'msg' => 'The customer linked their own Gravity Forms license, which released ours.' ];
                continue;
            }
            // Prefer the key the site itself reported (licenses already on the site when it connected).
            $use = ! empty( $meta['keys'][ $slug ] ) ? [ 'key' => (string) $meta['keys'][ $slug ], 'email' => (string) ( $lic[ $slug ]['email'] ?? '' ) ] : ( $lic[ $slug ] ?? [] );
            $results[ $slug ] = DFY_License_Remote::remove( $slug, $use, $site['urls'], $meta );
        }
        self::apply_results( $site, $results, "hub removed licenses because {$reason}" );
        return $results;
    }

    /** The site removed its own licenses and reported back. */
    public static function record_client_removal( array $site, string $reason, array $results ): void {
        $clean = [];
        foreach ( $results as $slug => $r ) {
            if ( isset( DFY_License_Remote::VENDORS[ $slug ] ) ) $clean[ $slug ] = [ 'ok' => ! empty( $r['ok'] ), 'replaced' => ! empty( $r['replaced'] ), 'msg' => sanitize_text_field( (string) ( $r['msg'] ?? '' ) ) ];
        }
        $why = [
            'expired'           => 'the license period ended',
            'plugin_deactivated'=> 'the customer deactivated DFY License Manager',
            'plugin_uninstalled'=> 'the customer deleted DFY License Manager',
            'command'           => 'the hub asked it to',
        ][ $reason ] ?? sanitize_text_field( $reason );
        self::apply_results( $site, $clean, "site removed its licenses because {$why}", false );

        // Anything the site could not remove, try from here.
        $site = DFY_LH_Store::get( $site['id'] );
        if ( $site && $site['status'] !== 'removed' ) self::remote_remove( $site, $why, true );
    }

    private static function apply_results( array $site, array $results, string $context, bool $notify = true ): void {
        $plugins = $site['plugins'];
        $lines   = [];
        foreach ( $results as $slug => $r ) {
            $state = $r['ok'] ? 'removed' : ( empty( $r['replaced'] ) ? 'remove_failed' : 'replaced' );
            $plugins[ $slug ] = [ 'state' => $state, 'msg' => $r['msg'], 'at' => current_time( 'mysql', true ) ];
            $lines[] = DFY_License_Remote::label( $slug ) . ': ' . [ 'removed' => 'removed', 'replaced' => 'customer switched to their own key', 'remove_failed' => 'FAILED' ][ $state ] . ' - ' . $r['msg'];
        }
        DFY_LH_Store::update( $site['id'], [ 'plugins' => $plugins ] );
        DFY_LH_Store::log( $site['id'], ucfirst( $context ) . ".\n" . implode( "\n", $lines ) );
        $site = DFY_LH_Store::get( $site['id'] );

        $failed = array_filter( $plugins, function ( $p ) { return ( $p['state'] ?? '' ) === 'remove_failed'; } );
        if ( $failed && $notify ) {
            self::notify( "Action needed: DFY licenses for {$site['label']}",
                "Some licenses on {$site['site_url']} could not be removed automatically ({$context}). Please remove these in the vendor dashboards:\n\n" . implode( "\n", $lines ) );
        }
        $left = array_filter( $plugins, function ( $p ) { return ! in_array( $p['state'] ?? '', [ 'removed', 'not_installed', 'unlicensed' ], true ); } );
        if ( ! $left ) self::finish( $site, $context, $lines );
    }

    private static function finish( array $site, string $context, array $lines = [] ): void {
        DFY_LH_Store::update( $site['id'], [ 'status' => 'removed', 'command' => '' ] );
        DFY_LH_Store::log( $site['id'], 'All licenses deactivated. DFY License Manager stays on the site; use "Activate licenses" to turn them on again.' );
        $text = "DFY License Hub: all premium licenses on {$site['site_url']} were removed ({$context})." . ( $lines ? "\n" . implode( "\n", $lines ) : '' );
        self::notify( "DFY licenses removed: {$site['label']}", $text );
    }

    /** Turn DFY licenses on again on a site whose licenses were deactivated. */
    public static function reactivate( int $id, string $who ): string {
        $site    = DFY_LH_Store::get( $id );
        $plugins = $site['plugins'];
        foreach ( $plugins as $slug => $p ) {
            if ( in_array( $p['state'] ?? '', [ 'removed', 'remove_failed' ], true ) ) $plugins[ $slug ] = [ 'state' => '', 'msg' => 'Waiting for the site to activate it again.', 'at' => current_time( 'mysql', true ) ];
        }
        $flags = $site['flags'];
        foreach ( array_keys( $flags ) as $f ) if ( in_array( $f, [ 'expired_noted', 'test_logged', 'silent_alerted' ], true ) || strpos( $f, 'resent_' ) === 0 ) unset( $flags[ $f ] );
        DFY_LH_Store::update( $id, [ 'status' => 'active', 'command' => '', 'plugins' => $plugins, 'flags' => $flags ] );
        DFY_LH_Store::log( $id, "Licenses activated again by {$who}." );
        if ( self::is_expired( $site ) ) {
            self::set_start( $id, current_time( 'mysql', true ), 'manual' );
            return 'licenses activated again; a new 1-year period starts today.';
        }
        return 'licenses activated again.';
    }

    /** Completed sites kept for reference (no DFY License Manager): remind the team when their year ends. */
    /** @return bool true when this reference site's year just ended (reported once) */
    public static function check_reference( array $site ): bool {
        if ( ! $site['expires_at'] || ! self::is_expired( $site ) || ! empty( $site['flags']['expired_noted'] ) ) return false;
        $site['flags']['expired_noted'] = 1;
        DFY_LH_Store::update( $site['id'], [ 'flags' => $site['flags'] ] );
        DFY_LH_Store::log( $site['id'], 'The 1-year period ended. This is a reference entry, so remove the DFY licenses in the vendor accounts by hand.' );
        return true;
    }

    /* ── helpers ───────────────────────────────────────────── */

    public static function notify( string $subject, string $body ): void {
        $to = (string) DFY_LH_Store::setting( 'notify_email' );
        if ( $to !== '' ) wp_mail( $to, '[DFY Licenses] ' . $subject, $body . "\n\n" . admin_url( 'admin.php?page=dfy-license-hub' ) );
    }

    public static function plugin_names( array $site ): string {
        $slugs = array_keys( array_filter( $site['plugins'], function ( $p ) { return ! in_array( $p['state'] ?? '', [ 'not_installed', 'unlicensed' ], true ); } ) );
        return $slugs ? self::names( $slugs ) : 'all DFY-provided licenses';
    }

    public static function names( array $slugs ): string {
        return implode( ', ', array_map( [ 'DFY_License_Remote', 'label' ], $slugs ) );
    }

    public static function fmt( ?string $utc ): string {
        if ( ! $utc ) return '-';
        return function_exists( 'wp_date' ) ? wp_date( 'M j, Y', strtotime( $utc . ' UTC' ) ) : gmdate( 'M j, Y', strtotime( $utc . ' UTC' ) );
    }
}
