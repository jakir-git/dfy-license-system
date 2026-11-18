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

    /** Key from the WordPress salts (used when DFY_LICENSE_ENC_KEY is not set, and to read older data). */
    private static function salt_key(): string {
        $seed = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
        if ( $seed === '' && function_exists( 'wp_salt' ) ) $seed = wp_salt( 'secure_auth' );
        return hash( 'sha256', 'dfy-license|' . $seed, true );
    }

    /** True when a permanent key is set in wp-config.php. */
    public static function has_own_key(): bool { return defined( 'DFY_LICENSE_ENC_KEY' ) && (string) DFY_LICENSE_ENC_KEY !== ''; }

    private static function key(): string {
        return self::has_own_key() ? hash( 'sha256', 'dfy-license|' . DFY_LICENSE_ENC_KEY, true ) : self::salt_key();
    }

    /** Keys to try when reading: the current one, then the salt key (data written before DFY_LICENSE_ENC_KEY was added). */
    private static function read_keys(): array {
        return self::has_own_key() ? [ self::key(), self::salt_key() ] : [ self::key() ];
    }

    /** Fingerprint of the current key (to know when stored data must be re-encrypted). */
    public static function key_id(): string { return substr( hash( 'sha256', 'id|' . self::key() ), 0, 16 ); }

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
        foreach ( self::read_keys() as $k ) {
            $out = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
            if ( $out !== false ) return $out;
        }
        return '';
    }

    public static function enc_json( array $data ): string { return $data ? self::encrypt( wp_json_encode( $data ) ) : ''; }

    /** Re-encrypt a value with the current key (no-op for empty / unreadable values). */
    public static function reencrypt( string $blob ): string {
        if ( $blob === '' ) return '';
        $p = self::decrypt( $blob );
        return $p === '' ? $blob : self::encrypt( $p );
    }

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
