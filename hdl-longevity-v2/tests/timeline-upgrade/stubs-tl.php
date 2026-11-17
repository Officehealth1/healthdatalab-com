<?php
/**
 * Plan 3 (v0.48.82) — the Timeline upgrade. Shared harness for the scenario-* files here: the
 * full-report stubs (WordPress functions, FakeWpdb, check()) plus a database that also holds
 * Full Report tickets with their Timeline stamp, client records and report rows, and answers the
 * queries the ticket route, the upgrade class and the Timeline page make.
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
date_default_timezone_set( 'UTC' );
require_once __DIR__ . '/../full-report/stubs.php';
if ( ! defined( 'HDL_STAGE1_TICKET_KEY' ) ) {
    define( 'HDL_STAGE1_TICKET_KEY', 'k-test-key' );
}

// The site's time zone: London (BST in summer, GMT in winter). The offer ends at the end of a London day.
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function is_page( $slug = '' ) { return false; }
function get_post() { return null; }
function has_shortcode( $c, $t ) { return false; }
function is_admin() { return false; }
function wp_enqueue_script() {}
function wp_enqueue_style() {}
function wp_add_inline_script() { return true; }

class TlWpdb extends FakeWpdb {
    public $progress_rows = array(); // (object) form_progress rows
    public $report_rows   = array(); // progress id => list of (object) { id, report_type, report_content }
    public $stamps        = array(); // every stamp UPDATE that changed a row: "id=ref"
    private $next_ticket = 900;

    /** A ticket row already on file. */
    public function seed_ticket( $over ) {
        $t = (object) array_merge( array(
            'id' => ++$this->next_ticket, 'practitioner_id' => 122, 'token' => bin2hex( random_bytes( 32 ) ),
            'client_email' => 'buyer@example.test', 'client_name' => 'Buyer', 'status' => 'completed',
            'expires_at' => '2030-01-01 00:00:00', 'source' => 'full_report', 'external_ref' => 'cs_test_' . $this->next_ticket,
            'timeline_paid_ref' => null,
        ), $over );
        $this->tickets[ $t->external_ref ] = $t;
        return $t;
    }
    public function insert( $table, $data, $format = null ) {
        $r = parent::insert( $table, $data, $format );
        if ( $r && isset( $data['external_ref'] ) ) {
            $t = $this->tickets[ $data['external_ref'] ];
            $t->id = $this->insert_id;
            $t->status = $t->status ?? 'pending';
            $t->practitioner_id = $data['practitioner_id'];
            if ( ! property_exists( $t, 'timeline_paid_ref' ) ) {
                $t->timeline_paid_ref = null;
            }
        }
        return $r;
    }
    private function ticket_by_id( $id ) {
        foreach ( $this->tickets as $t ) {
            if ( (int) ( $t->id ?? 0 ) === (int) $id ) return $t;
        }
        return null;
    }
    private function live_tickets( $email ) {
        $out = array();
        foreach ( $this->tickets as $t ) {
            if ( strtolower( $t->client_email ) === strtolower( $email ) && 'full_report' === $t->source && null !== $t->external_ref && 'revoked' !== $t->status ) {
                $out[] = $t;
            }
        }
        usort( $out, function ( $a, $b ) { return (int) $b->id <=> (int) $a->id; } );
        return $out;
    }
    public function get_results( $sql ) {
        $this->queries[] = $sql;
        if ( false !== strpos( $sql, 'hdlv2_widget_invites' ) && false !== strpos( $sql, 'timeline_paid_ref' ) ) {
            foreach ( array( 'LOWER(client_email) =', "source = 'full_report'", 'external_ref IS NOT NULL', "status <> 'revoked'" ) as $clause ) {
                if ( false === strpos( $sql, $clause ) ) return array();
            }
            return preg_match( "/LOWER\(client_email\) = '([^']*)'/", $sql, $m ) ? $this->live_tickets( $m[1] ) : array();
        }
        return array();
    }
    public function get_row( $sql ) {
        if ( false !== strpos( $sql, 'hdlv2_form_progress' ) ) {
            $this->queries[] = $sql;
            if ( preg_match( "/token = '([a-f0-9]{64})'/", $sql, $m ) ) {
                foreach ( $this->progress_rows as $r ) {
                    if ( $r->token === $m[1] && empty( $r->deleted_at ) ) return $r;
                }
                return null;
            }
            if ( preg_match( "/LOWER\(client_email\) = '([^']*)'/", $sql, $m ) ) {
                $hit = null;
                foreach ( $this->progress_rows as $r ) {
                    if ( strtolower( $r->client_email ) === $m[1] && empty( $r->deleted_at ) && ( ! $hit || $r->id > $hit->id ) ) $hit = $r;
                }
                return $hit;
            }
            return null;
        }
        if ( false !== strpos( $sql, 'hdlv2_reports' ) ) {
            $this->queries[] = $sql;
            $rows = preg_match( '/form_progress_id = (\d+)/', $sql, $m ) ? ( $this->report_rows[ (int) $m[1] ] ?? array() ) : array();
            usort( $rows, function ( $a, $b ) { return ( 'final' === $b->report_type ) <=> ( 'final' === $a->report_type ) ?: $b->id <=> $a->id; } );
            return $rows[0] ?? null;
        }
        if ( false !== strpos( $sql, 'hdlv2_widget_invites' ) && preg_match( "/external_ref = '([^']*)'/", $sql, $m ) ) {
            $this->queries[] = $sql;
            return $this->tickets[ $m[1] ] ?? null;
        }
        return parent::get_row( $sql );
    }
    public function get_var( $sql ) {
        if ( false !== strpos( $sql, 'hdlv2_widget_invites' ) && preg_match( '/SELECT timeline_paid_ref .*WHERE id = (\d+)/s', $sql, $m ) ) {
            $this->queries[] = $sql;
            $t = $this->ticket_by_id( $m[1] );
            return $t ? $t->timeline_paid_ref : null;
        }
        // HDLV2_Healthspan_Timeline::paid(): a live Full Report ticket for the e-mail.
        if ( false !== strpos( $sql, 'hdlv2_widget_invites' ) && false !== strpos( $sql, 'SELECT 1' ) ) {
            $this->queries[] = $sql;
            return preg_match( "/LOWER\(client_email\) = '([^']*)'/", $sql, $m ) && $this->live_tickets( $m[1] ) ? '1' : null;
        }
        return parent::get_var( $sql );
    }
    public function query( $sql ) {
        if ( preg_match( "/UPDATE \S*hdlv2_widget_invites\s+SET timeline_paid_ref = '([^']*)'\s+WHERE id = (\d+) AND \(timeline_paid_ref IS NULL OR timeline_paid_ref = ''\)/", $sql, $m ) ) {
            $this->queries[] = $sql;
            $t = $this->ticket_by_id( $m[2] );
            if ( ! $t || ( null !== $t->timeline_paid_ref && '' !== $t->timeline_paid_ref ) ) return 0;
            $t->timeline_paid_ref = $m[1];
            $this->stamps[] = $m[2] . '=' . $m[1];
            return 1;
        }
        return parent::query( $sql );
    }
}

