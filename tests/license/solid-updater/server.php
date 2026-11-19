<?php
class Ithemes_Updater_Server {
    /** Same URL/query/body shape as the real request(); answered by the test HTTP router. */
    static function request( $action, $query = [], $data = [] ) {
        $data['iterations'] = 8;
        $q = array_merge( [ 'wp' => $GLOBALS['wp_version'], 'site' => preg_replace( '|/$|', '', preg_replace( '/^https/', 'http', network_home_url( '', 'http' ) ) ), 'timestamp' => time(), 'auth_version' => '2' ], $query );
        $r = wp_remote_post( 'https://api.ithemes.com/updater/' . $action . '/?' . http_build_query( $q, '', '&' ), [ 'body' => [ 'request' => json_encode( $data ) ] ] );
        return json_decode( wp_remote_retrieve_body( $r ), true );
    }
}
