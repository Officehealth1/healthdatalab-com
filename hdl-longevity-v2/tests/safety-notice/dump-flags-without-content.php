<?php
/** v0.48.67 (R3) — HDLV2_Safety_Screen::map_flags() with HDLV2_Safety_Content NOT loaded, as JSON, for scenario-safety-notice.php. */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../includes/sprint-1/class-hdlv2-safety-screen.php';
$all = array( 'symptoms' => array_keys( HDLV2_Safety_Screen::SYMPTOM_FLAGS ), 'mh' => array_keys( HDLV2_Safety_Screen::MH_FLAGS ) );
echo json_encode( array( 'content_loaded' => class_exists( 'HDLV2_Safety_Content', false ), 'flags' => HDLV2_Safety_Screen::map_flags( $all ) ) );