/** One client record; the form sent at $sent (site time, 'Y-m-d H:i:s') or null. */
function tl_progress( $id, $email, $sent = null, $prac = 122 ) {
    $scores = array_fill_keys( array( 'physicalActivity', 'sleepDuration', 'sleepQuality', 'stressLevels', 'socialConnections', 'dietQuality', 'alcoholConsumption', 'smokingStatus', 'cognitiveActivity', 'sunlightExposure', 'supplementIntake', 'dailyHydration' ), 4 );
    return (object) array(
        'id' => $id, 'client_user_id' => 500 + $id, 'practitioner_user_id' => $prac, 'client_name' => 'Kim Lane',
        'client_email' => $email, 'token' => str_repeat( 'ab', 31 ) . sprintf( '%02x', $id % 256 ), 'created_at' => '2026-10-12 08:00:00',
        'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * 86400 ), 'deleted_at' => null,
        'stage1_data' => json_encode( array( 'q1_age' => 52, 'q1_sex' => 'female', 'server_result' => array( 'rate' => 1.1 ) ) ),
        'stage2_data' => null,
        'stage3_data' => json_encode( array( 'server_result' => array( 'rate' => 0.94, 'bio_age' => 48.9, 'scores' => $scores ) ) ),
        'stage3_completed_at' => $sent,
    );
}

function tl_fresh( $switch = true, $db = '3.34' ) {
    global $wpdb;
    fresh_wpdb( 'open' );
    $w = new TlWpdb();
    $w->config = $wpdb->config;
    $w->config->practitioner_user_id = 122;
    $wpdb = $w;
    $GLOBALS['options'] = array( 'hdlv2_db_version' => $db, 'hdlv2_ff_healthspan_timeline' => '1' );
    if ( $switch ) {
        $GLOBALS['options']['hdlv2_ff_timeline_upgrade'] = '1';
    }
    $GLOBALS['options']['hdlv2_stage1_offer'] = array(
        'timeline_upgrade_url' => 'https://buy.stripe.example.test/upgrade',
        'timeline_alone_url'   => 'https://buy.stripe.example.test/alone',
    );
    return $wpdb;
}

function tl_load() {
    load_sources();
    $ROOT = __DIR__ . '/../../';
    foreach ( array( 'includes/sprint-2/class-hdlv2-stage1-commentary.php', 'includes/sprint-2c/class-hdlv2-healthspan-timeline.php', 'includes/sprint-2c/class-hdlv2-timeline-upgrade.php' ) as $f ) {
        if ( file_exists( $ROOT . $f ) ) require_once $ROOT . $f;
    }
}

/** The site-time timestamp of a London wall-clock moment. */
function london( $when ) { return ( new DateTimeImmutable( $when, wp_timezone() ) )->getTimestamp(); }
