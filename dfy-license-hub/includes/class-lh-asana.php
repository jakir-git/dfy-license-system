<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reads the customer's name and email from a DFY Asana project (optional; needs a
 * Personal Access Token on the Settings tab). Only these fields are read and kept:
 * the "Customer First Name", "Customer Last Name" and "CONTACT EMAIL" fields on the
 * project's tasks (DFY projects keep them on "WEB PROJECT SCOPE"), and the project name.
 */
class DFY_LH_Asana {

    const API = 'https://app.asana.com/api/1.0';

    /** Project id from any Asana project/task link. */
    public static function project_gid( string $url ): string {
        if ( strpos( $url, 'app.asana.com' ) === false ) return '';
        if ( preg_match( '~/project/(\d+)~', $url, $p ) ) return $p[1];   // /1/<ws>/project/<gid>/…
        if ( preg_match( '~app\.asana\.com/0/(\d+)~', $url, $p ) ) return $p[1]; // /0/<gid>/…
        return '';
    }

    private static function get( string $path ) {
        $token = DFY_LH_Store::asana_token();
        if ( $token === '' ) return new WP_Error( 'dfy_asana', 'No Asana token saved (Settings tab).' );
        $res = wp_remote_get( self::API . $path, [ 'timeout' => 20, 'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ] ] );
        if ( is_wp_error( $res ) ) return $res;
        $code = (int) wp_remote_retrieve_response_code( $res );
        $data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        if ( $code >= 300 ) return new WP_Error( 'dfy_asana', 'Asana: ' . ( $data['errors'][0]['message'] ?? "HTTP {$code}" ) );
        return is_array( $data ) ? $data : [];
    }

    /** @return array{name:string,email:string,project:string}|WP_Error */
    public static function customer( string $link ) {
        $gid = self::project_gid( $link );
        if ( $gid === '' ) return new WP_Error( 'dfy_asana', 'Not an Asana project link.' );
        $p = self::get( '/projects/' . rawurlencode( $gid ) . '?opt_fields=name' );
        if ( is_wp_error( $p ) ) return $p;
        $project = (string) ( $p['data']['name'] ?? '' );

        $first = $last = $email = '';
        $t = self::get( '/projects/' . rawurlencode( $gid ) . '/tasks?limit=100&opt_fields=name,custom_fields.name,custom_fields.display_value' );
        if ( is_wp_error( $t ) ) return $t;
        foreach ( (array) ( $t['data'] ?? [] ) as $task ) {
            foreach ( (array) ( $task['custom_fields'] ?? [] ) as $f ) {
                $n = strtolower( trim( (string) ( $f['name'] ?? '' ) ) );
                $v = trim( (string) ( $f['display_value'] ?? '' ) );
                if ( $v === '' ) continue;
                if ( $email === '' && strpos( $n, 'email' ) !== false && is_email( $v ) ) $email = $v;
                if ( $first === '' && $n === 'customer first name' ) $first = $v;
                if ( $last === '' && $n === 'customer last name' ) $last = $v;
            }
            if ( $email !== '' && $first !== '' ) break;
        }
        $name = trim( "$first $last" );
        if ( $name === '' && strpos( $project, ' - ' ) !== false ) $name = trim( substr( $project, strrpos( $project, ' - ' ) + 3 ) ); // e.g. "Demo.Com - Charlie Example"
        return [ 'name' => sanitize_text_field( $name ), 'email' => sanitize_email( $email ), 'project' => sanitize_text_field( $project ) ];
    }
}
