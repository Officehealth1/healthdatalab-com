<?php
/**
 * Plan 14 part B (questions to ask now): the shared fake world. It reuses plan 13's WP stubs, fake $wpdb and
 * stubbed AI (tests/health-analysis/stubs.php: practitioner 3 owns form 10 and consultation 55, practitioner
 * 4, client 9, admin 1; wp_remote_post answers from $GLOBALS['ai_replies'] and records $GLOBALS['ai_calls'])
 * and loads the REAL classes. Not a test command.
 */
require dirname( __DIR__ ) . '/health-analysis/stubs.php';
ha_load( array(
    'security/class-hdlv2-idempotency.php', 'sprint-2/class-hdlv2-ai-service.php', 'sprint-2/class-hdlv2-email-templates.php',
    'class-hdlv2-report-names.php', 'sprint-2c/class-hdlv2-health-analysis.php', 'sprint-2c/class-hdlv2-live-questions.php', 'sprint-2c/class-hdlv2-live-listen.php',
) );
function lq_switch( $v ) { if ( null === $v ) unset( $GLOBALS['options']['hdlv2_ff_live_questions'] ); else $GLOBALS['options']['hdlv2_ff_live_questions'] = $v; }
/** The prompt text the model was sent by the newest AI call. */
function lq_sent() { $c = end( $GLOBALS['ai_calls'] ); return (string) ( $c['messages'][0]['content'] ?? '' ); }
