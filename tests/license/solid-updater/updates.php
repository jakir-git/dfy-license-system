<?php
class Ithemes_Updater_Updates {
    static function process_server_response( $response, $cached = false ) {
        $k = (array) get_site_option( 'ithemes-updater-keys', [] );
        foreach ( (array) ( $response['packages'] ?? [] ) as $p => $d ) { if ( isset( $d['key'] ) ) $k[ $p ] = $d['key']; elseif ( ( $d['status'] ?? '' ) === 'inactive' ) $k[ $p ] = ''; }
        update_site_option( 'ithemes-updater-keys', $k );
    }
}
