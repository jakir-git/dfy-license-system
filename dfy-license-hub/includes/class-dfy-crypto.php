<?php
/**
 * DFY_Crypto — encrypts secrets at rest (license keys, site secrets).
 *
 * The key comes from DFY_LICENSE_ENC_KEY in wp-config.php when defined
 * (recommended: values survive a salt rotation), otherwise from the site's
 * AUTH_KEY / SECURE_AUTH_SALT.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'DFY_Crypto' ) ) :

class DFY_Crypto {

    private static function key(): string {
        $seed = defined( 'DFY_LICENSE_ENC_KEY' ) ? DFY_LICENSE_ENC_KEY
            : ( ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) );
        if ( $seed === '' && function_exists( 'wp_salt' ) ) $seed = wp_salt( 'secure_auth' );
        return hash( 'sha256', 'dfy-license|' . $seed, true );
    }

    public static function encrypt( string $plain ): string {
        if ( $plain === '' ) return '';
        $iv  = random_bytes( 12 );
        $tag = '';
        $ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
        return 'v1:' . base64_encode( $iv . $tag . $ct );
    }

    public static function decrypt( string $blob ): string {
        if ( strpos( $blob, 'v1:' ) !== 0 ) return '';
        $raw = base64_decode( substr( $blob, 3 ), true );
        if ( $raw === false || strlen( $raw ) < 29 ) return '';
        $out = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
        return $out === false ? '' : $out;
    }

    public static function enc_json( array $data ): string { return $data ? self::encrypt( wp_json_encode( $data ) ) : ''; }

    public static function dec_json( string $blob ): array {
        $d = json_decode( self::decrypt( $blob ), true );
        return is_array( $d ) ? $d : [];
    }

    /** Request signature shared by hub and site. */
    public static function sign( string $secret, string $time, string $body ): string {
        return hash_hmac( 'sha256', $time . "\n" . $body, $secret );
    }
}

endif;
