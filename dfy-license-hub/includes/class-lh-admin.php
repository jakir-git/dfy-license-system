<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DFY_LH_Admin {

    const PAGE = 'dfy-license-hub';
    const CAP  = 'manage_options';

    public static function init(): void {
        add_action( 'admin_menu', function () {
            add_menu_page( 'DFY Licenses', 'DFY Licenses', self::CAP, self::PAGE, [ __CLASS__, 'render' ], 'dashicons-shield', 81 );
        } );
        add_action( 'admin_enqueue_scripts', function ( $hook ) {
            if ( $hook !== 'toplevel_page_' . self::PAGE ) return;
            wp_enqueue_style( 'dfy-lh-admin', plugins_url( 'assets/admin.css', DFY_LH_FILE ), [], DFY_LH_VERSION );
        } );
        add_filter( 'admin_body_class', function ( $c ) { return ( isset( $_GET['page'] ) && $_GET['page'] === self::PAGE ) ? $c . ' dfy-lh-page' : $c; } );
        foreach ( [ 'add_site', 'site_action', 'save_licenses', 'save_settings', 'run_now', 'import', 'export', 'asana_fill', 'cron_test' ] as $a ) {
            add_action( "admin_post_dfy_lh_{$a}", [ __CLASS__, $a ] );
        }
    }

    private static function url( array $args = [] ): string {
        return add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'admin.php' ) );
    }

    private static function guard( string $action ): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( 'Not allowed.' );
        check_admin_referer( $action );
    }

    private static function back( array $args, string $msg, bool $error = false ): void {
        wp_safe_redirect( self::url( $args + [ ( $error ? 'dfy_err' : 'dfy_msg' ) => rawurlencode( $msg ) ] ) );
        exit;
    }

    /* ── actions ───────────────────────────────────────────── */

    public static function add_site(): void {
        self::guard( 'dfy_lh_add_site' );
        $label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
        $url   = esc_url_raw( trim( wp_unslash( $_POST['site_url'] ?? '' ) ) );
        if ( $url === '' || ! wp_parse_url( $url, PHP_URL_HOST ) ) self::back( [ 'add' => 1 ], 'Enter the client site URL.', true );

        // One entry per site: same domain (with or without www / trailing slash) is the same site.
        $same = DFY_LH_Store::find_by_host( $url );
        if ( $same && $same['status'] !== 'removed' ) {
            self::back( [ 'site' => $same['id'] ], 'This site is already in the list as "' . $same['label'] . '". Nothing was added.', true );
        }
        if ( $same ) {
            // A closed site added again: reopen the same entry (keeps its history) and connect it.
            DFY_LH_Store::reopen( (int) $same['id'], $label !== '' ? $label : $same['label'], $url );
            self::save_asana( (int) $same['id'], (string) wp_unslash( $_POST['asana'] ?? '' ) );
            if ( ! empty( $_POST['older'] ) ) { $f = DFY_LH_Store::get( (int) $same['id'] )['flags']; $f['detect_start'] = 1; DFY_LH_Store::update( (int) $same['id'], [ 'flags' => $f ] ); }
            DFY_LH_Rest::new_token( (int) $same['id'] );
            DFY_LH_Store::log( (int) $same['id'], 'Site added again (the previous license period was closed). New 1-year period starts when it connects.' );
            self::connect_now( (int) $same['id'], 'Site re-added.' );
        }
        $id = DFY_LH_Store::create( [ 'label' => $label !== '' ? $label : (string) wp_parse_url( $url, PHP_URL_HOST ), 'site_url' => $url, 'asana_project' => '' ] );
        self::save_asana( $id, (string) wp_unslash( $_POST['asana'] ?? '' ) );
        if ( ! empty( $_POST['older'] ) ) { $f = DFY_LH_Store::get( $id )['flags']; $f['detect_start'] = 1; DFY_LH_Store::update( $id, [ 'flags' => $f ] ); }
        DFY_LH_Rest::new_token( $id );
        DFY_LH_Store::log( $id, 'Site added.' );
        self::connect_now( $id, 'Site added.' );
    }

    /**
     * Add completed DFY sites for reference (no DFY License Manager on them).
     * One per line: Name | completion date (YYYY-MM-DD) | Asana/Pipefy link | client | site URL
     */
    public static function import(): void {
        self::guard( 'dfy_lh_import' );
        $r = self::import_lines( (string) wp_unslash( $_POST['lines'] ?? '' ) );
        self::back( [ 'status' => 'reference' ], "Reference sites: {$r['added']} added, {$r['skipped']} already in the list" . ( $r['bad'] ? ', ' . count( $r['bad'] ) . ' lines not understood (' . implode( '; ', array_slice( $r['bad'], 0, 5 ) ) . ')' : '' ) . '.', (bool) $r['bad'] );
    }

    /** @return array{added:int,skipped:int,bad:string[]} */
    public static function import_lines( string $text ): array {
        $out = [ 'added' => 0, 'skipped' => 0, 'bad' => [] ];
        $all = DFY_LH_Store::all();
        foreach ( preg_split( '/\r?\n/', $text ) as $line ) {
            if ( trim( $line ) === '' ) continue;
            $p    = array_map( 'trim', explode( '|', $line ) );
            $name = sanitize_text_field( $p[0] ?? '' );
            $date = (string) ( $p[1] ?? '' );
            $link = esc_url_raw( (string) ( $p[2] ?? '' ) );
            $cli  = sanitize_text_field( $p[3] ?? '' );
            $url  = esc_url_raw( (string) ( $p[4] ?? '' ) );
            if ( $name === '' || ( $date !== '' && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! strtotime( $date ) ) ) ) { $out['bad'][] = mb_substr( $line, 0, 40 ); continue; }
            $dup = false;
            foreach ( $all as $s ) {
                if ( ( $url !== '' && DFY_LH_Rest::host( $url ) === DFY_LH_Rest::host( $s['site_url'] ) )
                    || ( $link !== '' && $link === ( $s['flags']['asana_url'] ?? '' ) )
                    || strcasecmp( $name, $s['label'] ) === 0 ) { $dup = true; break; }
            }
            if ( $dup ) { $out['skipped']++; continue; }
            $id = DFY_LH_Store::create( [ 'label' => $name, 'site_url' => $url, 'asana_project' => '', 'status' => 'reference' ] );
            DFY_LH_Store::update( $id, [ 'flags' => array_filter( [ 'asana_url' => $link, 'client' => $cli ] ) ] );
            DFY_LH_Store::log( $id, 'Added for reference (completed DFY project; DFY License Manager not installed).' );
            if ( $date !== '' ) DFY_LH_Engine::set_start( $id, $date . ' 00:00:00', 'completed' );
            $all[] = DFY_LH_Store::get( $id );
            $out['added']++;
        }
        return $out;
    }

    /** Ping the client site so DFY License Manager connects and activates the licenses right away. */
    private static function connect_now( int $id, string $prefix ): void {
        DFY_LH_Rest::ping( $id );
        $s = DFY_LH_Store::get( $id );
        if ( $s['status'] === 'active' ) {
            $ok = array_keys( array_filter( $s['plugins'], function ( $p ) { return ( $p['state'] ?? '' ) === 'active'; } ) );
            self::back( [ 'site' => $id ], "{$prefix} The site connected. Licenses active: " . ( $ok ? DFY_LH_Engine::names( $ok ) : 'none yet, see below' ) . '.' );
        }
        self::back( [ 'site' => $id ], "{$prefix} The site has not connected yet: install and activate DFY License Manager there and it connects by itself (or paste the connection code below)." );
    }

    /** Asana link + customer name/email; empty name/email are filled from Asana when a token is set. */
    private static function save_asana( int $id, string $url ): void {
        $s = DFY_LH_Store::get( $id );
        $flags = $s['flags'];
        $flags['asana_url'] = esc_url_raw( trim( $url ) );
        if ( isset( $_POST['client'] ) ) $flags['client'] = sanitize_text_field( wp_unslash( $_POST['client'] ) );
        if ( isset( $_POST['email'] ) )  $flags['email']  = sanitize_email( wp_unslash( $_POST['email'] ) );
        DFY_LH_Store::update( $id, [ 'flags' => $flags ] );
        if ( ( empty( $flags['client'] ) || empty( $flags['email'] ) ) && DFY_LH_Store::asana_token() !== '' && DFY_LH_Asana::project_gid( $flags['asana_url'] ) !== '' ) self::fill_from_asana( $id );
    }

    /** @return string message */
    public static function fill_from_asana( int $id ): string {
        $s = DFY_LH_Store::get( $id );
        $c = DFY_LH_Asana::customer( (string) ( $s['flags']['asana_url'] ?? '' ) );
        if ( is_wp_error( $c ) ) { DFY_LH_Store::log( $id, 'Could not read the customer from Asana: ' . $c->get_error_message() ); return $c->get_error_message(); }
        $flags = $s['flags'];
        $set   = [];
        if ( empty( $flags['client'] ) && $c['name'] !== '' )  { $flags['client'] = $c['name']; $set[] = 'name'; }
        if ( empty( $flags['email'] ) && $c['email'] !== '' )  { $flags['email'] = $c['email']; $set[] = 'email'; }
        $flags['asana_checked'] = time();
        $upd = [ 'flags' => $flags ];
        if ( trim( (string) $s['label'] ) === '' || $s['label'] === (string) wp_parse_url( $s['site_url'], PHP_URL_HOST ) ) {
            $proj = strpos( $c['project'], ' - ' ) !== false ? trim( substr( $c['project'], 0, strrpos( $c['project'], ' - ' ) ) ) : $c['project'];
            if ( $proj !== '' ) $upd['label'] = $proj;
        }
        DFY_LH_Store::update( $id, $upd );
        DFY_LH_Store::log( $id, $set ? 'Customer ' . implode( ' and ', $set ) . ' filled from Asana.' : 'Asana checked: no new customer details found.' );
        return $set ? 'filled ' . implode( ' and ', $set ) : 'nothing new found';
    }

    public static function asana_fill(): void {
        self::guard( 'dfy_lh_asana_fill' );
        $done = 0; $found = 0; $left = 0;
        foreach ( DFY_LH_Store::all() as $x ) {
            if ( DFY_LH_Asana::project_gid( (string) ( $x['flags']['asana_url'] ?? '' ) ) === '' || ! empty( $x['flags']['email'] ) ) continue;
            if ( $done >= 15 || ! empty( $x['flags']['asana_checked'] ) && time() - (int) $x['flags']['asana_checked'] < HOUR_IN_SECONDS ) { $left++; continue; }
            $r = self::fill_from_asana( (int) $x['id'] ); $done++;
            if ( strpos( $r, 'filled' ) === 0 ) $found++;
        }
        self::back( [], "Asana: checked {$done} sites, filled details for {$found}." . ( $left ? " {$left} left — click again to continue (15 per click)." : '' ) );
    }

    /** Excel-ready CSV of the sites matching the current list filters. */
    public static function export(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'dfy_lh_export' );
        [ $sites ] = self::query( wp_unslash( $_GET ) );
        $labels = [ 'active' => 'Active', 'pending' => 'Not connected yet', 'removed' => 'Licenses deactivated', 'reference' => 'Reference only' ];
        $rows = [ [ '#', 'Project / site', 'Customer name', 'Customer email', 'Site URL', 'Status', 'Start date', 'Licenses end', 'Days left', 'Needs action', 'Licenses', 'Asana / Pipefy link', 'Last check-in' ] ];
        $n = 0;
        foreach ( $sites as $s ) {
            $lic = [];
            foreach ( $s['plugins'] as $slug => $p ) if ( ( $p['state'] ?? '' ) !== 'not_installed' ) $lic[] = DFY_License_Remote::label( $slug ) . ' (' . str_replace( '_', ' ', $p['state'] ) . ')';
            $left = $s['expires_at'] ? (int) ceil( ( strtotime( $s['expires_at'] . ' UTC' ) - time() ) / DAY_IN_SECONDS ) : '';
            $rows[] = [ ++$n, $s['label'], $s['flags']['client'] ?? '', $s['flags']['email'] ?? '', $s['site_url'], $labels[ $s['status'] ] ?? $s['status'],
                $s['start_at'] ? substr( $s['start_at'], 0, 10 ) : '', $s['expires_at'] ? substr( $s['expires_at'], 0, 10 ) : '', $left, self::urgency( $s ),
                implode( ', ', $lic ), $s['flags']['asana_url'] ?? '', $s['last_seen'] ? substr( $s['last_seen'], 0, 16 ) : '' ];
        }
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="dfy-sites-' . gmdate( 'Y-m-d' ) . '.csv"' );
        echo self::csv( $rows );
        exit;
    }

    /** CSV with a UTF-8 BOM so Excel shows accents correctly; cells that look like formulas are neutralised. */
    public static function csv( array $rows ): string {
        $out = "\xEF\xBB\xBF";
        foreach ( $rows as $r ) {
            $cells = [];
            foreach ( $r as $v ) {
                $v = (string) $v;
                if ( $v !== '' && strpos( '=+-@', $v[0] ) !== false && ! is_numeric( $v ) ) $v = "'" . $v;
                $cells[] = '"' . str_replace( '"', '""', $v ) . '"';
            }
            $out .= implode( ',', $cells ) . "\r\n";
        }
        return $out;
    }

    public static function site_action(): void {
        self::guard( 'dfy_lh_site_action' );
        $id   = (int) ( $_POST['site'] ?? 0 );
        $site = DFY_LH_Store::get( $id );
        if ( ! $site ) self::back( [], 'Site not found.', true );
        $do = sanitize_key( $_POST['do'] ?? '' );
        $ret = ( $_POST['return'] ?? '' ) === 'list' ? [] : [ 'site' => $id ];

        switch ( $do ) {
            case 'connect_now':
                if ( $site['status'] !== 'pending' ) self::back( [ 'site' => $id ], 'This site is already connected.', true );
                self::connect_now( $id, '' );
            case 'set_start':
                $d = sanitize_text_field( wp_unslash( $_POST['start'] ?? '' ) );
                if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) || ! strtotime( $d ) ) self::back( $ret, 'Use the date picker.', true );
                if ( $site['status'] === 'removed' ) self::back( $ret, 'This site is closed.', true );
                DFY_LH_Engine::set_start( $id, $d . ' 00:00:00', ( $_POST['source'] ?? '' ) === 'detected' ? 'detected' : 'manual' );
                DFY_LH_Rest::ping( $id, 'checkin' ); // the site learns its new end date now
                self::back( $ret, $site['label'] . ': start date set to ' . $d . '; licenses expire ' . DFY_LH_Engine::fmt( DFY_LH_Store::get( $id )['expires_at'] ) . '.' );
            case 'edit':
                DFY_LH_Store::update( $id, [
                    'label'         => sanitize_text_field( wp_unslash( $_POST['label'] ?? $site['label'] ) ),
                ] + ( $site['status'] === 'reference' && isset( $_POST['site_url'] ) ? [ 'site_url' => esc_url_raw( trim( wp_unslash( $_POST['site_url'] ) ) ) ] : [] ) );
                self::save_asana( $id, (string) wp_unslash( $_POST['asana'] ?? '' ) );
                $f = DFY_LH_Store::get( $id )['flags'];
                if ( ! empty( $_POST['keep'] ) ) $f['keep_licenses'] = 1; else unset( $f['keep_licenses'] );
                DFY_LH_Store::update( $id, [ 'flags' => $f ] );
                self::back( [ 'site' => $id ], 'Saved.' );
            case 'new_code':
                if ( $site['status'] === 'removed' ) self::back( [ 'site' => $id ], 'This site is closed.', true );
                DFY_LH_Rest::new_token( $id );
                DFY_LH_Store::log( $id, 'New connection code created.' );
                self::back( [ 'site' => $id ], 'New connection code created. The old one no longer works.' );
            case 'remove_now':
                DFY_LH_Store::update( $id, [ 'command' => 'remove' ] );
                DFY_LH_Store::log( $id, 'Removal requested by ' . wp_get_current_user()->user_login . '.' );
                DFY_LH_Rest::ping( $id, 'checkin' ); // the site removes its licenses itself right away
                $r = DFY_LH_Engine::remote_remove( DFY_LH_Store::get( $id ), 'it was requested manually in the hub', true ); // anything left: from here
                $bad = array_filter( $r, function ( $x ) { return ! $x['ok']; } );
                $now = DFY_LH_Store::get( $id );
                self::back( $ret, $bad ? $site['label'] . ': some licenses could not be removed; see the site log.' : $site['label'] . ': ' . ( $now['status'] === 'removed' ? 'all DFY licenses deactivated.' : 'deactivation requested; the site finishes it at its next check-in.' ), (bool) $bad );
            case 'activate':
                if ( $site['status'] !== 'removed' ) self::back( $ret, 'Licenses are not deactivated on this site.', true );
                $msg = DFY_LH_Engine::reactivate( $id, wp_get_current_user()->user_login );
                if ( ! DFY_LH_Rest::ping( $id, 'checkin' ) ) {
                    // The site lost its connection (e.g. DFY License Manager was reinstalled): connect it again.
                    DFY_LH_Store::update( $id, [ 'status' => 'pending', 'secret_enc' => '' ] );
                    DFY_LH_Rest::new_token( $id );
                    DFY_LH_Store::log( $id, 'The site did not answer as connected; asking DFY License Manager to connect again.' );
                    DFY_LH_Rest::ping( $id );
                }
                $now = DFY_LH_Store::get( $id );
                $on  = array_keys( array_filter( $now['plugins'], function ( $p ) { return ( $p['state'] ?? '' ) === 'active'; } ) );
                self::back( $ret, $site['label'] . ': ' . $msg . ' ' . ( $on ? 'Active now: ' . DFY_LH_Engine::names( $on ) . '.' : 'The site activates them at its next check-in.' ) );
            case 'track':
                if ( $site['status'] !== 'reference' ) self::back( $ret, 'Only reference sites can be switched to tracking.', true );
                if ( ! wp_parse_url( $site['site_url'], PHP_URL_HOST ) ) self::back( [ 'site' => $id ], 'Add the client site URL under Details first.', true );
                $f = $site['flags']; $f['keep_licenses'] = 1; $f['detect_start'] = 1;
                DFY_LH_Store::update( $id, [ 'status' => 'pending', 'flags' => $f ] );
                DFY_LH_Rest::new_token( $id );
                DFY_LH_Store::log( $id, 'Switched from reference to tracking with DFY License Manager. The start date is kept; licenses are never removed automatically (older project).' );
                self::connect_now( $id, 'Tracking on.' );
            case 'handled':
                $f = $site['flags']; $f['manual_removed'] = time();
                DFY_LH_Store::update( $id, [ 'flags' => $f ] );
                DFY_LH_Store::log( $id, 'Licenses marked as removed by hand by ' . wp_get_current_user()->user_login . '.' );
                self::back( $ret, $site['label'] . ': marked as removed.' );
            case 'remind':
                $r = DFY_LH_Engine::customer_reminder( $site );
                self::back( $ret, $site['label'] . ': expiry reminder ' . $r['msg'] . '.', ! $r['ok'] );
            case 'checkin':
                if ( $site['status'] !== 'active' ) self::back( $ret, 'Only connected sites can check in.', true );
                DFY_LH_Rest::ping( $id, 'checkin' );
                $now = DFY_LH_Store::get( $id );
                $st  = [];
                foreach ( $now['plugins'] as $slug => $p ) if ( ( $p['state'] ?? '' ) !== 'not_installed' ) $st[] = DFY_License_Remote::label( $slug ) . ': ' . str_replace( '_', ' ', $p['state'] );
                self::back( $ret, $site['label'] . ' checked in. ' . ( $st ? implode( ', ', $st ) . '.' : '' ) );
            case 'delete':
                // Deleting a site always removes its DFY licenses first (also in test mode).
                if ( $site['status'] === 'active' ) {
                    DFY_LH_Store::update( $id, [ 'command' => 'remove' ] );
                    DFY_LH_Store::log( $id, 'Delete requested by ' . wp_get_current_user()->user_login . ': removing licenses first.' );
                    DFY_LH_Rest::ping( $id, 'checkin' );
                    DFY_LH_Engine::remote_remove( DFY_LH_Store::get( $id ), 'the site is being deleted from the hub', true );
                    if ( DFY_LH_Store::get( $id )['status'] !== 'removed' ) {
                        self::back( [ 'site' => $id ], $site['label'] . ': some licenses could not be removed, so the site was NOT deleted. See the log, remove those in the vendor account, then delete again.', true );
                    }
                }
                DFY_LH_Store::delete( $id );
                self::back( [], $site['label'] . ' deleted' . ( $site['status'] === 'active' ? '; all its DFY licenses were removed.' : '.' ) );
        }
        self::back( [ 'site' => $id ], 'Unknown action.', true );
    }

    /** Plugins with a key field on the Licenses tab (Uncanny Automator Pro is no longer part of DFY). */
    private static function license_fields(): array {
        return array_diff_key( DFY_License_Remote::VENDORS, [ 'uncanny-automator-pro' => 1 ] );
    }

    public static function save_licenses(): void {
        self::guard( 'dfy_lh_save_licenses' );
        foreach ( self::license_fields() as $slug => $label ) {
            $key   = trim( (string) wp_unslash( $_POST['key'][ $slug ] ?? '' ) );
            $clear = ! empty( $_POST['clear'][ $slug ] );
            DFY_LH_Store::save_license( $slug, $clear ? '' : ( $key === '' ? null : $key ), (string) wp_unslash( $_POST['email'][ $slug ] ?? '' ) );
        }
        self::back( [ 'tab' => 'licenses' ], 'Licenses saved.' );
    }

    public static function save_settings(): void {
        self::guard( 'dfy_lh_save_settings' );
        $in = [
            'term_days'     => max( 1, (int) ( $_POST['term_days'] ?? 365 ) ),
            'warn_days'     => preg_replace( '/[^0-9,]/', '', (string) ( $_POST['warn_days'] ?? '' ) ),
            'notify_email'  => sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ),
            'grace_days'    => max( 0, (int) ( $_POST['grace_days'] ?? 2 ) ),
            'silent_alert'  => max( 1, (int) ( $_POST['silent_alert'] ?? 3 ) ),
            'silent_remove' => max( 0, (int) ( $_POST['silent_remove'] ?? 7 ) ),
            'test_mode'     => empty( $_POST['test_mode'] ) ? 0 : 1,
            'contact_url'   => esc_url_raw( trim( wp_unslash( $_POST['contact_url'] ?? '' ) ) ),
            'customer_mail' => in_array( $_POST['customer_mail'] ?? '', [ 'off', 'test', 'live' ], true ) ? $_POST['customer_mail'] : 'test',
            'customer_test_to' => sanitize_email( wp_unslash( $_POST['customer_test_to'] ?? '' ) ),
        ];
        $tok = trim( (string) wp_unslash( $_POST['asana_token'] ?? '' ) );
        if ( $tok !== '' ) $in['asana_token'] = DFY_Crypto::encrypt( $tok );
        if ( ! empty( $_POST['clear_token'] ) ) $in['asana_token'] = '';
        DFY_LH_Store::save_settings( $in );
        self::back( [ 'tab' => 'settings' ], 'Settings saved.' );
    }

    public static function run_now(): void {
        self::guard( 'dfy_lh_run_now' );
        DFY_LH_Engine::daily();
        self::back( [], 'Daily check finished.' );
    }

    /* ── screens ───────────────────────────────────────────── */

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) return;
        $tab  = sanitize_key( $_GET['tab'] ?? 'sites' );
        $site = isset( $_GET['site'] ) ? DFY_LH_Store::get( (int) $_GET['site'] ) : null;
        $last = (int) get_option( 'dfy_lh_last_run' );
        echo '<div class="wrap dfy-lh">';
        echo '<header class="dfy-head"><div class="dfy-head__brand"><span class="dfy-head__icon dashicons dashicons-shield-alt" aria-hidden="true"></span><div><h1>DFY Licenses</h1><p>Premium plugin licenses for BuddyBoss Done-For-You sites</p></div></div><div class="dfy-head__actions">';
        echo '<span class="dfy-head__meta">Last daily check: ' . ( $last ? esc_html( wp_date( 'M j, H:i', $last ) ) : 'never' ) . '</span>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'dfy_lh_run_now' );
        echo '<input type="hidden" name="action" value="dfy_lh_run_now"><button class="button">Run daily check now</button></form>';
        echo '<a class="button button-primary" data-dfy-open="add" href="' . esc_url( self::url( [ 'tab' => 'add' ] ) ) . '">+ Add site</a></div></header><hr class="wp-header-end" style="display:none">';
        if ( ! empty( $_GET['dfy_msg'] ) ) echo '<div class="notice notice-success"><p>' . esc_html( wp_unslash( $_GET['dfy_msg'] ) ) . '</p></div>';
        if ( ! empty( $_GET['dfy_err'] ) ) echo '<div class="notice notice-error"><p>' . esc_html( wp_unslash( $_GET['dfy_err'] ) ) . '</p></div>';
        if ( DFY_LH_Store::setting( 'test_mode' ) ) echo '<div class="dfy-banner"><span class="dashicons dashicons-warning"></span><span><strong>Test mode is on.</strong> Expired sites are only logged; no licenses are removed automatically. Turn it off on the Settings tab when you are ready.</span></div>';

        echo '<nav class="dfy-tabs">';
        foreach ( [ 'sites' => 'Sites', 'licenses' => 'Licenses', 'settings' => 'Settings' ] as $k => $l ) {
            printf( '<a class="%s" href="%s">%s</a>', ( $tab === $k && ! $site ) || ( $site && $k === 'sites' ) ? 'is-active' : '', esc_url( self::url( [ 'tab' => $k ] ) ), esc_html( $l ) );
        }
        echo '</nav><div class="dfy-body">';
        if ( $site ) self::screen_site( $site );
        elseif ( $tab === 'add' ) self::screen_add();
        elseif ( $tab === 'licenses' ) self::screen_licenses();
        elseif ( $tab === 'settings' ) self::screen_settings();
        else self::screen_sites();
        echo '</div>';
        self::add_modal();
        echo '</div>';
    }

    /**
     * Where a site's 1-year period stands, for both connected and tracked-by-date sites:
     * running = licenses in use, soon = ends within 30 days, expired = ended and not removed yet,
     * done = licenses removed / deactivated, pending = not connected yet.
     */
    public static function phase( array $s ): string {
        if ( $s['status'] === 'removed' || ( $s['status'] === 'reference' && ! empty( $s['flags']['manual_removed'] ) ) ) return 'done';
        if ( $s['status'] === 'pending' ) return 'pending';
        if ( ! $s['expires_at'] ) return 'running';
        $left = strtotime( $s['expires_at'] . ' UTC' ) - time();
        if ( $left <= 0 ) return 'expired';
        return $left <= 30 * DAY_IN_SECONDS ? 'soon' : 'running';
    }

    private static function badge( array $s ): string {
        $pill = function ( string $kind, string $text, string $sub = '' ) { return '<span class="dfy-pill dfy-pill--' . $kind . '">' . esc_html( $text ) . '</span>' . ( $sub !== '' ? '<span class="dfy-sub">' . esc_html( $sub ) . '</span>' : '' ); };
        $ref  = $s['status'] === 'reference';
        $keep = DFY_LH_Engine::keeps( $s );
        switch ( self::phase( $s ) ) {
            case 'done':    return $pill( 'muted', $ref ? 'Licenses removed' : 'Licenses deactivated', $ref ? 'removed by hand' : '' );
            case 'pending': return $pill( 'warn', 'Not connected yet' );
            case 'expired': return $pill( 'bad', 'Expired', $ref ? 'remove the licenses by hand' : ( $keep ? 'older project · licenses kept' : 'removal pending' ) );
        }
        if ( ! $ref && $s['last_seen'] && ( time() - strtotime( $s['last_seen'] . ' UTC' ) ) > DAY_IN_SECONDS * (int) DFY_LH_Store::setting( 'silent_alert' ) ) return $pill( 'bad', 'Not checking in' );
        $sub = $ref ? 'no DFY License Manager' : ( $keep ? 'older project · licenses kept' : '' );
        if ( self::phase( $s ) === 'soon' ) {
            $d = (int) ceil( ( strtotime( $s['expires_at'] . ' UTC' ) - time() ) / DAY_IN_SECONDS );
            return $pill( 'warn', 'Expires soon', $d . ' day' . ( $d === 1 ? '' : 's' ) . ' left' . ( $ref ? ' · no DFY License Manager' : '' ) );
        }
        return $pill( 'ok', 'Active', $sub );
    }

    /** One plugin as a small chip; the state is shown only when it is not "active". */
    private static function chip( string $slug, string $state ): string {
        $kind = [ 'active' => 'ok', 'sent' => 'primary', 'removed' => 'muted', 'replaced' => 'muted', 'remove_failed' => 'bad' ][ $state ] ?? 'warn';
        return '<span class="dfy-chip dfy-chip--' . $kind . '" title="' . esc_attr( str_replace( '_', ' ', $state ) ) . '">' . esc_html( DFY_License_Remote::label( $slug ) ) . ( $state !== 'active' ? ' <em>' . esc_html( str_replace( '_', ' ', $state ) ) . '</em>' : '' ) . '</span>';
    }

    /** Sites matching the list filters (search, status, month/year), sorted. Used by the list and the export. */
    public static function query( array $g ): array {
        $all   = DFY_LH_Store::all();
        $q     = trim( sanitize_text_field( wp_unslash( $g['s'] ?? '' ) ) );
        $field = in_array( $g['by'] ?? '', [ 'start', 'expires', 'added' ], true ) ? $g['by'] : 'start';
        $month = max( 0, min( 12, (int) ( $g['m'] ?? 0 ) ) );
        $year  = (int) ( $g['y'] ?? 0 );
        $st    = in_array( $g['status'] ?? '', [ 'active', 'pending', 'removed', 'expiring', 'expired', 'reference', 'action', 'ended', 'done' ], true ) ? $g['status'] : '';
        $sort  = in_array( $g['orderby'] ?? '', [ 'label', 'start', 'expires', 'last_seen', 'added' ], true ) ? $g['orderby'] : 'start';
        $dir   = ( $g['order'] ?? 'desc' ) === 'asc' ? 'asc' : 'desc';
        $col   = [ 'start' => 'start_at', 'expires' => 'expires_at', 'added' => 'created_at', 'last_seen' => 'last_seen', 'label' => 'label' ];

        $years = [];
        foreach ( $all as $x ) foreach ( [ 'start_at', 'expires_at', 'created_at' ] as $k ) if ( ! empty( $x[ $k ] ) ) $years[ (int) substr( $x[ $k ], 0, 4 ) ] = 1;
        krsort( $years );

        $sites = array_values( array_filter( $all, function ( $x ) use ( $q, $field, $month, $year, $st, $col ) {
            if ( $q !== '' && stripos( $x['label'] . ' ' . $x['site_url'] . ' ' . ( $x['flags']['asana_url'] ?? '' ) . ' ' . ( $x['flags']['client'] ?? '' ) . ' ' . ( $x['flags']['email'] ?? '' ), $q ) === false ) return false;
            $ph = self::phase( $x );
            if ( $st === 'active' && ! ( $ph === 'running' && in_array( $x['status'], [ 'active', 'reference' ], true ) ) ) return false;
            if ( $st === 'expiring' && $ph !== 'soon' ) return false;
            if ( $st === 'expired' && $ph !== 'expired' ) return false;
            if ( $st === 'done' && $ph !== 'done' ) return false;
            if ( $st === 'action' && ! self::urgency( $x ) ) return false;
            if ( $st === 'ended' && ! ( $x['status'] === 'removed' || DFY_LH_Engine::is_expired( $x ) ) ) return false;
            if ( $st !== '' && ! in_array( $st, [ 'active', 'expiring', 'expired', 'action', 'ended', 'done' ], true ) && $x['status'] !== $st ) return false;
            if ( $month || $year ) {
                $d = (string) ( $x[ $col[ $field ] ] ?? '' );
                if ( $d === '' ) return false;
                if ( $year && (int) substr( $d, 0, 4 ) !== $year ) return false;
                if ( $month && (int) substr( $d, 5, 2 ) !== $month ) return false;
            }
            return true;
        } ) );
        usort( $sites, function ( $a, $b ) use ( $sort, $dir, $col ) {
            $k = $col[ $sort ];
            $va = (string) ( $a[ $k ] ?? '' ); $vb = (string) ( $b[ $k ] ?? '' );
            if ( $k === 'start_at' ) {
                // A just-added site that has not connected yet has no start date: treat it as the newest.
                $na = $va === '' && $a['status'] === 'pending'; $nb = $vb === '' && $b['status'] === 'pending';
                if ( $na !== $nb ) return $na === ( $dir === 'desc' ) ? -1 : 1;
            }
            if ( $va !== $vb && ( $va === '' || $vb === '' ) ) return $va === '' ? 1 : -1; // other missing dates last
            $c = $k === 'label' ? strcasecmp( $va, $vb ) : strcmp( $va, $vb );
            if ( $c === 0 ) return strcmp( (string) $b['created_at'], (string) $a['created_at'] ); // then newest added first
            return $dir === 'asc' ? $c : -$c;
        } );

        return [ $sites, $all, $q, $field, $month, $year, $st, $sort, $dir, $years ];
    }

    /** Sites list: search, month/year filter, sortable columns, row actions. */
    private static function screen_sites(): void {
        foreach ( DFY_LH_Store::all() as $x ) DFY_LH_Engine::heal_start( $x ); // connected sites always have a start date
        [ $sites, $all, $q, $field, $month, $year, $st, $sort, $dir, $years ] = self::query( $_GET );
        if ( ! $all ) {
            echo '<div class="dfy-card dfy-empty"><h2>No sites yet</h2><p>Add each DFY project with <strong>Add site</strong>. The licenses are activated as soon as DFY License Manager connects.</p><p><a class="button button-primary" data-dfy-open="add" href="' . esc_url( self::url( [ 'tab' => 'add' ] ) ) . '">+ Add site</a></p></div>';
            return;
        }

        // Summary tiles (each one filters the list).
        $ph    = array_count_values( array_map( function ( $x ) { return self::phase( $x ) . ( in_array( $x['status'], [ 'active', 'reference' ], true ) ? '' : '-' . $x['status'] ); }, $all ) );
        $count = function ( string $status ) use ( $all ) { return count( array_filter( $all, function ( $x ) use ( $status ) { return $x['status'] === $status; } ) ); };
        $tiles = [
            ''         => [ 'All sites', count( $all ), 'primary' ],
            'active'   => [ 'Active', (int) ( $ph['running'] ?? 0 ), 'ok' ],
            'expiring' => [ 'Expires soon', (int) ( $ph['soon'] ?? 0 ), 'warn' ],
            'expired'  => [ 'Expired', (int) ( $ph['expired'] ?? 0 ), 'bad' ],
            'pending'  => [ 'Not connected', $count( 'pending' ), 'muted' ],
            'done'     => [ 'Licenses removed', count( array_filter( $all, function ( $x ) { return self::phase( $x ) === 'done'; } ) ), 'muted' ],
        ];
        echo '<div class="dfy-stats">';
        foreach ( $tiles as $key => [ $label, $n, $kind ] ) {
            printf( '<a class="dfy-stat dfy-stat--%s%s%s" href="%s"><span class="dfy-stat__label">%s</span><span class="dfy-stat__value">%d</span></a>',
                esc_attr( $kind ), $st === $key ? ' is-current' : '', $n && in_array( $kind, [ 'warn', 'bad' ], true ) ? ' has-items' : '',
                esc_url( self::url( $key === '' ? [] : [ 'status' => $key ] ) ), esc_html( $label ), $n );
        }
        echo '</div>';

        // Filter bar.
        echo '<div class="dfy-card"><div class="dfy-toolbar"><form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
        echo '<input type="search" class="dfy-search" name="s" value="' . esc_attr( $q ) . '" placeholder="Search name, customer, email, URL or link">';
        echo '<select name="status"><option value="">All statuses</option>';
        foreach ( [ 'action' => 'Needs action (expires soon / expired)', 'active' => 'Active', 'expiring' => 'Expires soon (within 30 days)', 'expired' => 'Expired', 'ended' => 'Ended / expired (licenses ended or removed)', 'pending' => 'Not connected yet', 'removed' => 'Licenses deactivated', 'reference' => 'Without DFY License Manager' ] as $k => $l ) printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $st, $k, false ), esc_html( $l ) );
        echo '</select><select name="by">';
        foreach ( [ 'start' => 'Start date', 'expires' => 'Expiry date', 'added' => 'Date added' ] as $k => $l ) printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $field, $k, false ), esc_html( $l ) );
        echo '</select><select name="m"><option value="0">All months</option>';
        for ( $i = 1; $i <= 12; $i++ ) printf( '<option value="%d"%s>%02d - %s</option>', $i, selected( $month, $i, false ), $i, esc_html( gmdate( 'M', gmmktime( 0, 0, 0, $i, 1, 2000 ) ) ) );
        echo '</select><select name="y"><option value="0">All years</option>';
        foreach ( array_keys( $years ) as $y ) printf( '<option value="%d"%s>%d</option>', $y, selected( $year, $y, false ), $y );
        echo '</select><input type="hidden" name="orderby" value="' . esc_attr( $sort ) . '"><input type="hidden" name="order" value="' . esc_attr( $dir ) . '">';
        echo '<button class="button button-primary">Filter</button>';
        if ( $q !== '' || $month || $year || $st !== '' ) echo '<a class="button" href="' . esc_url( self::url( [] ) ) . '">Clear</a>';
        echo '<span class="dfy-toolbar__count">' . count( $sites ) . ' of ' . count( $all ) . ' sites</span></form></div>';
        $exp = wp_nonce_url( admin_url( 'admin-post.php?' . http_build_query( array_filter( [ 'action' => 'dfy_lh_export', 's' => $q, 'by' => $field, 'm' => $month ?: null, 'y' => $year ?: null, 'status' => $st, 'orderby' => $sort, 'order' => $dir ] ) ) ), 'dfy_lh_export' );
        echo '<div class="dfy-toolbar__row"><div class="dfy-legend"><span><i class="is-soon"></i>Expires soon (within 30 days)</span><span><i class="is-ended"></i>Expired — action needed</span></div><div class="dfy-actions">';
        if ( DFY_LH_Store::asana_token() !== '' ) {
            $missing = count( array_filter( $all, function ( $x ) { return DFY_LH_Asana::project_gid( (string) ( $x['flags']['asana_url'] ?? '' ) ) !== '' && empty( $x['flags']['email'] ); } ) );
            if ( $missing ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                wp_nonce_field( 'dfy_lh_asana_fill' );
                echo '<input type="hidden" name="action" value="dfy_lh_asana_fill"><button class="button">Fill customer name and email from Asana (' . $missing . ' sites)</button></form>';
            }
        }
        echo '<a class="button" href="' . esc_url( $exp ) . '">Export these ' . count( $sites ) . ' sites to Excel (CSV)</a></div></div></div>';

        $th = function ( string $key, string $label ) use ( $sort, $dir, $q, $field, $month, $year, $st ) {
            $next = ( $sort === $key && $dir === 'desc' ) ? 'asc' : 'desc';
            $url  = self::url( array_filter( [ 's' => $q, 'by' => $field, 'm' => $month ?: null, 'y' => $year ?: null, 'status' => $st, 'orderby' => $key, 'order' => $next ] ) );
            $arrow = $sort === $key ? ( $dir === 'asc' ? ' ▲' : ' ▼' ) : '';
            return '<th><a href="' . esc_url( $url ) . '">' . esc_html( $label . $arrow ) . '</a></th>';
        };
        echo '<div class="dfy-card dfy-card--flush"><div class="dfy-table-wrap"><table class="widefat dfy-sites"><thead><tr><th>#</th>' . $th( 'label', 'Site' ) . '<th>Status</th>' . $th( 'start', 'Start date' ) . $th( 'expires', 'Licenses expire' ) . $th( 'last_seen', 'Last check-in' ) . '<th>Licenses</th><th>Actions</th></tr></thead><tbody>';
        if ( ! $sites ) echo '<tr><td colspan="8" class="dfy-empty">No sites match.</td></tr>';
        $n = 0;
        foreach ( $sites as $s ) {
            $n++;
            $u = self::urgency( $s );
            $chips = '';
            foreach ( $s['plugins'] as $slug => $p ) if ( ( $p['state'] ?? '' ) !== 'not_installed' ) $chips .= self::chip( $slug, (string) ( $p['state'] ?? '' ) );
            echo '<tr' . ( $u ? ' class="' . ( strpos( $u, 'Ends in' ) === 0 ? 'dfy-soon' : 'dfy-urgent' ) . '"' : '' ) . '><td>' . $n . '</td><td><a href="' . esc_url( self::url( [ 'site' => $s['id'] ] ) ) . '"><strong>' . esc_html( $s['label'] ) . '</strong></a><br><small>' . ( $s['site_url'] !== '' ? '<a href="' . esc_url( $s['site_url'] ) . '" target="_blank" rel="noopener">' . esc_html( preg_replace( '~^https?://~', '', rtrim( $s['site_url'], '/' ) ) ) . '</a>' : 'no site URL' ) . ( ! empty( $s['flags']['client'] ) ? '<br>' . esc_html( $s['flags']['client'] ) : '' ) . ( ! empty( $s['flags']['email'] ) ? ( empty( $s['flags']['client'] ) ? '<br>' : ' · ' ) . '<a href="mailto:' . esc_attr( $s['flags']['email'] ) . '">' . esc_html( $s['flags']['email'] ) . '</a>' : '' ) . '</small></td>';
            echo '<td>' . self::badge( $s ) . '</td>';
            echo '<td><span class="dfy-date">' . esc_html( $s['start_at'] ? self::num_date( $s['start_at'] ) : '-' ) . '</span>' . ( $s['start_at'] ? '<span class="dfy-date-sub">' . esc_html( [ 'manual' => 'set by hand', 'completed' => 'project completed', 'connected' => 'when connected', 'detected' => 'found on the site' ][ $s['start_source'] ] ?? $s['start_source'] ) . '</span>' : '' ) . '</td>';
            echo '<td' . ( $u ? ' class="dfy-red"' : '' ) . '><span class="dfy-date">' . esc_html( $s['expires_at'] ? self::num_date( $s['expires_at'] ) : '-' ) . '</span>' . ( $u ? '<small class="dfy-red dfy-date-sub">' . esc_html( $u ) . '</small>' : '' ) . '</td>';
            echo '<td>' . esc_html( $s['last_seen'] ? human_time_diff( strtotime( $s['last_seen'] . ' UTC' ) ) . ' ago' : '-' ) . '</td>';
            echo '<td>' . ( $chips !== '' ? '<div class="dfy-chips">' . $chips . '</div>' : '<span class="dfy-sub">-</span>' ) . '</td><td>';
            self::row_actions( $s );
            echo '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    /**
     * Why a site needs attention now ('' = nothing to do):
     * licenses end within 30 days, or ended and still need removing.
     */
    public static function urgency( array $s ): string {
        if ( ! $s['expires_at'] || ! in_array( $s['status'], [ 'active', 'pending', 'reference' ], true ) ) return '';
        if ( $s['status'] === 'reference' && ! empty( $s['flags']['manual_removed'] ) ) return '';
        if ( DFY_LH_Engine::keeps( $s ) ) return '';
        $left = (int) ceil( ( strtotime( $s['expires_at'] . ' UTC' ) - time() ) / DAY_IN_SECONDS );
        if ( $left > 30 ) return '';
        if ( $left > 0 ) return 'Ends in ' . $left . ' day' . ( $left === 1 ? '' : 's' ) . ( $s['status'] === 'reference' ? ' — remove by hand' : '' );
        if ( $s['status'] === 'reference' ) return 'Ended — remove the licenses by hand, then click "Mark removed"';
        return DFY_LH_Store::setting( 'test_mode' ) ? 'Ended — test mode is on, click "Deactivate licenses"' : 'Ended — removal in progress';
    }

    /** Dates as numbers plus month name: 2026-11-17 (Nov 17, 2026). */
    private static function num_date( string $utc ): string {
        $t = strtotime( $utc . ' UTC' );
        return wp_date( 'Y-m-d', $t ) . ' (' . wp_date( 'M j, Y', $t ) . ')';
    }

    /** Row buttons (list and site page). */
    private static function row_actions( array $s, bool $wide = false ): void {
        $hidden = [ 'site' => $s['id'], 'return' => $wide ? 'site' : 'list' ];
        $link   = (string) ( $s['flags']['asana_url'] ?? '' );
        echo '<div class="dfy-actions">';
        if ( $link !== '' ) echo '<a class="button button-small" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . ( strpos( $link, 'pipefy' ) !== false ? 'Pipefy' : 'Asana' ) . ' ↗</a>';
        if ( in_array( $s['status'], [ 'active', 'pending', 'reference' ], true ) ) {
            self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'set_start' ] );
            echo '<input type="date" name="start" required value="' . esc_attr( $s['start_at'] ? wp_date( 'Y-m-d', strtotime( $s['start_at'] . ' UTC' ) ) : '' ) . '"><button class="button button-small" onclick="return confirm(\'Set the start date? The licenses then expire one year (' . esc_js( (string) DFY_LH_Store::setting( 'term_days' ) ) . ' days) after it.\')">Set start date</button></form>';
        }
        if ( $s['status'] === 'active' ) {
            self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'remove_now' ] );
            echo '<button class="button button-small button-link-delete" onclick="return confirm(\'Deactivate all DFY licenses on ' . esc_js( $s['site_url'] ) . ' now? The premium plugins and DFY License Manager stay installed; you can activate the licenses again later. This ignores test mode.\')">Deactivate licenses</button></form>';
            self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'checkin' ] );
            echo '<button class="button button-small">Check in now</button></form>';
        }
        if ( $s['status'] === 'removed' ) {
            self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'activate' ] );
            $exp = DFY_LH_Engine::is_expired( $s );
            echo '<button class="button button-small button-primary" onclick="return confirm(' . esc_attr( wp_json_encode( 'Activate the DFY licenses on ' . $s['site_url'] . ' again?' . ( $exp ? ' The 1-year period has ended, so a new 1-year period starts today.' : ' The current period (until ' . DFY_LH_Engine::fmt( $s['expires_at'] ) . ') continues.' ) ) ) . ')">Activate licenses</button></form>';
        }
        if ( $s['status'] === 'reference' && DFY_LH_Engine::is_expired( $s ) && empty( $s['flags']['manual_removed'] ) ) {
            self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'handled' ] );
            echo '<button class="button button-small button-primary" onclick="return confirm(\'Confirm the DFY licenses for this site were removed in the vendor accounts?\')">Mark removed</button></form>';
        }
        if ( $s['status'] === 'reference' && $wide ) {
            self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'track' ] );
            echo '<button class="button button-small" title="Use this once DFY License Manager is installed on the site">Track with DFY License Manager</button></form>';
        }
        if ( $wide && in_array( $s['status'], [ 'active', 'reference' ], true ) && $s['expires_at'] && ! DFY_LH_Engine::keeps( $s ) ) {
            self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'remind' ] );
            $m = (string) DFY_LH_Store::setting( 'customer_mail' );
            echo '<button class="button button-small" title="' . esc_attr( $m === 'live' ? 'Sends to ' . ( $s['flags']['email'] ?? 'the customer' ) : 'Test mode: sends to the test address on the Settings tab' ) . '">Send expiry reminder email</button></form>';
        }
        self::form_open( 'dfy_lh_site_action', [ 'site' => $s['id'], 'do' => 'delete' ] );
        $q = $s['status'] === 'active'
            ? 'Delete ' . $s['label'] . '? All its DFY licenses are removed first (the premium plugins stay active), then the site is deleted from this list.'
            : 'Delete ' . $s['label'] . ' from this list?' . ( $s['status'] === 'reference' ? ' (Reference entry: nothing on the site is changed.)' : '' );
        echo '<button class="button button-small button-link-delete" onclick="return confirm(' . esc_attr( wp_json_encode( $q ) ) . ')">Delete</button></form>';
        echo '</div>';
    }

    private static function form_open( string $action, array $hidden = [] ): void {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( $action );
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
        foreach ( $hidden as $k => $v ) echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
    }

    /** The Add site form (shown in the popup, and on ?tab=add without JavaScript). */
    private static function add_form(): void {
        self::form_open( 'dfy_lh_add_site' );
        $asana = DFY_LH_Store::asana_token() !== ''
            ? 'The customer name and email are filled in from this project when left empty below.'
            : 'For reference. Add an Asana token on the Settings tab to fill the customer name and email from it.';
        echo '<div class="dfy-form">
            <label class="dfy-field"><span>Client site URL <em>*</em></span><input name="site_url" type="url" required placeholder="https://example.com"><small>The live site the licenses will be used on.</small></label>
            <label class="dfy-field"><span>Asana project</span><input name="asana" type="url" placeholder="https://app.asana.com/…"><small>' . esc_html( $asana ) . '</small></label>
            <label class="dfy-field"><span>Project / site name</span><input name="label" type="text" placeholder="Community or project name"></label>
            <div class="dfy-form__row">
                <label class="dfy-field"><span>Customer name</span><input name="client" type="text" placeholder="Filled from Asana when empty"></label>
                <label class="dfy-field"><span>Customer email</span><input name="email" type="email" placeholder="Filled from Asana when empty"></label>
            </div>
            <p class="dfy-form__note">If DFY License Manager is already active on the client site, the licenses are activated as soon as you click the button. Otherwise they are activated when it is installed there. The 1-year period starts at that moment.</p>
        </div>
        <div class="dfy-modal__foot"><button type="button" class="button" data-dfy-close>Cancel</button><button class="button button-primary">Add site and create connection code</button></div></form>';
    }

    private static function add_modal(): void {
        echo '<dialog class="dfy-modal" id="dfy-add-site" aria-labelledby="dfy-add-title"><div class="dfy-modal__head"><div><h2 id="dfy-add-title">Add a DFY site</h2><p>The licenses are activated as soon as DFY License Manager on the site connects.</p></div><button type="button" class="dfy-modal__x" data-dfy-close aria-label="Close">&times;</button></div>';
        self::add_form();
        echo '</dialog>';
        echo "<script>(function(){var d=document.getElementById('dfy-add-site');if(!d||!d.showModal)return;"
            . "document.querySelectorAll('[data-dfy-open=add]').forEach(function(b){b.addEventListener('click',function(e){e.preventDefault();d.showModal();var i=d.querySelector('input[name=site_url]');if(i)i.focus();});});"
            . "d.querySelectorAll('[data-dfy-close]').forEach(function(b){b.addEventListener('click',function(){d.close();});});"
            . "d.addEventListener('click',function(e){if(e.target===d)d.close();});"
            . "if(/[?&]add=1(&|$)/.test(location.search))d.showModal();})();</script>";
    }

    /** Fallback page for ?tab=add (no JavaScript). */
    private static function screen_add(): void {
        echo '<div class="dfy-card"><div class="dfy-card__head"><div><h2>Add a DFY site</h2><p class="dfy-card__desc">The licenses are activated as soon as DFY License Manager on the site connects.</p></div></div>';
        self::add_form();
        echo '</div>';
    }

    /** Settings tab: bulk-add completed sites that have no DFY License Manager. */
    private static function import_card(): void {
        echo '<div class="dfy-card"><h2>Add completed sites for reference</h2>
            <p class="dfy-card__desc">For older DFY projects where DFY License Manager is not installed (often no admin access). They appear in the list with their start and end dates (Active, Expires soon, Expired), and the team gets an email when a year ends so the licenses can be removed by hand. Duplicates (same name, link or site URL) are skipped.</p>';
        self::form_open( 'dfy_lh_import' );
        echo '<textarea name="lines" rows="8" class="large-text code" placeholder="One per line:  Name | completion date (YYYY-MM-DD) | Asana or Pipefy link | client name | site URL&#10;Example Project | 2025-07-03 | https://app.pipefy.com/open-cards/123456 | Jane Doe |"></textarea>';
        submit_button( 'Add reference sites', 'secondary' );
        echo '</form></div>';
    }

    private static function screen_site( array $s ): void {
        $hidden = [ 'site' => $s['id'] ];
        $link   = (string) ( $s['flags']['asana_url'] ?? '' );
        $src    = [ 'manual' => 'set by hand', 'completed' => 'project completed', 'connected' => 'when the site connected', 'detected' => 'found on the site (earliest DFY plugin date)' ][ $s['start_source'] ] ?? '';
        $u      = self::urgency( $s );
        echo '<a class="dfy-back" href="' . esc_url( self::url( [] ) ) . '">&larr; All sites</a>';
        echo '<div class="dfy-card"><div class="dfy-site-head"><div><h2>' . esc_html( $s['label'] ) . ' ' . self::badge( $s ) . '</h2><div class="dfy-site-head__links">';
        if ( $s['site_url'] !== '' ) echo '<a href="' . esc_url( $s['site_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $s['site_url'] ) . ' ↗</a>';
        if ( ! empty( $s['flags']['client'] ) ) echo '<span>' . esc_html( $s['flags']['client'] ) . '</span>';
        if ( ! empty( $s['flags']['email'] ) ) echo '<a href="mailto:' . esc_attr( $s['flags']['email'] ) . '">' . esc_html( $s['flags']['email'] ) . '</a>';
        if ( $link !== '' ) echo '<a href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . ( strpos( $link, 'pipefy' ) !== false ? 'Pipefy' : 'Asana' ) . ' project ↗</a>';
        echo '</div></div></div><div class="dfy-facts">';
        $fact = function ( string $label, string $value, string $note = '' ) { echo '<div class="dfy-fact"><span class="dfy-fact__label">' . esc_html( $label ) . '</span><span class="dfy-fact__value">' . esc_html( $value ) . '</span>' . ( $note !== '' ? '<span class="dfy-fact__note">' . esc_html( $note ) . '</span>' : '' ) . '</div>'; };
        $fact( 'Start date', $s['start_at'] ? self::num_date( $s['start_at'] ) : 'Set when the site connects', $s['start_at'] ? $src : '' );
        $fact( 'Licenses expire', $s['expires_at'] ? self::num_date( $s['expires_at'] ) : '-', $u );
        $fact( 'Last check-in', $s['last_seen'] ? wp_date( 'M j, Y H:i', strtotime( $s['last_seen'] . ' UTC' ) ) : '-' );
        $fact( 'Site reports', implode( '  ·  ', array_unique( array_filter( $s['urls'] ) ) ) ?: '-' );
        echo '</div></div>';

        $code = DFY_LH_Rest::connection_code( $s );
        if ( $code !== '' ) {
            echo '<div class="notice notice-info inline"><p><strong>Connection code (backup)</strong>. Not needed normally: DFY License Manager connects by itself. Use it only if the automatic connection fails (for example the site answers on a different address): on the client site go to <em>Settings → DFY Licenses</em> and paste it. It works once.</p>
                <p><textarea readonly rows="3" class="large-text code" onclick="this.select()">' . esc_textarea( $code ) . '</textarea></p></div>';
        }

        echo '<div class="dfy-card"><h2>Licenses on this site</h2><p class="dfy-card__desc">Reported by DFY License Manager at each check-in.</p><table class="widefat"><thead><tr><th>Plugin</th><th>State</th><th>Details</th></tr></thead><tbody>';
        if ( ! $s['plugins'] ) echo '<tr><td colspan="3">' . ( $s['status'] === 'pending' ? 'Not connected yet.' : ( $s['status'] === 'reference' ? 'Reference only: DFY License Manager is not installed on this site.' : 'Connected, but no DFY premium plugin is licensed on the site yet.' ) ) . '</td></tr>';
        foreach ( $s['plugins'] as $slug => $p ) {
            $state = (string) ( $p['state'] ?? '' );
            $kind  = [ 'active' => 'ok', 'sent' => 'primary', 'removed' => 'muted', 'replaced' => 'muted', 'not_installed' => 'muted', 'remove_failed' => 'bad' ][ $state ] ?? 'warn';
            printf( '<tr><td><strong>%s</strong></td><td><span class="dfy-pill dfy-pill--%s">%s</span></td><td><small>%s</small></td></tr>', esc_html( DFY_License_Remote::label( $slug ) ), $kind, esc_html( str_replace( '_', ' ', $state ) ), esc_html( $p['msg'] ?? '' ) );
        }
        echo '</tbody></table></div>';

        echo '<div class="dfy-card"><h2>Actions</h2><p class="dfy-card__desc">Changes apply right away; the site is told at once when it can be reached.</p>';
        self::row_actions( $s, true );
        if ( $s['status'] === 'pending' || $s['status'] !== 'removed' ) {
            echo '<div class="dfy-actions" style="margin-top:10px">';
            if ( $s['status'] === 'pending' ) {
                self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'connect_now' ] );
                echo '<button class="button button-primary">Connect now</button></form>';
            }
            if ( $s['status'] !== 'removed' && $s['status'] !== 'reference' ) {
                self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'new_code' ] );
                echo '<button class="button">New connection code</button></form>';
            }
            echo '</div>';
        }
        echo '</div>';

        $ev = (array) ( $s['flags']['evidence'] ?? [] );
        if ( $ev ) {
            $tpl = DFY_LH_Rest::template_dates( (int) $s['id'] );
            $sug = DFY_LH_Rest::suggested_start( $ev, $tpl );
            echo '<div class="dfy-card"><h2>Dates found on the site</h2><p class="dfy-card__desc">WordPress and the vendor plugins do not record when a license was activated. These are the dates the DFY plugins left on the site. Dates that are identical on other DFY sites were copied from the DFY starter template and are ignored. ' . ( $sug ? 'The suggested date is the earliest one that belongs to this site.' : '<strong>No date on this site shows when the licenses were activated</strong>; check the vendor account, or set the date by hand.' ) . '</p>';
            echo '<table class="widefat"><thead><tr><th>Date</th><th>Plugin</th><th>Found</th><th></th></tr></thead><tbody>';
            foreach ( $ev as $e ) {
                $is = $sug && $sug['date'] === $e['date'] && $sug['source'] === $e['source'];
                $tp = DFY_LH_Rest::is_template( $e, $tpl );
                $nt = $tp ? (int) $tpl[ $e['source'] . '|' . substr( $e['date'], 0, 10 ) ] : 0;
                echo '<tr><td><span class="dfy-date">' . esc_html( self::num_date( $e['date'] ) ) . '</span></td><td>' . esc_html( $e['label'] ) . '</td><td>' . esc_html( $e['source'] ) . ( $is ? ' <span class="dfy-tag dfy-tag--ok">← suggested start</span>' : '' ) . ( $tp ? ' <span class="dfy-tag dfy-tag--warn">copied from the DFY template (same on ' . $nt . ' other site' . ( $nt > 1 ? 's' : '' ) . ')</span>' : '' ) . '</td><td>';
                if ( $s['status'] !== 'removed' && $e['slug'] !== 'wp-fusion' && ! $tp ) {
                    self::form_open( 'dfy_lh_site_action', [ 'site' => $s['id'], 'do' => 'set_start', 'source' => 'detected', 'start' => gmdate( 'Y-m-d', strtotime( $e['date'] . ' UTC' ) ), 'return' => 'site' ] );
                    echo '<button class="button button-small" onclick="return confirm(\'Use ' . esc_js( gmdate( 'Y-m-d', strtotime( $e['date'] . ' UTC' ) ) ) . ' as the start date? The licenses then end one year later.\')">Use this date</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<div class="dfy-grid-2"><div class="dfy-card"><h2>Details</h2>';
        self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'edit' ] );
        echo '<table class="form-table"><tr><th>Name</th><td><input name="label" class="regular-text" value="' . esc_attr( $s['label'] ) . '"></td></tr>' . ( $s['status'] === 'reference' ? '
            <tr><th>Client site URL</th><td><input name="site_url" type="url" class="regular-text" value="' . esc_attr( $s['site_url'] ) . '"></td></tr>' : '' ) . '
            <tr><th>Keep licenses</th><td><label><input type="checkbox" name="keep" value="1" ' . checked( DFY_LH_Engine::keeps( $s ), true, false ) . '> Never remove this site\'s licenses automatically (older project). Only "Deactivate licenses" removes them.</label></td></tr>
            <tr><th>Customer name</th><td><input name="client" class="regular-text" value="' . esc_attr( $s['flags']['client'] ?? '' ) . '"></td></tr>
            <tr><th>Customer email</th><td><input name="email" type="email" class="regular-text" value="' . esc_attr( $s['flags']['email'] ?? '' ) . '"></td></tr>
            <tr><th>Asana / Pipefy link</th><td><input name="asana" type="url" class="regular-text" value="' . esc_attr( $link ) . '"><p class="description">Empty customer name/email are filled from the Asana project when you save (Asana token on the Settings tab).</p></td></tr></table>';
        submit_button( 'Save details', 'secondary' );
        echo '</form></div>';

        echo '<div class="dfy-card"><h2>Log</h2><p class="dfy-card__desc">Newest first.</p><div class="dfy-log">';
        foreach ( array_reverse( $s['log'] ) as $l ) {
            echo '<div class="dfy-log__item"><span class="dfy-log__time">' . esc_html( wp_date( 'Y-m-d H:i', strtotime( $l['t'] . ' UTC' ) ) ) . '</span><span>' . nl2br( esc_html( $l['m'] ) ) . '</span></div>';
        }
        if ( ! $s['log'] ) echo '<div class="dfy-log__item">No entries yet.</div>';
        echo '</div></div></div>';
    }

    private static function screen_licenses(): void {
        $lic = DFY_LH_Store::licenses();
        echo '<div class="dfy-card"><h2>Agency license keys</h2><p class="dfy-card__desc">The keys DFY sites receive. Stored encrypted and only sent to a site when it connects. Leave a key empty to keep the saved one.</p>';
        self::form_open( 'dfy_lh_save_licenses' );
        echo '<table class="widefat"><thead><tr><th>Plugin</th><th>License key / password</th><th>Account email / username</th><th>Clear</th></tr></thead><tbody>';
        foreach ( self::license_fields() as $slug => $label ) {
            $has = ( $lic[ $slug ]['key'] ?? '' ) !== '';
            $rcp = $slug === 'restrict-content-pro';
            printf( '<tr><td><strong>%s</strong>%s</td><td><input type="password" autocomplete="new-password" name="key[%s]" class="regular-text" placeholder="%s">%s</td><td>%s</td><td><input type="checkbox" name="clear[%s]" value="1"></td></tr>',
                esc_html( $label ), $rcp ? '<span class="dfy-sub">SolidWP login, not a key</span>' : '', esc_attr( $slug ),
                $has ? ( $rcp ? 'SolidWP password saved' : esc_attr( 'saved ••••' . substr( $lic[ $slug ]['key'], -4 ) ) ) : ( $rcp ? 'SolidWP password' : 'not set' ),
                $rcp ? '<p class="description">Stays on this hub. Each site only receives a sign-in token that works for that site, and its license is released after the year. Needs Restrict Content Pro 3.5 or later on the site.</p>' : '',
                $rcp ? '<input type="text" autocomplete="off" name="email[restrict-content-pro]" value="' . esc_attr( $lic[ $slug ]['email'] ?? '' ) . '" placeholder="SolidWP username">' : ( $slug === 'learndash' ? '<input type="email" name="email[learndash]" value="' . esc_attr( $lic[ $slug ]['email'] ?? '' ) . '" placeholder="Needed for LearnDash">' : ( $slug === 'elementor-pro' ? '<span style="color:#646970">No key needed (leave empty). Elementor Pro only activates with Elementor → License → Connect &amp; Activate on each site; DFY License Manager then takes it over and removes it after the year automatically.</span>' : '<span style="color:#646970">not needed</span>' ) ),
                esc_attr( $slug ) );
        }
        echo '</tbody></table>';
        submit_button( 'Save licenses' );
        echo '</form></div>';
    }

    private static function screen_settings(): void {
        $s = DFY_LH_Store::settings();
        echo '<div class="dfy-card"><h2>General</h2><p class="dfy-card__desc">License period, reminders, notifications and removal rules.</p>';
        self::form_open( 'dfy_lh_save_settings' );
        $f = function ( $name, $label, $html, $desc = '' ) {
            echo '<tr><th>' . esc_html( $label ) . '</th><td>' . $html . ( $desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : '' ) . '</td></tr>';
        };
        echo '<table class="form-table">';
        $f( 'term_days', 'License period (days)', '<input type="number" min="1" name="term_days" value="' . esc_attr( $s['term_days'] ) . '">' );
        $f( 'warn_days', 'Warn the DFY team (days before)', '<input name="warn_days" value="' . esc_attr( $s['warn_days'] ) . '">', 'Comma-separated, e.g. 30,7.' );
        $f( 'notify_email', 'Notification email', '<input type="email" name="notify_email" class="regular-text" value="' . esc_attr( $s['notify_email'] ) . '">' );
        $f( 'grace_days', 'Grace after expiry (days)', '<input type="number" min="0" name="grace_days" value="' . esc_attr( $s['grace_days'] ) . '">', 'How long to wait for the site to remove its own licenses before the hub removes them directly.' );
        $f( 'silent_alert', 'Alert when silent (days)', '<input type="number" min="1" name="silent_alert" value="' . esc_attr( $s['silent_alert'] ) . '">' );
        $f( 'silent_remove', 'Remove when silent (days)', '<input type="number" min="0" name="silent_remove" value="' . esc_attr( $s['silent_remove'] ) . '">', 'Remove licenses if a connected site stops checking in for this long (plugin deleted by FTP, site gone). 0 = never.' );
        $f( 'asana_token', 'Asana token (optional)', '<input type="password" autocomplete="new-password" name="asana_token" class="regular-text" placeholder="' . ( DFY_LH_Store::asana_token() !== '' ? 'saved' : 'not set' ) . '"> <label><input type="checkbox" name="clear_token" value="1"> clear</label>', 'Only used to fill the customer name and email from a site\'s Asana project (Customer First/Last Name and CONTACT EMAIL). Asana → profile → Settings → Apps → Developer apps → Personal access token. Stored encrypted.' );
        $f( 'contact_url', 'Customer contact link', '<input name="contact_url" type="url" class="large-text" value="' . esc_attr( $s['contact_url'] ) . '" placeholder="https://… or mailto:…">', 'Shown on client sites when their DFY licenses end: "buy your own license from each vendor, or contact BuddyBoss DFY for an alternative solution".' );
        $f( 'customer_mail', 'Customer reminder emails', '<select name="customer_mail">'
            . '<option value="test"' . selected( $s['customer_mail'], 'test', false ) . '>Test — send every customer email to the test address below</option>'
            . '<option value="live"' . selected( $s['customer_mail'], 'live', false ) . '>Live — send to each customer\'s email</option>'
            . '<option value="off"' . selected( $s['customer_mail'], 'off', false ) . '>Off</option></select>'
            . '<p><input type="email" name="customer_test_to" class="regular-text" value="' . esc_attr( $s['customer_test_to'] ) . '" placeholder="Test address"></p>',
            'Customers get an email 30 and 7 days before their DFY licenses end (with links to buy from each plugin author and your contact link). Sites marked "keep licenses" are skipped.' );
        $f( 'test_mode', 'Test mode', '<label><input type="checkbox" name="test_mode" value="1" ' . checked( $s['test_mode'], 1, false ) . '> Only log what would be removed</label>', 'Customers deactivating or deleting DFY License Manager still remove licenses, and "Deactivate licenses" still works.' );
        echo '</table>';
        submit_button( 'Save settings' );
        echo '</form></div><div class="dfy-grid-2">';

        // Encryption key status.
        echo '<div class="dfy-card"><h2>Encryption</h2>';
        echo DFY_Crypto::has_own_key()
            ? '<p><span class="dfy-tag dfy-tag--ok">✓ Permanent key set</span> (DFY_LICENSE_ENC_KEY in wp-config.php). Stored keys no longer depend on the WordPress salts.</p>'
            : '<p><span class="dfy-tag dfy-tag--warn">✗ No permanent key</span> License keys and site secrets are encrypted with this site\'s WordPress salts; if the salts change, they can no longer be read. Add this line to wp-config.php (above "That\'s all, stop editing"), with your own long random value:</p><p><code>define( \'DFY_LICENSE_ENC_KEY\', \'…64 random characters…\' );</code></p><p class="description">Existing data is re-encrypted with the new key automatically on the next page load. Keep a copy of the key; never change or remove it afterwards.</p>';
        echo '</div>';

        // WP-Cron health.
        $next = wp_next_scheduled( 'dfy_lh_daily' );
        $last = (int) get_option( 'dfy_lh_last_run' );
        $test = (array) get_option( 'dfy_lh_cron_test', [] );
        echo '<div class="dfy-card"><h2>Scheduled tasks (WP-Cron)</h2><p class="dfy-card__desc">The daily check sends reminders and removes ended licenses.</p><table class="widefat"><tbody>';
        echo '<tr><th style="width:220px">Daily license check</th><td>' . ( $next ? 'next run ' . esc_html( wp_date( 'M j, Y H:i', $next ) ) . ( $next < time() - HOUR_IN_SECONDS ? ' <strong style="color:#d63638">(overdue — WP-Cron is not running)</strong>' : '' ) : '<strong style="color:#d63638">not scheduled</strong>' ) . '</td></tr>';
        echo '<tr><th>Last daily check</th><td>' . ( $last ? esc_html( wp_date( 'M j, Y H:i', $last ) ) . ( time() - $last > 2 * DAY_IN_SECONDS ? ' <strong style="color:#d63638">(more than 2 days ago)</strong>' : '' ) : 'never' ) . '</td></tr>';
        echo '<tr><th>WP-Cron</th><td>' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'DISABLE_WP_CRON is set — a server cron job must call wp-cron.php' : 'runs when someone visits the site (a server cron job is more reliable)' ) . '</td></tr>';
        echo '<tr><th>Last WP-Cron test</th><td>' . ( ! empty( $test['ran'] ) ? 'queued ' . esc_html( wp_date( 'M j, H:i:s', (int) $test['queued'] ) ) . ', ran ' . esc_html( wp_date( 'M j, H:i:s', (int) $test['ran'] ) ) . ' — email to ' . esc_html( $test['to'] ) . ' ' . ( ! empty( $test['mail'] ) ? '<span class="dfy-status-ok">sent</span>' : '<span class="dfy-status-bad">wp_mail failed (check SMTP)</span>' ) : 'not run yet' ) . '</td></tr>';
        echo '</tbody></table>';
        self::form_open( 'dfy_lh_cron_test' );
        echo '<p><input type="email" name="to" class="regular-text" required value="' . esc_attr( $s['customer_test_to'] ?: $s['notify_email'] ) . '"> <button class="button">Send test email through WP-Cron</button></p><p class="description">Queues the email as a WP-Cron task. If it arrives (and "ran" appears above within a minute or two), WP-Cron and email both work.</p></form></div></div>';
        self::import_card();
    }

    public static function cron_test(): void {
        self::guard( 'dfy_lh_cron_test' );
        $to = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
        if ( ! is_email( $to ) ) self::back( [ 'tab' => 'settings' ], 'Enter an email address.', true );
        $q = time();
        wp_schedule_single_event( $q, 'dfy_lh_cron_test', [ $to, $q ] );
        if ( function_exists( 'spawn_cron' ) ) spawn_cron();
        self::back( [ 'tab' => 'settings' ], "Test email queued for WP-Cron (to {$to}). Reload this page in a minute: \"Last WP-Cron test\" should show it ran." );
    }
}
