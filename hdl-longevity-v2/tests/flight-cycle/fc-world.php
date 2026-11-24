<?php
/**
 * Plan 14 part C and its fix rows: the shared world of the six-week tests (scenario-six-weeks, -waiting,
 * -reinvited). The access stubs, the REAL classes, a table fake (FC_Wpdb) and fakes for the AI, the e-mail
 * and the plan job. Rosa (13), form 14 with a final report, practitioner Ada (3). No network.
 */
require dirname( __DIR__ ) . '/access/stubs.php';
ac_load( array( 'security/class-hdlv2-access.php', 'sprint-4/class-hdlv2-checkin.php', 'sprint-5/class-hdlv2-flight-plan.php', 'sprint-5/class-hdlv2-flight-cycle.php' ) );

/** Rows by table; WHERE on `col = n`, `col = 'x'`, `col IS [NOT] NULL`; ORDER BY one column; LIMIT. */
class FC_Wpdb {
    public $prefix = 'wp_'; public $last_error = ''; public $insert_id = 0; public $writes = array(); public $queries = array();
    public $t = array( 'flight_cycles' => array(), 'form_progress' => array(), 'reports' => array(), 'flight_plans' => array(), 'flight_plan_ticks' => array(), 'checkins' => array() );
    public function prepare( $q, ...$a ) { foreach ( $a as $x ) $q = preg_replace( '/%[sd]/', is_int( $x ) ? (string) $x : "'" . addslashes( (string) $x ) . "'", $q, 1 ); return $q; }
    private function name( $q ) { foreach ( array_keys( $this->t ) as $n ) if ( preg_match( '/hdlv2_' . $n . '\b/', $q ) ) return $n; return null; }
    private function rows( $q ) {
        $this->queries[] = $q; $n = $this->name( $q ); if ( ! $n ) return array();
        $where = preg_match( '/WHERE (.*?)(ORDER BY|LIMIT|$)/s', $q, $m ) ? $m[1] : '';
        $out = array();
        foreach ( $this->t[ $n ] as $r ) {
            foreach ( preg_split( '/\s+AND\s+/i', trim( $where ) ) as $c ) {
                if ( preg_match( "/^(\w+) = (\d+)$/", trim( $c ), $x ) && (string) ( $r[ $x[1] ] ?? '' ) !== $x[2] ) continue 2;
                if ( preg_match( "/^(\w+) = '([^']*)'$/", trim( $c ), $x ) && (string) ( $r[ $x[1] ] ?? '' ) !== $x[2] ) continue 2;
                if ( preg_match( '/^(\w+) IS NULL$/', trim( $c ), $x ) && null !== ( $r[ $x[1] ] ?? null ) ) continue 2;
                if ( preg_match( '/^(\w+) IS NOT NULL$/', trim( $c ), $x ) && null === ( $r[ $x[1] ] ?? null ) ) continue 2;
                if ( preg_match( "/^(\w+) < '([^']*)'$/", trim( $c ), $x ) && ! ( (string) ( $r[ $x[1] ] ?? '' ) < $x[2] ) ) continue 2;
            }
            $out[] = (object) $r;
        }
        if ( preg_match( '/ORDER BY (\w+)( DESC)?/', $q, $o ) ) usort( $out, function ( $a, $b ) use ( $o ) { $c = strcmp( (string) $a->{$o[1]}, (string) $b->{$o[1]} ); if ( 0 === $c ) $c = $a->id - $b->id; return empty( $o[2] ) ? $c : -$c; } );
        if ( preg_match( '/LIMIT (\d+)/', $q, $l ) ) $out = array_slice( $out, 0, (int) $l[1] );
        return $out;
    }
    public function get_results( $q ) { return $this->rows( $q ); }
    public function get_row( $q ) { $r = $this->rows( $q ); return $r ? $r[0] : null; }
    public $locks = array();
    public function get_var( $q ) { if ( preg_match( "/GET_LOCK\('([\w]+)', 30\)/", $q, $l ) ) { $this->locks[] = $l[1]; if ( isset( $GLOBALS['on_lock'] ) ) ( $GLOBALS['on_lock'] )( $this ); return isset( $GLOBALS['lock_busy'] ) ? '0' : '1'; } $r = $this->rows( $q ); if ( ! $r ) return null; $a = (array) $r[0]; return preg_match( '/SELECT\s+(\w+)\s/i', $q, $m ) && array_key_exists( $m[1], $a ) ? $a[ $m[1] ] : reset( $a ); }
    public function get_col( $q ) { return array(); }
    public function query( $q ) { $this->queries[] = $q; if ( preg_match( '/^\s*(UPDATE|INSERT|DELETE)/i', $q ) ) $this->writes[] = $q; return 1; }
    public function insert( $table, $data ) {
        $n = $this->name( $table ); $this->writes[] = array( 'insert', $n, $data );
        if ( 'flight_cycles' === $n ) foreach ( $this->t[ $n ] as $r ) if ( (int) $r['client_id'] === (int) $data['client_id'] && (int) $r['cycle_number'] === (int) $data['cycle_number'] ) { $this->last_error = 'Duplicate entry'; return false; }
        $this->last_error = ''; $this->insert_id = 500 + count( $this->writes );
        $this->t[ $n ][] = array_merge( array( 'id' => $this->insert_id, 'outline' => null, 'review' => null, 'closed_at' => null ), $data );
        return 1;
    }
    public function update( $table, $data, $where ) {
        $n = $this->name( $table ); $this->writes[] = array( 'update', $n, $data, $where ); $k = 0;
        foreach ( $this->t[ $n ] as $i => $r ) { foreach ( $where as $c => $v ) if ( (string) ( $r[ $c ] ?? '' ) !== (string) $v ) continue 2; $this->t[ $n ][ $i ] = array_merge( $r, $data ); $k++; }
        return $k;
    }
}
class HDLV2_AI_Service {
    public static $prompts = array(); public static $fail = false;
    public static function run_prompt( $p, $max, &$usage = null ) {
        self::$prompts[] = $p;
        if ( self::$fail ) return new WP_Error( 'claude_api_error', 'down' );
        $usage = array( 'input_tokens' => 1200 + count( self::$prompts ), 'output_tokens' => 90 );
        return 0 === strpos( $p, 'From the client' ) ? "Week 1: Walk after lunch.\nWeek 2: Water at breakfast.\nWeek 3: Bed by 23:00.\nWeek 4: Two walks.\nWeek 5: Cook twice.\nWeek 6: Keep it up." : 'Walked most days. Missed the late snacks rule.';
    }
}
class HDLV2_Email_Templates { public static $sent = array(); public static function client_needs_attention( $d ) { self::$sent[] = $d; } }
class HDLV2_Flight_Plan_Auto_Jobs { public static $jobs = array(); public static function enqueue_for( $c, $t ) { self::$jobs[] = array( (int) $c, $t ); return 1; } }

