<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DFY_LH_Admin {

    const PAGE = 'dfy-license-hub';
    const CAP  = 'manage_options';

    public static function init(): void {
        add_action( 'admin_menu', function () {
            add_menu_page( 'DFY Licenses', 'DFY Licenses', self::CAP, self::PAGE, [ __CLASS__, 'render' ], 'dashicons-shield', 81 );
        } );
        foreach ( [ 'add_site', 'site_action', 'save_licenses', 'save_settings', 'run_now', 'import' ] as $a ) {
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
        if ( $url === '' || ! wp_parse_url( $url, PHP_URL_HOST ) ) self::back( [ 'tab' => 'add' ], 'Enter the client site URL.', true );

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

    /** Asana project link, kept for reference only. */
    private static function save_asana( int $id, string $url ): void {
        $s = DFY_LH_Store::get( $id );
        $flags = $s['flags'];
        $flags['asana_url'] = esc_url_raw( trim( $url ) );
        DFY_LH_Store::update( $id, [ 'flags' => $flags ] );
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
                DFY_LH_Store::update( $id, [ 'status' => 'pending' ] );
                DFY_LH_Rest::new_token( $id );
                DFY_LH_Store::log( $id, 'Switched from reference to tracking with DFY License Manager. The start date is kept.' );
                self::connect_now( $id, 'Tracking on.' );
            case 'handled':
                $f = $site['flags']; $f['manual_removed'] = time();
                DFY_LH_Store::update( $id, [ 'flags' => $f ] );
                DFY_LH_Store::log( $id, 'Licenses marked as removed by hand by ' . wp_get_current_user()->user_login . '.' );
                self::back( $ret, $site['label'] . ': marked as removed.' );
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

    public static function save_licenses(): void {
        self::guard( 'dfy_lh_save_licenses' );
        foreach ( DFY_License_Remote::VENDORS as $slug => $label ) {
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
        ];
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
        echo '<div class="wrap"><h1>DFY Licenses</h1>';
        if ( ! empty( $_GET['dfy_msg'] ) ) echo '<div class="notice notice-success"><p>' . esc_html( wp_unslash( $_GET['dfy_msg'] ) ) . '</p></div>';
        if ( ! empty( $_GET['dfy_err'] ) ) echo '<div class="notice notice-error"><p>' . esc_html( wp_unslash( $_GET['dfy_err'] ) ) . '</p></div>';
        if ( DFY_LH_Store::setting( 'test_mode' ) ) echo '<div class="notice notice-warning"><p><strong>Test mode is on.</strong> Expired sites are only logged; no licenses are removed automatically. Turn it off on the Settings tab when you are ready.</p></div>';

        echo '<nav class="nav-tab-wrapper">';
        foreach ( [ 'sites' => 'Sites', 'add' => 'Add site', 'licenses' => 'Licenses', 'settings' => 'Settings' ] as $k => $l ) {
            printf( '<a class="nav-tab %s" href="%s">%s</a>', ( $tab === $k && ! $site ) ? 'nav-tab-active' : '', esc_url( self::url( [ 'tab' => $k ] ) ), esc_html( $l ) );
        }
        echo '</nav><div style="margin-top:16px">';
        if ( $site ) self::screen_site( $site );
        elseif ( $tab === 'add' ) self::screen_add();
        elseif ( $tab === 'licenses' ) self::screen_licenses();
        elseif ( $tab === 'settings' ) self::screen_settings();
        else self::screen_sites();
        echo '</div></div>';
    }

    private static function badge( array $s ): string {
        $now = time();
        if ( $s['status'] === 'removed' ) return '<span style="color:#646970">Licenses deactivated</span>';
        if ( $s['status'] === 'reference' ) return '<span style="color:#646970">Reference only</span><br><small style="color:#646970">' . ( ! empty( $s['flags']['manual_removed'] ) ? 'licenses removed by hand' : 'no DFY License Manager' ) . '</small>';
        if ( $s['status'] === 'pending' ) return '<span style="color:#996800">Not connected yet</span>';
        if ( DFY_LH_Engine::is_expired( $s ) ) return '<span style="color:#d63638;font-weight:600">Expired, removal pending</span>';
        if ( $s['last_seen'] && ( $now - strtotime( $s['last_seen'] . ' UTC' ) ) > DAY_IN_SECONDS * (int) DFY_LH_Store::setting( 'silent_alert' ) ) return '<span style="color:#d63638">Not checking in</span>';
        return '<span style="color:#008a20">Active</span>';
    }

    /** Sites list: search, month/year filter, sortable columns, row actions. */
    private static function screen_sites(): void {
        foreach ( DFY_LH_Store::all() as $x ) DFY_LH_Engine::heal_start( $x ); // connected sites always have a start date
        $all   = DFY_LH_Store::all();
        $last  = (int) get_option( 'dfy_lh_last_run' );
        $q     = trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) );
        $field = in_array( $_GET['by'] ?? '', [ 'start', 'expires', 'added' ], true ) ? $_GET['by'] : 'start';
        $month = max( 0, min( 12, (int) ( $_GET['m'] ?? 0 ) ) );
        $year  = (int) ( $_GET['y'] ?? 0 );
        $st    = in_array( $_GET['status'] ?? '', [ 'active', 'pending', 'removed', 'expiring', 'reference', 'action' ], true ) ? $_GET['status'] : '';
        $sort  = in_array( $_GET['orderby'] ?? '', [ 'label', 'start', 'expires', 'last_seen', 'added' ], true ) ? $_GET['orderby'] : 'start';
        $dir   = ( $_GET['order'] ?? 'desc' ) === 'asc' ? 'asc' : 'desc';
        $col   = [ 'start' => 'start_at', 'expires' => 'expires_at', 'added' => 'created_at', 'last_seen' => 'last_seen', 'label' => 'label' ];

        $years = [];
        foreach ( $all as $x ) foreach ( [ 'start_at', 'expires_at', 'created_at' ] as $k ) if ( ! empty( $x[ $k ] ) ) $years[ (int) substr( $x[ $k ], 0, 4 ) ] = 1;
        krsort( $years );

        $sites = array_values( array_filter( $all, function ( $x ) use ( $q, $field, $month, $year, $st, $col ) {
            if ( $q !== '' && stripos( $x['label'] . ' ' . $x['site_url'] . ' ' . ( $x['flags']['asana_url'] ?? '' ), $q ) === false ) return false;
            if ( $st === 'expiring' && ! ( in_array( $x['status'], [ 'active', 'reference' ], true ) && $x['expires_at'] && strtotime( $x['expires_at'] . ' UTC' ) < time() + 30 * DAY_IN_SECONDS ) ) return false;
            if ( $st === 'action' && ! self::urgency( $x ) ) return false;
            if ( $st !== '' && ! in_array( $st, [ 'expiring', 'action' ], true ) && $x['status'] !== $st ) return false;
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

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="float:right">';
        wp_nonce_field( 'dfy_lh_run_now' );
        echo '<input type="hidden" name="action" value="dfy_lh_run_now"><button class="button">Run daily check now</button></form>';
        echo '<p>Last daily check: ' . ( $last ? esc_html( wp_date( 'M j, Y H:i', $last ) ) : 'never' ) . '</p>';
        if ( ! $all ) { echo '<p>No sites yet. Use <strong>Add site</strong> for each DFY project.</p>'; return; }

        // Filter bar.
        echo '<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
        echo '<input type="search" name="s" value="' . esc_attr( $q ) . '" placeholder="Search name, URL or Asana link" style="min-width:260px">';
        echo '<select name="status"><option value="">All statuses</option>';
        foreach ( [ 'action' => 'Needs action (ending within 30 days / ended)', 'active' => 'Active', 'expiring' => 'Expiring within 30 days', 'pending' => 'Not connected yet', 'removed' => 'Licenses deactivated', 'reference' => 'Reference only (completed, no plugin)' ] as $k => $l ) printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $st, $k, false ), esc_html( $l ) );
        echo '</select><select name="by">';
        foreach ( [ 'start' => 'Start date', 'expires' => 'Expiry date', 'added' => 'Date added' ] as $k => $l ) printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $field, $k, false ), esc_html( $l ) );
        echo '</select><select name="m"><option value="0">All months</option>';
        for ( $i = 1; $i <= 12; $i++ ) printf( '<option value="%d"%s>%02d - %s</option>', $i, selected( $month, $i, false ), $i, esc_html( gmdate( 'M', gmmktime( 0, 0, 0, $i, 1, 2000 ) ) ) );
        echo '</select><select name="y"><option value="0">All years</option>';
        foreach ( array_keys( $years ) as $y ) printf( '<option value="%d"%s>%d</option>', $y, selected( $year, $y, false ), $y );
        echo '</select><input type="hidden" name="orderby" value="' . esc_attr( $sort ) . '"><input type="hidden" name="order" value="' . esc_attr( $dir ) . '">';
        echo '<button class="button">Filter</button>';
        if ( $q !== '' || $month || $year || $st !== '' ) echo ' <a class="button-link" href="' . esc_url( self::url( [] ) ) . '">Clear</a>';
        echo '<span style="margin-left:auto;color:#646970">' . count( $sites ) . ' of ' . count( $all ) . ' sites</span></form>';

        $th = function ( string $key, string $label ) use ( $sort, $dir, $q, $field, $month, $year, $st ) {
            $next = ( $sort === $key && $dir === 'desc' ) ? 'asc' : 'desc';
            $url  = self::url( array_filter( [ 's' => $q, 'by' => $field, 'm' => $month ?: null, 'y' => $year ?: null, 'status' => $st, 'orderby' => $key, 'order' => $next ] ) );
            $arrow = $sort === $key ? ( $dir === 'asc' ? ' ▲' : ' ▼' ) : '';
            return '<th><a href="' . esc_url( $url ) . '">' . esc_html( $label . $arrow ) . '</a></th>';
        };
        echo '<style>.dfy-urgent td{background:#fcf0f1!important}.dfy-urgent td:first-child{box-shadow:inset 4px 0 0 #d63638}.dfy-red{color:#d63638;font-weight:600}</style>';
        echo '<table class="widefat striped"><thead><tr><th style="width:36px">#</th>' . $th( 'label', 'Site' ) . '<th>Status</th>' . $th( 'start', 'Start date' ) . $th( 'expires', 'Licenses expire' ) . $th( 'last_seen', 'Last check-in' ) . '<th>Licenses</th><th style="width:300px">Actions</th></tr></thead><tbody>';
        if ( ! $sites ) echo '<tr><td colspan="8">No sites match.</td></tr>';
        $n = 0;
        foreach ( $sites as $s ) {
            $n++;
            $u = self::urgency( $s );
            $lic = [];
            foreach ( $s['plugins'] as $slug => $p ) if ( ( $p['state'] ?? '' ) !== 'not_installed' ) $lic[] = DFY_License_Remote::label( $slug ) . ' (' . str_replace( '_', ' ', $p['state'] ) . ')';
            echo '<tr' . ( $u ? ' class="dfy-urgent"' : '' ) . '><td>' . $n . '</td><td><a href="' . esc_url( self::url( [ 'site' => $s['id'] ] ) ) . '"><strong>' . esc_html( $s['label'] ) . '</strong></a><br><small>' . ( $s['site_url'] !== '' ? '<a href="' . esc_url( $s['site_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $s['site_url'] ) . '</a>' : 'no site URL' ) . ( ! empty( $s['flags']['client'] ) ? ' · ' . esc_html( $s['flags']['client'] ) : '' ) . '</small></td>';
            echo '<td>' . self::badge( $s ) . '</td>';
            echo '<td>' . esc_html( $s['start_at'] ? self::num_date( $s['start_at'] ) : '-' ) . ( $s['start_at'] ? '<br><small style="color:#646970">' . esc_html( [ 'manual' => 'set by hand', 'completed' => 'project completed', 'connected' => 'when connected', 'detected' => 'found on the site' ][ $s['start_source'] ] ?? $s['start_source'] ) . '</small>' : '' ) . '</td>';
            echo '<td' . ( $u ? ' class="dfy-red"' : '' ) . '>' . esc_html( $s['expires_at'] ? self::num_date( $s['expires_at'] ) : '-' ) . ( $u ? '<br><small class="dfy-red">' . esc_html( $u ) . '</small>' : '' ) . '</td>';
            echo '<td>' . esc_html( $s['last_seen'] ? human_time_diff( strtotime( $s['last_seen'] . ' UTC' ) ) . ' ago' : '-' ) . '</td>';
            echo '<td><small>' . esc_html( implode( ', ', $lic ) ?: '-' ) . '</small></td><td>';
            self::row_actions( $s );
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Why a site needs attention now ('' = nothing to do):
     * licenses end within 30 days, or ended and still need removing.
     */
    public static function urgency( array $s ): string {
        if ( ! $s['expires_at'] || ! in_array( $s['status'], [ 'active', 'pending', 'reference' ], true ) ) return '';
        if ( $s['status'] === 'reference' && ! empty( $s['flags']['manual_removed'] ) ) return '';
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
        echo '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">';
        if ( $link !== '' ) echo '<a class="button button-small" href="' . esc_url( $link ) . '" target="_blank" rel="noopener">' . ( strpos( $link, 'pipefy' ) !== false ? 'Pipefy' : 'Asana' ) . ' ↗</a>';
        if ( in_array( $s['status'], [ 'active', 'pending', 'reference' ], true ) ) {
            self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'set_start' ] );
            echo '<input type="date" name="start" required value="' . esc_attr( $s['start_at'] ? wp_date( 'Y-m-d', strtotime( $s['start_at'] . ' UTC' ) ) : '' ) . '" style="width:140px"> <button class="button button-small" onclick="return confirm(\'Set the start date? The licenses then expire one year (' . esc_js( (string) DFY_LH_Store::setting( 'term_days' ) ) . ' days) after it.\')">Set start date</button></form>';
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

    private static function screen_add(): void {
        self::form_open( 'dfy_lh_add_site' );
        echo '<table class="form-table">
            <tr><th>Client site URL</th><td><input name="site_url" type="url" class="regular-text" required placeholder="https://example.com"><p class="description">The live site the licenses will be used on.</p></td></tr>
            <tr><th>Customer name</th><td><input name="label" class="regular-text" placeholder="Customer or community name"></td></tr>
            <tr><th>Asana project</th><td><input name="asana" type="url" class="large-text" placeholder="https://app.asana.com/…"><p class="description">For reference only.</p></td></tr>
            <tr><th>Older DFY project</th><td><label><input type="checkbox" name="older" value="1"> The DFY licenses were activated on this site long ago — find the start date on the site</label>
                <p class="description">DFY License Manager looks for the earliest date the DFY plugins left on the site (Elementor install history, first Gravity Forms form, first LearnDash course, first AutomatorWP automation, first WP All Import import) and the 1-year period counts from that date. Licenses already on the site are taken over as they are. If that year is already over, no keys are sent and the licenses are removed (unless test mode is on). You can change the date afterwards.</p></td></tr>
        </table>
        <p class="description">If DFY License Manager is already active on the client site, the licenses are activated as soon as you click the button. Otherwise they are activated when it is installed there. The 1-year period starts at that moment.</p>';
        submit_button( 'Add site and create connection code' );
        echo '</form>';

        echo '<hr style="margin:32px 0"><h2>Add completed sites for reference</h2>
            <p>For older DFY projects where DFY License Manager is not installed (often no admin access). They appear in the list as <em>Reference only</em> with their start and end dates, and the team gets an email when a year ends so the licenses can be removed by hand. Duplicates (same name, link or site URL) are skipped.</p>';
        self::form_open( 'dfy_lh_import' );
        echo '<textarea name="lines" rows="8" class="large-text code" placeholder="One per line:  Name | completion date (YYYY-MM-DD) | Asana or Pipefy link | client name | site URL&#10;Example Project | 2025-07-03 | https://app.pipefy.com/open-cards/123456 | Jane Doe |"></textarea>';
        submit_button( 'Add reference sites', 'secondary' );
        echo '</form>';
    }

    private static function screen_site( array $s ): void {
        $hidden = [ 'site' => $s['id'] ];
        echo '<h2>' . esc_html( $s['label'] ) . ' <small style="font-weight:400">' . self::badge( $s ) . '</small></h2>';
        echo '<p><a href="' . esc_url( $s['site_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $s['site_url'] ) . '</a>';
        echo '</p>';

        $code = DFY_LH_Rest::connection_code( $s );
        if ( $code !== '' ) {
            echo '<div class="notice notice-info inline"><p><strong>Connection code (backup)</strong>. Not needed normally: DFY License Manager connects by itself. Use it only if the automatic connection fails (for example the site answers on a different address): on the client site go to <em>Settings → DFY Licenses</em> and paste it. It works once.</p>
                <p><textarea readonly rows="3" class="large-text code" onclick="this.select()">' . esc_textarea( $code ) . '</textarea></p></div>';
        }

        echo '<table class="widefat" style="max-width:900px"><tbody>';
        $rows = [
            'Start date'      => $s['start_at'] ? self::num_date( $s['start_at'] ) . ( [ 'manual' => ' — set by hand', 'completed' => ' — project completed', 'connected' => ' — when the site connected', 'detected' => ' — found on the site (earliest DFY plugin date)' ][ $s['start_source'] ] ?? '' ) : 'Set when the site connects',
            'Licenses expire' => $s['expires_at'] ? self::num_date( $s['expires_at'] ) : '-',
            'Last check-in'   => $s['last_seen'] ? wp_date( 'M j, Y H:i', strtotime( $s['last_seen'] . ' UTC' ) ) : '-',
            'Site reports'    => implode( '  ·  ', array_filter( $s['urls'] ) ) ?: '-',
        ];
        foreach ( $rows as $k => $v ) echo '<tr><th style="width:180px">' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
        echo '</tbody></table>';

        $ev = (array) ( $s['flags']['evidence'] ?? [] );
        if ( $ev ) {
            $sug = DFY_LH_Rest::suggested_start( $ev );
            echo '<h3>Dates found on the site</h3><p class="description">WordPress does not record when a license was activated. These are the earliest dates the DFY plugins left on the site; the oldest DFY plugin date is the best estimate of when the DFY team set them up. To confirm, check the activation date in the vendor account.</p>';
            echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Date</th><th>Plugin</th><th>Found</th><th></th></tr></thead><tbody>';
            foreach ( $ev as $e ) {
                $is = $sug && $sug['date'] === $e['date'] && $sug['source'] === $e['source'];
                echo '<tr' . ( $is ? ' style="font-weight:600"' : '' ) . '><td>' . esc_html( self::num_date( $e['date'] ) ) . '</td><td>' . esc_html( $e['label'] ) . '</td><td>' . esc_html( $e['source'] ) . ( $is ? ' <span style="color:#008a20">← suggested start</span>' : '' ) . '</td><td>';
                if ( $s['status'] !== 'removed' && $e['slug'] !== 'wp-fusion' ) {
                    self::form_open( 'dfy_lh_site_action', [ 'site' => $s['id'], 'do' => 'set_start', 'source' => 'detected', 'start' => gmdate( 'Y-m-d', strtotime( $e['date'] . ' UTC' ) ), 'return' => 'site' ] );
                    echo '<button class="button button-small" onclick="return confirm(\'Use ' . esc_js( gmdate( 'Y-m-d', strtotime( $e['date'] . ' UTC' ) ) ) . ' as the start date? The licenses then end one year later.\')">Use this date</button></form>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h3>Licenses on this site</h3><table class="widefat striped" style="max-width:900px"><thead><tr><th>Plugin</th><th>State</th><th>Details</th></tr></thead><tbody>';
        if ( ! $s['plugins'] ) echo '<tr><td colspan="3">' . ( $s['status'] === 'pending' ? 'Not connected yet.' : 'Connected, but no DFY premium plugin is licensed on the site yet.' ) . '</td></tr>';
        foreach ( $s['plugins'] as $slug => $p ) {
            printf( '<tr><td>%s</td><td>%s</td><td><small>%s</small></td></tr>', esc_html( DFY_License_Remote::label( $slug ) ), esc_html( str_replace( '_', ' ', $p['state'] ?? '' ) ), esc_html( $p['msg'] ?? '' ) );
        }
        echo '</tbody></table>';

        echo '<h3>Actions</h3>';
        self::row_actions( $s, true );
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start;margin-top:8px">';
        if ( $s['status'] === 'pending' ) {
            self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'connect_now' ] );
            echo '<p><button class="button button-primary">Connect now</button></p></form>';
        }
        if ( $s['status'] !== 'removed' ) {
            self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'new_code' ] );
            echo '<p><button class="button">New connection code</button></p></form>';
        }
        echo '</div>';

        self::form_open( 'dfy_lh_site_action', $hidden + [ 'do' => 'edit' ] );
        echo '<h3>Details</h3><table class="form-table"><tr><th>Name</th><td><input name="label" class="regular-text" value="' . esc_attr( $s['label'] ) . '"></td></tr>' . ( $s['status'] === 'reference' ? '
            <tr><th>Client site URL</th><td><input name="site_url" type="url" class="regular-text" value="' . esc_attr( $s['site_url'] ) . '"></td></tr>' : '' ) . '
            <tr><th>Asana project</th><td><input name="asana" type="url" class="large-text" value="' . esc_attr( $s['flags']['asana_url'] ?? '' ) . '"><p class="description">For reference only.</p></td></tr></table>';
        submit_button( 'Save details', 'secondary' );
        echo '</form>';

        echo '<h3>Log</h3><div style="max-width:900px;max-height:360px;overflow:auto;background:#fff;border:1px solid #c3c4c7;padding:8px 12px">';
        foreach ( array_reverse( $s['log'] ) as $l ) {
            echo '<p style="margin:4px 0"><code>' . esc_html( wp_date( 'Y-m-d H:i', strtotime( $l['t'] . ' UTC' ) ) ) . '</code> ' . nl2br( esc_html( $l['m'] ) ) . '</p>';
        }
        echo '</div>';

    }

    private static function screen_licenses(): void {
        $lic = DFY_LH_Store::licenses();
        self::form_open( 'dfy_lh_save_licenses' );
        echo '<p>The agency license keys DFY sites receive. Keys are stored encrypted and are only sent to a site once, at connection. Leave a key empty to keep the saved one.</p>';
        echo '<table class="widefat" style="max-width:900px"><thead><tr><th>Plugin</th><th>License key</th><th>Account email</th><th>Clear</th></tr></thead><tbody>';
        foreach ( DFY_License_Remote::VENDORS as $slug => $label ) {
            $has = ( $lic[ $slug ]['key'] ?? '' ) !== '';
            printf( '<tr><td><strong>%s</strong></td><td><input type="password" autocomplete="new-password" name="key[%s]" class="regular-text" placeholder="%s"></td><td>%s</td><td><input type="checkbox" name="clear[%s]" value="1"></td></tr>',
                esc_html( $label ), esc_attr( $slug ), $has ? esc_attr( 'saved ••••' . substr( $lic[ $slug ]['key'], -4 ) ) : 'not set',
                $slug === 'learndash' ? '<input type="email" name="email[learndash]" value="' . esc_attr( $lic[ $slug ]['email'] ?? '' ) . '" placeholder="Needed for LearnDash">' : ( $slug === 'elementor-pro' ? '<span style="color:#646970">No key needed (leave empty). Elementor Pro only activates with Elementor → License → Connect &amp; Activate on each site; DFY License Manager then takes it over and removes it after the year automatically.</span>' : '<span style="color:#646970">not needed</span>' ),
                esc_attr( $slug ) );
        }
        echo '</tbody></table>';
        submit_button( 'Save licenses' );
        echo '</form>';
    }

    private static function screen_settings(): void {
        $s = DFY_LH_Store::settings();
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
        $f( 'contact_url', 'Customer contact link', '<input name="contact_url" type="url" class="large-text" value="' . esc_attr( $s['contact_url'] ) . '" placeholder="https://… or mailto:…">', 'Shown on client sites when their DFY licenses end: "buy your own license from each vendor, or contact BuddyBoss DFY for an alternative solution".' );
        $f( 'test_mode', 'Test mode', '<label><input type="checkbox" name="test_mode" value="1" ' . checked( $s['test_mode'], 1, false ) . '> Only log what would be removed</label>', 'Customers deactivating or deleting DFY License Manager still remove licenses, and "Deactivate licenses" still works.' );
        echo '</table>';
        submit_button( 'Save settings' );
        echo '</form>';
    }
}
