<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Customer reminder email: an email-client-safe HTML version (tables + inline
 * styles, a "bulletproof" button that works in Outlook and Gmail) and a matching
 * plain-text version for clients that do not show HTML.
 */
class DFY_LH_Email {

    const ACCENT = '#E8590C'; // call-to-action orange
    const DARK   = '#111827';
    const MUTED  = '#6B7280';

    /**
     * @param array{name:string,site_url:string,site_name?:string,end_date:string,left:int,plugins:array<int,array{label:string,url:string}>,contact:string,test_note:string} $d
     * @return array{subject:string,html:string,text:string}
     */
    public static function reminder( array $d ): array {
        $d['site_name'] = (string) ( $d['site_name'] ?? '' );
        $where   = self::domain( $d['site_url'] ) ?: $d['site_name'];
        $subject = 'Your premium plugin licenses' . ( $where !== '' ? ' for ' . $where : '' ) . ' end on ' . $d['end_date'];
        return [ 'subject' => $subject, 'html' => self::html( $d ), 'text' => self::text( $d ) ];
    }

    private static function e( string $s ): string { return esc_html( $s ); }

    private static function domain( string $url ): string { return $url === '' ? '' : (string) preg_replace( '~^https?://~', '', rtrim( $url, '/' ) ); }

    private static function html( array $d ): string {
        $a = self::ACCENT; $dark = self::DARK; $muted = self::MUTED;
        $font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $hi   = $d['name'] !== '' ? 'Hi ' . self::e( $d['name'] ) . ',' : 'Hi there,';
        $days = $d['left'] > 0 ? $d['left'] . ' day' . ( $d['left'] === 1 ? '' : 's' ) . ' left' : '';
        $pre  = 'Your BuddyBoss Done-For-You plugin licenses end on ' . $d['end_date'] . '. Here is how to keep your plugins updated.';

        $rows = '';
        foreach ( $d['plugins'] as $i => $p ) {
            $border = $i === count( $d['plugins'] ) - 1 ? '' : "border-bottom:1px solid #EEF0F3;";
            $rows .= '<tr>'
                . '<td style="padding:14px 0;' . $border . 'font:600 15px/1.4 ' . $font . ';color:' . $dark . ';">' . self::e( $p['label'] ) . '</td>'
                . '<td align="right" style="padding:14px 0;' . $border . 'font:500 14px/1.4 ' . $font . ';">'
                . ( $p['url'] !== '' ? '<a href="' . esc_url( $p['url'] ) . '" style="color:' . $a . ';text-decoration:none;">Get a license&nbsp;&rarr;</a>' : '' )
                . '</td></tr>';
        }

        $test = $d['test_note'] !== ''
            ? '<tr><td style="padding:12px 24px;background:#FEF3C7;border:1px solid #FCD34D;border-radius:8px;font:600 13px/1.5 ' . $font . ';color:#92400E;">' . self::e( $d['test_note'] ) . '</td></tr><tr><td style="height:16px;line-height:16px;">&nbsp;</td></tr>'
            : '';

        $dom  = self::domain( $d['site_url'] );
        $site = ( $dom !== '' || $d['site_name'] !== '' )
            ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;border-top:1px solid #EEF0F3;width:100%;"><tr><td style="padding-top:12px;font:600 12px/1 ' . $font . ';color:' . $muted . ';text-transform:uppercase;letter-spacing:1px;">' . ( $dom !== '' ? 'Website' : 'Project' ) . '</td></tr><tr><td style="padding-top:6px;font:700 16px/1.4 ' . $font . ';color:' . $dark . ';">'
              . ( $dom !== '' ? '<a href="' . esc_url( $d['site_url'] ) . '" style="color:' . $dark . ';text-decoration:underline;text-decoration-color:#D6D9DD;">' . self::e( $dom ) . '</a>' . ( $d['site_name'] !== '' && strcasecmp( $d['site_name'], $dom ) !== 0 ? '<span style="font-weight:400;color:' . $muted . ';"> · ' . self::e( $d['site_name'] ) . '</span>' : '' ) : self::e( $d['site_name'] ) )
              . '</td></tr></table>'
            : '';

        $cta = $d['contact'] !== '' ? '
          <tr><td style="padding:32px 40px 8px;" class="px">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FFF7ED;border-radius:12px;">
              <tr><td align="center" style="padding:32px 24px;">
                <div style="font:700 20px/1.3 ' . $font . ';color:' . $dark . ';">Not sure which option is right for you?</div>
                <div style="margin:10px auto 24px;max-width:420px;font:400 15px/1.6 ' . $font . ';color:#374151;">Book a short call with the BuddyBoss team. We\'ll walk you through your options and help you find the most cost-effective way to keep your site fully supported.</div>
                <!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="' . esc_url( $d['contact'] ) . '" style="height:54px;v-text-anchor:middle;width:280px;" arcsize="16%" stroke="f" fillcolor="' . $a . '"><center style="color:#ffffff;font-family:Arial,sans-serif;font-size:17px;font-weight:bold;">Book your call &rarr;</center></v:roundrect><![endif]-->
                <!--[if !mso]><!-->
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>
                  <td align="center" bgcolor="' . $a . '" style="border-radius:10px;box-shadow:0 4px 14px rgba(232,89,12,.35);">
                    <a href="' . esc_url( $d['contact'] ) . '" target="_blank" style="display:inline-block;padding:17px 40px;font:700 17px/1 ' . $font . ';color:#ffffff;text-decoration:none;border-radius:10px;">Book your call&nbsp;&rarr;</a>
                  </td></tr></table>
                <!--<![endif]-->
                <div style="margin-top:14px;font:400 13px/1.5 ' . $font . ';color:' . $muted . ';">Pick a time that suits you — it only takes a minute.</div>
              </td></tr>
            </table>
          </td></tr>' : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting"><title>' . self::e( 'Your premium plugin licenses end on ' . $d['end_date'] ) . '</title>
<style>@media (max-width:600px){.px{padding-left:24px!important;padding-right:24px!important}.h1{font-size:24px!important}}</style></head>
<body style="margin:0;padding:0;background:#F3F4F6;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . self::e( $pre ) . '</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F3F4F6;">
  <tr><td align="center" style="padding:32px 12px;">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
      ' . $test . '
      <tr><td style="background:' . $dark . ';border-radius:14px 14px 0 0;padding:22px 40px;" class="px">
        <span style="font:800 20px/1 ' . $font . ';color:#ffffff;letter-spacing:-.2px;">BuddyBoss</span>
        <span style="font:600 12px/1 ' . $font . ';color:' . $a . ';text-transform:uppercase;letter-spacing:1.2px;margin-left:8px;">Done-For-You</span>
      </td></tr>
      <tr><td style="background:#ffffff;border-radius:0 0 14px 14px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr><td style="padding:40px 40px 0;" class="px">
            <div style="font:400 16px/1.6 ' . $font . ';color:#374151;">' . $hi . '</div>
            <h1 class="h1" style="margin:12px 0 0;font:800 28px/1.25 ' . $font . ';color:' . $dark . ';">Your premium plugin licenses are ending soon</h1>
            <p style="margin:14px 0 0;font:400 16px/1.65 ' . $font . ';color:#374151;">The premium plugin licenses included with your BuddyBoss Done-For-You project' . ( $dom !== '' ? ' for <a href="' . esc_url( $d['site_url'] ) . '" style="color:' . $dark . ';font-weight:600;">' . self::e( $dom ) . '</a>' : ( $d['site_name'] !== '' ? ' <strong>' . self::e( $d['site_name'] ) . '</strong>' : '' ) ) . ' end soon. Your plugins will <strong>keep working</strong>, but after that date they will no longer receive <strong>updates, security fixes or support</strong>.</p>
          </td></tr>
          <tr><td style="padding:28px 40px 0;" class="px">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #E5E7EB;border-left:5px solid ' . $a . ';border-radius:10px;">
              <tr><td style="padding:20px 24px;">
                <div style="font:600 12px/1 ' . $font . ';color:' . $muted . ';text-transform:uppercase;letter-spacing:1px;">Licenses end on</div>
                <div style="margin-top:8px;font:800 26px/1.2 ' . $font . ';color:' . $dark . ';">' . self::e( $d['end_date'] ) . ( $days ? ' <span style="display:inline-block;vertical-align:middle;margin-left:8px;padding:5px 10px;background:#FFEDD5;border-radius:999px;font:700 13px/1 ' . $font . ';color:#9A3412;">' . self::e( $days ) . '</span>' : '' ) . '</div>
                ' . $site . '
              </td></tr>
            </table>
          </td></tr>
          <tr><td style="padding:32px 40px 0;" class="px">
            <div style="font:700 17px/1.3 ' . $font . ';color:' . $dark . ';">Keep your plugins updated</div>
            <div style="margin-top:6px;font:400 15px/1.6 ' . $font . ';color:#374151;">Purchase your own license from each plugin author and enter it in the plugin\'s settings — your site keeps running without any interruption.</div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px;">' . $rows . '</table>
          </td></tr>
          ' . $cta . '
          <tr><td style="padding:28px 40px 40px;" class="px">
            <div style="font:400 15px/1.6 ' . $font . ';color:#374151;">Thank you for building your community with BuddyBoss.<br><strong style="color:' . $dark . ';">The BuddyBoss Done-For-You team</strong></div>
          </td></tr>
        </table>
      </td></tr>
      <tr><td align="center" style="padding:20px 24px;font:400 12px/1.6 ' . $font . ';color:#9CA3AF;">You are receiving this because BuddyBoss built ' . ( $dom !== '' ? self::e( $dom ) : ( $d['site_name'] !== '' ? self::e( $d['site_name'] ) : 'your site' ) ) . ' as a Done-For-You project, which included these licenses for one year.</td></tr>
    </table>
  </td></tr>
</table>
</body></html>';
    }