function sw_world() {
    ac_world(); // users, options, clock
    $w = new FC_Wpdb();
    $w->t['form_progress'][] = array( 'id' => 14, 'client_user_id' => 13, 'practitioner_user_id' => 3, 'client_name' => 'Rosa Vale', 'deleted_at' => null, 'token_expires_at' => '2026-12-01 00:00:00' );
    $w->t['reports'][]       = array( 'id' => 70, 'form_progress_id' => 14, 'report_type' => 'final', 'status' => 'ready' );
    $GLOBALS['wpdb'] = $w;
    HDLV2_AI_Service::$prompts = array(); HDLV2_AI_Service::$fail = false; HDLV2_Email_Templates::$sent = array(); HDLV2_Flight_Plan_Auto_Jobs::$jobs = array();
    $GLOBALS['options']['hdlv2_ff_flight_cycle'] = 1; $GLOBALS['env_live'] = 1; unset( $GLOBALS['engagement'], $GLOBALS['on_lock'], $GLOBALS['lock_busy'] );
    return $w;
}
function cyc( $w ) { return array_values( $w->t['flight_cycles'] ); }
$ctx = array( 'report_summary' => 'Pace 1.1', 'why_profile' => array( 'distilled_why' => 'Grandchildren' ), 'recent_checkins' => array(), 'engagement_signal' => 'medium' );
