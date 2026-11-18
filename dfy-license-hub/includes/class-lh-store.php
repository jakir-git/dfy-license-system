<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Storage: one row per DFY client site, plus settings and license keys in options.
 * Secrets (license keys, site secrets, GF site keys) are encrypted.
 */
class DFY_LH_Store {

    const DEFAULTS = [
        'term_days'       => 365,
        'warn_days'       => '30,7',
        'notify_email'    => '',
        'grace_days'      => 2,             // after expiry, wait this long for the site itself before removing remotely
        'silent_alert'    => 3,             // days without a check-in before emailing
        'silent_remove'   => 7,             // days without a check-in before removing remotely (0 = never)
        'test_mode'       => 1,             // log instead of removing (on by default until you are happy)
        'contact_url'     => '',            // shown to customers when their DFY licenses end
        'asana_token'     => '',            // optional, encrypted: fills customer name/email from the Asana link
    ];

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'dfy_lh_sites';
    }

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        $c = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            label varchar(191) NOT NULL DEFAULT '',
            site_url varchar(255) NOT NULL DEFAULT '',
            asana_project varchar(32) NOT NULL DEFAULT '',
            launch_task varchar(32) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            token_enc text NULL,
            secret_enc text NULL,
            urls text NULL,
            meta_enc text NULL,
            plugins text NULL,
            start_at datetime NULL,
            start_source varchar(20) NOT NULL DEFAULT '',
            expires_at datetime NULL,
            last_seen datetime NULL,
            command varchar(20) NOT NULL DEFAULT '',
            flags text NULL,
            log longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) {$c};" );
        update_option( 'dfy_lh_db_version', DFY_LH_VERSION );
    }

    /* ── settings ─────────────────────────────────────────── */

    /** 1.1.0: sites connected before then start from their connection time. */
    public static function upgrade_110(): void {
        foreach ( self::all() as $site ) DFY_LH_Engine::heal_start( $site );
    }

    /** The site with this domain (www and trailing slash ignored), newest first. */
    public static function find_by_host( string $url ): ?array {
        $h = DFY_LH_Rest::host( $url );
        if ( $h === '' ) return null;
        foreach ( self::all() as $s ) if ( DFY_LH_Rest::host( $s['site_url'] ) === $h ) return $s;
        return null;
    }

    /** Start a new license period on a closed site entry. */
    public static function reopen( int $id, string $label, string $url ): void {
        self::update( $id, [
            'label' => $label, 'site_url' => $url, 'status' => 'pending', 'secret_enc' => '', 'meta_enc' => '',
            'plugins' => [], 'urls' => [], 'start_at' => null, 'start_source' => '', 'expires_at' => null, 'command' => '',
            'flags' => array_intersect_key( (array) ( self::get( $id )['flags'] ?? [] ), [ 'asana_url' => 1 ] ),
        ] );
    }

    public static function asana_token(): string { return DFY_Crypto::decrypt( (string) self::setting( 'asana_token' ) ); }

    public static function settings(): array {
        $s = wp_parse_args( (array) get_option( 'dfy_lh_settings', [] ), self::DEFAULTS );
        if ( $s['notify_email'] === '' ) $s['notify_email'] = (string) get_option( 'admin_email' );
        return $s;
    }

    public static function setting( string $k ) { return self::settings()[ $k ] ?? null; }

    public static function save_settings( array $in ): void {
        $s = wp_parse_args( (array) get_option( 'dfy_lh_settings', [] ), self::DEFAULTS );
        foreach ( $in as $k => $v ) if ( array_key_exists( $k, self::DEFAULTS ) ) $s[ $k ] = $v;
        update_option( 'dfy_lh_settings', $s, false );
    }


    /* ── licenses ─────────────────────────────────────────── */

    /** @return array<string, array{key:string, email:string}> decrypted */
    public static function licenses(): array {
        $out = [];
        foreach ( (array) get_option( 'dfy_lh_licenses', [] ) as $slug => $row ) {
            $out[ $slug ] = [ 'key' => DFY_Crypto::decrypt( (string) ( $row['key'] ?? '' ) ), 'email' => (string) ( $row['email'] ?? '' ) ];
        }
        return $out;
    }

    public static function save_license( string $slug, ?string $key, string $email ): void {
        $all = (array) get_option( 'dfy_lh_licenses', [] );
        $row = $all[ $slug ] ?? [ 'key' => '', 'email' => '' ];
        if ( $key !== null ) $row['key'] = DFY_Crypto::encrypt( trim( $key ) );
        $row['email'] = sanitize_email( $email );
        $all[ $slug ] = $row;
        update_option( 'dfy_lh_licenses', $all, false );
    }

    /* ── sites ─────────────────────────────────────────────── */

    public static function get( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
        return $row ? self::hydrate( $row ) : null;
    }

    public static function all( string $status = '' ): array {
        global $wpdb;
        $sql  = 'SELECT * FROM ' . self::table();
        $rows = $status === '' ? $wpdb->get_results( $sql . ' ORDER BY id DESC', ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare( $sql . ' WHERE status = %s ORDER BY id DESC', $status ), ARRAY_A );
        return array_map( [ __CLASS__, 'hydrate' ], (array) $rows );
    }

    private static function hydrate( array $r ): array {
        $r['id']      = (int) $r['id'];
        $r['urls']    = json_decode( (string) $r['urls'], true ) ?: [];
        $r['plugins'] = json_decode( (string) $r['plugins'], true ) ?: [];
        $r['flags']   = json_decode( (string) $r['flags'], true ) ?: [];
        $r['log']     = json_decode( (string) $r['log'], true ) ?: [];
        return $r;
    }

    public static function create( array $d ): int {
        global $wpdb;
        $wpdb->insert( self::table(), [
            'label'         => $d['label'],
            'site_url'      => $d['site_url'],
            'asana_project' => $d['asana_project'],
            'status'        => $d['status'] ?? 'pending',
            'created_at'    => current_time( 'mysql', true ),
            'urls'          => '{}', 'plugins' => '{}', 'flags' => '{}', 'log' => '[]',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** Update columns; arrays are JSON-encoded. */
    public static function update( int $id, array $cols ): void {
        global $wpdb;
        foreach ( [ 'urls', 'plugins', 'flags', 'log' ] as $j ) {
            if ( isset( $cols[ $j ] ) && is_array( $cols[ $j ] ) ) $cols[ $j ] = wp_json_encode( $cols[ $j ] );
        }
        $wpdb->update( self::table(), $cols, [ 'id' => $id ] );
    }

    public static function delete( int $id ): void {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'id' => $id ] );
    }

    public static function log( int $id, string $msg ): void {
        $s = self::get( $id );
        if ( ! $s ) return;
        $log   = $s['log'];
        $log[] = [ 't' => current_time( 'mysql', true ), 'm' => $msg ];
        self::update( $id, [ 'log' => array_slice( $log, -150 ) ] );
    }

    public static function meta( array $site ): array { return DFY_Crypto::dec_json( (string) $site['meta_enc'] ); }
}
