<?php
/** Prints HDLV2_Safety_Content::public_payload() as JSON, for test-widget-notice.mjs. */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../includes/sprint-1/class-hdlv2-safety-screen.php';
require __DIR__ . '/../../includes/sprint-1/class-hdlv2-safety-content.php';
echo json_encode( HDLV2_Safety_Content::public_payload() );