    private static function text( array $d ): string {
        $lines = array_map( function ( $p ) { return '- ' . $p['label'] . ': ' . $p['url']; }, $d['plugins'] );
        return ( $d['test_note'] !== '' ? $d['test_note'] . "\n\n" : '' )
            . ( $d['name'] !== '' ? "Hi {$d['name']},\n\n" : "Hi there,\n\n" )
            . 'The premium plugin licenses included with your BuddyBoss Done-For-You project' . ( $d['site_url'] !== '' ? ' for ' . self::domain( $d['site_url'] ) : ( ( $d['site_name'] ?? '' ) !== '' ? ' ' . $d['site_name'] : '' ) )
            . ' end on ' . $d['end_date'] . ( $d['left'] > 0 ? " — in {$d['left']} days" : '' ) . ".\n\n"
            . ( $d['site_url'] !== '' ? "Website: {$d['site_url']}\n\n" : '' )
            . "Your plugins keep working, but after that date they no longer receive updates, security fixes or support.\n\n"
            . "To keep them updated, purchase your own license from each plugin author and enter it in the plugin's settings:\n\n" . implode( "\n", $lines ) . "\n\n"
            . ( $d['contact'] !== '' ? "Not sure which option is right for you? Book a short call with the BuddyBoss team:\n{$d['contact']}\n\n" : '' )
            . "Thank you for building your community with BuddyBoss.\nThe BuddyBoss Done-For-You team";
    }
}
