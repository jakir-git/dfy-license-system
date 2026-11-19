<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class DFY_LM_Admin {

    const PAGE = 'dfy-licenses';

    public static function init(): void {
        add_action( 'admin_menu', function () {
            add_options_page( 'DFY Licenses', 'DFY Licenses', 'manage_options', self::PAGE, [ __CLASS__, 'render' ] );
        } );
        add_action( 'admin_post_dfy_lm_connect', [ __CLASS__, 'connect' ] );
        add_action( 'admin_post_dfy_lm_check', [ __CLASS__, 'check' ] );
        add_action( 'admin_post_dfy_lm_auto', [ __CLASS__, 'auto' ] );
        add_action( 'admin_notices', [ __CLASS__, 'expiry_notice' ] );
        add_action( 'admin_footer', [ __CLASS__, 'expiry_popup' ] );
        add_action( 'wp_ajax_dfy_lm_dismiss', [ __CLASS__, 'dismiss' ] );
        add_action( 'wp_ajax_dfy_lm_popup_seen', [ __CLASS__, 'popup_seen' ] );
        add_filter( 'plugin_row_meta', [ __CLASS__, 'row_meta' ], 10, 2 );
        add_action( 'admin_footer-plugins.php', [ __CLASS__, 'deactivate_confirm' ] );
    }

    private static function back( string $msg, bool $err = false ): void {
        wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, $err ? 'dfy_err' : 'dfy_msg' => rawurlencode( $msg ) ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    public static function connect(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'dfy_lm_connect' );
        $r = DFY_LM_Client::connect( (string) wp_unslash( $_POST['code'] ?? '' ) );
        self::back( $r['msg'], ! $r['ok'] );
    }

    public static function auto(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'dfy_lm_auto' );
        $r = DFY_LM_Client::auto_connect( true );
        self::back( $r['msg'], ! $r['ok'] );
    }

    public static function check(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'dfy_lm_check' );
        DFY_LM_Client::heartbeat();
        if ( ! DFY_LM_Client::connected() ) {
            wp_safe_redirect( admin_url( 'plugins.php' ) );
            exit;
        }
        $e = DFY_LM_Client::get()['last_error'];
        self::back( $e !== '' ? $e : 'Checked in with BuddyBoss DFY.', $e !== '' );
    }

    private static function names(): string {
        $c = DFY_LM_Client::get();
        return implode( ', ', array_map( [ 'DFY_License_Remote', 'label' ], array_keys( $c['ours'] ) ) ) ?: 'premium plugins';
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        echo '<div class="wrap"><h1>DFY Licenses</h1>';
        if ( ! empty( $_GET['dfy_msg'] ) ) echo '<div class="notice notice-success"><p>' . esc_html( wp_unslash( $_GET['dfy_msg'] ) ) . '</p></div>';
        if ( ! empty( $_GET['dfy_err'] ) ) echo '<div class="notice notice-error"><p>' . esc_html( wp_unslash( $_GET['dfy_err'] ) ) . '</p></div>';

        if ( ! DFY_LM_Client::connected() || DFY_LM_Client::is_copy() ) {
            $err = (string) get_option( 'dfy_lm_claim_error', '' );
            echo '<p>This site connects to DFY License Hub by itself once BuddyBoss DFY has added <strong>' . esc_html( home_url() ) . '</strong> there. The premium licenses are then activated automatically.</p>';
            if ( $err !== '' ) echo '<p>Last attempt: <span style="color:#d63638">' . esc_html( $err ) . '</span></p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'dfy_lm_auto' );
            echo '<input type="hidden" name="action" value="dfy_lm_auto">';
            submit_button( 'Connect now', 'primary', 'submit', false );
            echo '</form><hr><p><strong>Or</strong> paste a connection code from DFY License Hub:</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'dfy_lm_connect' );
            echo '<input type="hidden" name="action" value="dfy_lm_connect"><textarea name="code" rows="4" class="large-text code" required placeholder="DFY1.…"></textarea>';
            submit_button( 'Connect and activate licenses' );
            echo '</form></div>';
            return;
        }

        $c = DFY_LM_Client::get();
        if ( DFY_LM_Client::is_copy() ) {
            echo '<div class="notice notice-warning inline"><p>This looks like a copy of <strong>' . esc_html( $c['home'] ) . '</strong>, so DFY License Manager is not doing anything here.</p></div>';
        }
        $exp = DFY_LM_Client::expires_ts();
        if ( DFY_LM_Client::ended() ) {
            echo self::ended_box( true );
        } else
        echo '<p style="font-size:14px">' . ( $exp
            ? 'The premium licenses included with your BuddyBoss DFY project are active until <strong>' . esc_html( wp_date( get_option( 'date_format' ), $exp ) ) . '</strong>. After that date they are removed from this site; to keep receiving updates and support, buy your own licenses from each vendor and enter them in the plugin settings.'
            : 'The premium licenses included with your BuddyBoss DFY project are active. The one-year period started when this site was connected.' ) . '</p>';

        echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>Plugin</th><th>License</th><th>Details</th></tr></thead><tbody>';
        $labels = [ 'active' => 'Active', 'activate_failed' => 'Could not activate', 'replaced' => 'Using your own key', 'missing' => 'No key', 'inactive' => 'Inactive', 'removed' => 'Deactivated', 'remove_failed' => 'Deactivation failed' ];
        foreach ( $c['plugins'] as $slug => $p ) {
            printf( '<tr><td>%s</td><td>%s</td><td><small>%s</small></td></tr>', esc_html( DFY_License_Remote::label( $slug ) ), esc_html( $labels[ $p['state'] ] ?? $p['state'] ), esc_html( $p['msg'] ?? '' ) );
        }
        foreach ( DFY_LM_Vendors::installed() as $slug ) {
            if ( isset( $c['plugins'][ $slug ] ) ) continue;
            $lic = DFY_LM_Vendors::is_licensed( $slug );
            printf( '<tr><td>%s</td><td>%s</td><td><small>%s</small></td></tr>', esc_html( DFY_License_Remote::label( $slug ) ), 'Not managed', esc_html( $lic ? 'Licensed, but not by DFY License Manager.' : ( $slug === 'restrict-content-pro' && DFY_LM_Vendors::solid_package() === '' ? 'This Restrict Content Pro version (' . ( defined( 'RCP_PLUGIN_VERSION' ) ? RCP_PLUGIN_VERSION : 'old' ) . ') has no SolidWP licensing. Update it to 3.5 or later; it is then licensed at the next check-in.' : 'No license yet. It is activated at the next check-in once DFY has a key for it.' ) ) );
        }
        if ( ! $c['plugins'] && ! DFY_LM_Vendors::installed() ) echo '<tr><td colspan="3">No DFY premium plugins are active yet. Activate them, then click "Check in now".</td></tr>';
        echo '</tbody></table>';

        echo '<p>Last check-in: ' . ( $c['last_ok'] ? esc_html( human_time_diff( $c['last_ok'] ) ) . ' ago' : 'never' ) . ( $c['last_error'] ? ' <span style="color:#d63638">(' . esc_html( $c['last_error'] ) . ')</span>' : '' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        wp_nonce_field( 'dfy_lm_check' );
        echo '<input type="hidden" name="action" value="dfy_lm_check"><button class="button">Check in now</button></form>';
        echo '<p class="description" style="margin-top:16px">' . ( DFY_LM_Client::ended() ? 'DFY License Manager stays installed so BuddyBoss DFY can re-activate these licenses if you renew with us.' : 'Deactivating or deleting DFY License Manager removes these licenses from this site.' ) . '</p></div>';
    }

    const VENDOR_SITES = [
        'elementor-pro' => 'https://elementor.com/pro/', 'gravityforms' => 'https://www.gravityforms.com/', 'learndash' => 'https://www.learndash.com/',
        'wp-fusion' => 'https://wpfusion.com/', 'automatorwp-pro' => 'https://automatorwp.com/', 'wp-all-import-pro' => 'https://www.wpallimport.com/',
        'uncanny-automator-pro' => 'https://automatorplugin.com/',
    ];

    /** "Your DFY licenses have ended" message with vendor links and the DFY contact. */
    private static function ended_box( bool $page ): string {
        $c     = DFY_LM_Client::get();
        $slugs = array_keys( array_filter( $c['plugins'], function ( $p ) { return ( $p['state'] ?? '' ) === 'removed'; } ) );
        $links = [];
        foreach ( $slugs as $s ) $links[] = '<a href="' . esc_url( self::VENDOR_SITES[ $s ] ?? '#' ) . '" target="_blank" rel="noopener">' . esc_html( DFY_License_Remote::label( $s ) ) . '</a>';
        $when    = ! empty( $c['ended']['time'] ) ? wp_date( get_option( 'date_format' ), (int) $c['ended']['time'] ) : '';
        $contact = $c['contact'] !== '' ? '<a href="' . esc_url( $c['contact'] ) . '" target="_blank" rel="noopener">contact BuddyBoss DFY</a>' : 'contact BuddyBoss DFY';
        return '<div class="notice notice-warning' . ( $page ? ' inline' : ' is-dismissible dfy-lm-ended' ) . '"><p><strong>The premium licenses included with your BuddyBoss DFY project have ended' . ( $when ? ' (' . esc_html( $when ) . ')' : '' ) . '.</strong> '
            . ( $links ? implode( ', ', $links ) . ' are still installed and working, but no longer receive updates and support on this site.' : 'Your premium plugins are still installed, but no longer receive updates and support on this site.' )
            . '</p><p>To keep them updated, buy your own license from each plugin vendor' . ( $links ? ' (links above)' : '' ) . ' and enter it in the plugin\'s settings, or ' . $contact . ' for an alternative solution.'
            . ( $page ? '' : ' <a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">Details</a>' ) . '</p></div>';
    }

    /**
     * Popup for administrators 30 days (and again 7 days) before the DFY licenses end.
     * "I understand" hides it for that user until the next stage.
     */
    public static function expiry_popup(): void {
        if ( ! current_user_can( 'manage_options' ) || ! DFY_LM_Client::connected() || DFY_LM_Client::is_copy() || DFY_LM_Client::ended() ) return;
        $c   = DFY_LM_Client::get();
        $exp = DFY_LM_Client::expires_ts();
        if ( ! $exp || ! $c['ours'] ) return;
        $left = (int) ceil( ( $exp - time() ) / DAY_IN_SECONDS );
        if ( $left > 30 || $left < 1 ) return;
        $stage = $left <= 7 ? 'popup7' : 'popup30';
        if ( get_user_meta( get_current_user_id(), 'dfy_lm_popup', true ) === $stage ) return;
        $items = '';
        foreach ( array_keys( $c['ours'] ) as $s ) {
            $items .= '<li><strong>' . esc_html( DFY_License_Remote::label( $s ) ) . '</strong> — <a href="' . esc_url( self::VENDOR_SITES[ $s ] ?? '#' ) . '" target="_blank" rel="noopener">buy a license from the plugin author</a></li>';
        }
        $date    = wp_date( get_option( 'date_format' ), $exp );
        $contact = $c['contact'] !== '' ? '<a class="button" href="' . esc_url( $c['contact'] ) . '" target="_blank" rel="noopener">Contact BuddyBoss DFY</a> ' : '';
        ?>
<div id="dfy-lm-popup" role="dialog" aria-modal="true" aria-labelledby="dfy-lm-popup-title" style="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px">
  <div style="background:#fff;max-width:560px;width:100%;border-radius:8px;padding:24px 28px;box-shadow:0 10px 40px rgba(0,0,0,.3);max-height:90vh;overflow:auto">
    <h2 id="dfy-lm-popup-title" style="margin-top:0;color:#b32d2e">Your premium plugin licenses end in <?php echo (int) $left; ?> day<?php echo $left === 1 ? '' : 's'; ?></h2>
    <p style="font-size:14px">The premium plugin licenses included with your BuddyBoss DFY project will be <strong>removed from this site on <?php echo esc_html( $date ); ?></strong>. The plugins keep working, but they will no longer receive updates and support.</p>
    <ul style="list-style:disc;padding-left:20px;font-size:14px"><?php echo $items; // escaped above ?></ul>
    <p style="font-size:14px">To keep them updated, purchase your own license from each plugin author and enter it in the plugin's settings, or contact us for an alternative solution.</p>
    <p style="text-align:right;margin-bottom:0"><?php echo $contact; // escaped above ?><button type="button" class="button button-primary" id="dfy-lm-popup-ok">I understand</button></p>
  </div>
</div>
<script>
(function(){var p=document.getElementById('dfy-lm-popup');function close(){p.remove();if(window.jQuery){jQuery.post(ajaxurl,{action:'dfy_lm_popup_seen',stage:<?php echo wp_json_encode( $stage ); ?>,_ajax_nonce:<?php echo wp_json_encode( wp_create_nonce( 'dfy_lm_popup' ) ); ?>});}}
document.getElementById('dfy-lm-popup-ok').addEventListener('click',close);document.addEventListener('keydown',function(e){if(e.key==='Escape'&&document.body.contains(p))close();});document.getElementById('dfy-lm-popup-ok').focus();})();
</script>
        <?php
    }

    public static function popup_seen(): void {
        check_ajax_referer( 'dfy_lm_popup' );
        if ( current_user_can( 'manage_options' ) ) update_user_meta( get_current_user_id(), 'dfy_lm_popup', sanitize_key( $_POST['stage'] ?? '' ) );
        wp_die();
    }

    /** Reminder for administrators 30 and 7 days before the licenses end, and once they have ended. */
    public static function expiry_notice(): void {
        if ( ! current_user_can( 'manage_options' ) || DFY_LM_Client::is_copy() ) return;
        if ( DFY_LM_Client::ended() ) {
            if ( ( $_GET['page'] ?? '' ) === self::PAGE || get_user_meta( get_current_user_id(), 'dfy_lm_dismissed', true ) === 'ended' ) return;
            echo self::ended_box( false );
            printf( "<script>jQuery(document).on('click','.dfy-lm-ended .notice-dismiss',function(){jQuery.post(ajaxurl,{action:'dfy_lm_dismiss',stage:'ended',_ajax_nonce:'%s'});});</script>", esc_js( wp_create_nonce( 'dfy_lm_dismiss' ) ) );
            return;
        }
        if ( ! DFY_LM_Client::connected() ) return;
        $exp = DFY_LM_Client::expires_ts();
        if ( ! $exp ) return;
        $left = (int) ceil( ( $exp - time() ) / DAY_IN_SECONDS );
        if ( $left > 30 || $left < 0 ) return;
        $stage = $left <= 7 ? '7' : '30';
        if ( get_user_meta( get_current_user_id(), 'dfy_lm_dismissed', true ) === $stage ) return;
        printf(
            '<div class="notice notice-warning is-dismissible dfy-lm-notice"><p><strong>Your BuddyBoss DFY premium licenses end on %s.</strong> %s will stop receiving updates and support on this site. Buy your own licenses from each vendor before then to keep them. <a href="%s">Details</a></p></div>',
            esc_html( wp_date( get_option( 'date_format' ), $exp ) ), esc_html( self::names() ), esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) )
        );
        printf( "<script>jQuery(document).on('click','.dfy-lm-notice .notice-dismiss',function(){jQuery.post(ajaxurl,{action:'dfy_lm_dismiss',stage:'%s',_ajax_nonce:'%s'});});</script>", esc_js( $stage ), esc_js( wp_create_nonce( 'dfy_lm_dismiss' ) ) );
    }

    public static function dismiss(): void {
        check_ajax_referer( 'dfy_lm_dismiss' );
        update_user_meta( get_current_user_id(), 'dfy_lm_dismissed', sanitize_key( $_POST['stage'] ?? '' ) );
        wp_die();
    }

    public static function row_meta( array $meta, string $file ): array {
        if ( $file === plugin_basename( DFY_LM_FILE ) && DFY_LM_Client::connected() && DFY_LM_Client::get()['ours'] ) {
            $meta[] = '<strong>Deactivating this plugin removes the DFY premium licenses (' . esc_html( self::names() ) . ').</strong>';
        }
        return $meta;
    }

    public static function deactivate_confirm(): void {
        if ( ! DFY_LM_Client::connected() || ! DFY_LM_Client::get()['ours'] ) return;
        $slug = dirname( plugin_basename( DFY_LM_FILE ) );
        $msg  = 'Deactivating DFY License Manager removes the premium licenses provided by BuddyBoss DFY (' . self::names() . ') from this site. Those plugins will stop receiving updates. Continue?';
        printf( "<script>jQuery(function($){ $('tr[data-slug=\"%s\"] .deactivate a').on('click',function(e){ if(!confirm(%s)) e.preventDefault(); }); });</script>", esc_js( $slug ), wp_json_encode( $msg ) );
    }
}
