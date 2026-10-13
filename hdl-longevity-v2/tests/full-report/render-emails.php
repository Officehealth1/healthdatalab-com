<?php
/**
 * Renders the three client emails the rename touches, plus the customer-facing
 * lines of the two widget scripts, and prints their sha256 as JSON.
 * Used by scenario-email-snapshot.php and to write fixtures/before.json once.
 *
 * Run:  php render-emails.php            → JSON on stdout
 *       php render-emails.php --html     → the rendered HTML too (for eyeballing)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
require __DIR__ . '/stubs.php';

$ROOT = __DIR__ . '/../../';
require $ROOT . 'includes/sprint-2/class-hdlv2-email-templates.php';
require $ROOT . 'includes/sprint-2/class-hdlv2-rate-calculator.php';
require $ROOT . 'includes/sprint-2/class-hdlv2-stage1-commentary.php';
require $ROOT . 'includes/security/class-hdlv2-rate-limiter.php';
require $ROOT . 'includes/sprint-1/class-hdlv2-widget-config.php';
if ( file_exists( $ROOT . 'includes/sprint-1/class-hdlv2-offer.php' ) ) {
    require $ROOT . 'includes/sprint-1/class-hdlv2-offer.php';
}

global $wpdb;
$wpdb = new FakeWpdb();
$wpdb->config = (object) array(
    'practitioner_user_id' => 122, 'practitioner_name' => 'Matthew D\'haemer',
    'logo_url' => 'https://stby.example.test/logo.png', 'logo_shape' => 'wordmark',
);
$GLOBALS['mails'] = array();

// (a) the Confirm email (no expiry line: the expiry text depends on the clock)
HDLV2_Widget_Config::send_invite_email( 122, 'Kim Test', 'kim@example.test', 'https://stby.example.test/assessment/?token=abc', '' );
// (b) the WHY-release email
HDLV2_Email_Templates::why_gate_released( array( 'client_name' => 'Kim Test', 'client_email' => 'kim@example.test', 'form_url' => 'https://stby.example.test/assessment/?token=abc', 'practitioner_id' => 122 ) );
$mails = $GLOBALS['mails'];
// (c) the fallback Stage 1 results email (returned, not sent)
$stage1 = HDLV2_Email_Templates::stage1_results( array(
    'client_name' => 'Kim Test', 'client_email' => 'kim@example.test', 'rate' => 1.04, 'bio_age' => 57.2, 'age' => 55,
    'gauge_url' => 'https://stby.example.test/g.png', 'token_url' => 'https://stby.example.test/assessment/?token=abc',
    'rate_message' => 'Average', 'practitioner_id' => 122,
) );

// Subjects carry the send time; blank it so the hash is stable.
$clock = function ( $s ) { return preg_replace( '#\d{2}/\d{2}/\d{4} \d{2}:\d{2}#', 'DD/MM/YYYY HH:MM', (string) $s ); };

// Customer-facing script lines: every non-comment line naming a stage or the Full Report.
$lines = function ( $file ) use ( $ROOT ) {
    $out = array();
    foreach ( file( $ROOT . $file ) as $l ) {
        $t = ltrim( $l );
        if ( '' === $t || 0 === strpos( $t, '//' ) || 0 === strpos( $t, '*' ) || 0 === strpos( $t, '/*' ) ) continue;
        if ( preg_match( '/Stage ?(2|3)|Full Report/', $l ) ) $out[] = trim( $l );
    }
    return implode( "\n", $out );
};

$doc = array(
    'invite_email'    => array( 'subject' => $mails[0][1] ?? '', 'html' => $mails[0][2] ?? '' ),
    'why_released'    => array( 'subject' => $clock( $mails[1][1] ?? '' ), 'html' => $mails[1][2] ?? '' ),
    'stage1_results'  => array( 'subject' => '', 'html' => (string) $stage1 ),
    'widget_lines'    => array( 'subject' => '', 'html' => $lines( 'widget/hdl-lead-magnet.js' ) ),
    'form_lines'      => array( 'subject' => '', 'html' => $lines( 'assets/js/hdlv2-staged-form.js' ) ),
);
$out = array();
foreach ( $doc as $k => $v ) {
    $out[ $k ] = hash( 'sha256', $v['subject'] . "\n" . $v['html'] );
}
if ( in_array( '--html', $argv, true ) ) {
    echo json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
    exit( 0 );
}
echo json_encode( $out, JSON_PRETTY_PRINT ), "\n";
