<?php
/**
 * HDL V2 — one person's data, found and erased (v0.48.63, switch hdlv2_ff_data_erasure, off by default).
 *
 * Registered on WordPress's own wp_privacy_personal_data_erasers: nothing is erased until an
 * administrator presses Erase on Tools → Erase Personal Data for a confirmed request. Every key is
 * the person's exact address (lower case, trimmed), their user id, or ids found from those two.
 *
 * Four pages, in this order:
 *   1 files (report PDFs, recordings, iris images), then the jobs that point at them;
 *   2 V2 rows, one transaction (the table list and order of HDLV2_Admin_Restore::ajax_purge,
 *     across every practitioner, plus the stores the purge leaves);
 *   3 V1 rows and the mail log, one transaction;
 *   4 the sign-in account, client roles only.
 * Each page finds the keys again, so a second run removes nothing. A practitioner's or an
 * administrator's address is refused whole. Kept and reported: V1 payment rows
 * (hdl_credit_purchases). Messages carry counts per store, never a name or an address.
 *
 * Not tied to a person, so left to the 90-day audio sweep: a recording the practitioner made
 * outside a consultation's notes (an addendum), whose job holds only the practitioner's stamp.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Privacy_Eraser {

    const FLAG         = 'hdlv2_ff_data_erasure';
    const PAGES        = 4;
    const CLIENT_ROLES = array( 'um_client', 'um_consumer', 'um_annual_subscriber', 'subscriber' );
    const REFUSED      = 'This address belongs to a practitioner or administrator account. It is handled by hand.';

    /** job_type => which of the person's ids its reference_id holds (the enqueue sites). */
    const JOB_REFS = array(
        'generate_draft_report'       => 'progress',
        'generate_final_report'       => 'progress',
        'regenerate_final_report'     => 'progress',
        'generate_flight_notes_pdf'   => 'progress',
        'redflag_scan'                => 'progress',
        'organise_consultation'       => 'consults',
        'render_final_report_pdf'     => 'reports',
        'render_flight_plan_pdf'      => 'plans',
        'generate_flight_plan_manual' => 'user',
        'generate_flight_plan_auto'   => 'user',
    );

    public static function init() {
        if ( self::enabled() ) {
            add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register' ) );
        }
    }

    public static function enabled() {
        return (bool) get_option( self::FLAG, false );
    }

    public static function register( $erasers ) {
        $erasers['hdl-longevity-v2'] = array(
            'eraser_friendly_name' => 'HealthDataLab',
            'callback'             => array( __CLASS__, 'erase' ),
        );
        return $erasers;
    }

    /**
     * The keys to one person's data.
     *
     * @return array email, user, refused, progress[], tokens[], leads[], consults[], reports[], plans[],
     *               practitioners[], email_hash, user_hash.
     */
    public static function find( $email ) {
        global $wpdb;
        $p     = $wpdb->prefix;
        $email = strtolower( trim( (string) $email ) );
        $k     = array(
            'email' => $email, 'user' => 0, 'refused' => false, 'progress' => array(), 'tokens' => array(), 'leads' => array(),
            'consults' => array(), 'reports' => array(), 'plans' => array(), 'practitioners' => array(), 'email_hash' => '', 'user_hash' => '',
        );
        // An empty address would match every blank row: nothing is found for it.
        if ( ! is_email( $email ) ) {
            return $k;
        }
        $user = get_user_by( 'email', $email );
        if ( $user ) {
            $k['user']    = (int) $user->ID;
            $k['refused'] = user_can( $user, 'manage_options' ) || HDLV2_Compatibility::is_practitioner( $user->ID );
            if ( $k['refused'] ) {
                return $k;
            }
        }
        $uid  = $k['user'];
        $pracs = array();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, practitioner_user_id, token FROM {$p}hdlv2_form_progress WHERE ( %d > 0 AND client_user_id = %d ) OR LOWER(TRIM(client_email)) = %s ORDER BY id",
            $uid, $uid, $email
        ) );
        foreach ( $rows as $r ) {
            $k['progress'][] = (int) $r->id;
            $k['tokens'][]   = (string) $r->token;
            $pracs[]         = (int) $r->practitioner_user_id;
        }
        foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, practitioner_user_id FROM {$p}hdlv2_widget_leads WHERE LOWER(TRIM(visitor_email)) = %s ORDER BY id", $email ) ) as $r ) {
            $k['leads'][] = (int) $r->id;
            $pracs[]      = (int) $r->practitioner_user_id;
        }
        $pracs = array_merge( $pracs, array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
            "SELECT practitioner_id FROM {$p}hdlv2_widget_invites WHERE LOWER(TRIM(client_email)) = %s AND source <> 'timeline_link'",
            $email
        ) ) ) );
        if ( $k['progress'] ) {
            $in            = self::in( $k['progress'] );
            $k['consults'] = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$p}hdlv2_consultation_notes WHERE form_progress_id IN ($in)" ) );
            $k['reports']  = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$p}hdlv2_reports WHERE form_progress_id IN ($in)" ) );
        }
        if ( $uid ) {
            $k['plans'] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}hdlv2_flight_plans WHERE client_id = %d", $uid ) ) );
        }
        // V1's two hashes (class-health-tracker.php generate_user_hash, and the plain email hash).
        $k['email_hash'] = hash( 'sha256', $email );
        $salt            = (string) get_option( 'health_tracker_salt', '' );
        $k['user_hash']  = '' === $salt ? '' : hash( 'sha256', $email . $salt );
        if ( self::has( 'health_tracker_practitioner_clients' ) ) {
            $pracs = array_merge( $pracs, array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                "SELECT practitioner_user_id FROM {$p}health_tracker_practitioner_clients WHERE client_email_hash = %s",
                $k['email_hash']
            ) ) ) );
        }
        $k['practitioners'] = array_values( array_unique( array_filter( $pracs, function ( $id ) use ( $uid ) {
            return $id > 0 && $id !== $uid;
        } ) ) );
        return $k;
    }

    /** wp_privacy_personal_data_erasers callback: one page of the four. */
    public static function erase( $email, $page = 1 ) {
        $page = max( 1, (int) $page );
        $k    = self::find( $email );
        if ( $k['refused'] ) {
            return self::answer( false, true, array( self::REFUSED ), true );
        }
        if ( 1 === $page ) {
            self::remember( $k['email'], $k['practitioners'], true );
        } elseif ( $page <= self::PAGES ) {
            self::remember( $k['email'], $k['practitioners'], false );
        }
        $done = $page >= self::PAGES;
        switch ( $page ) {
            case 1:
                list( $counts, $kept ) = self::page_files( $k );
                break;
            case 2:
                $counts = self::in_transaction( function () use ( $k ) { return self::page_v2( $k ); } );
                $kept   = array();
                break;
            case 3:
                $counts = self::in_transaction( function () use ( $k ) { return self::page_v1( $k ); } );
                $kept   = is_wp_error( $counts ) ? array() : self::kept_payments( $k );
                break;
            case 4:
                list( $counts, $kept ) = self::page_account( $k );
                break;
            default:
                return self::answer( false, false, array(), true );
        }
        if ( is_wp_error( $counts ) ) {
            return $counts;
        }
        $counts = array_filter( $counts );
        $msgs   = $counts ? array( 'Removed: ' . implode( ', ', array_map( function ( $store, $n ) { return "$store $n"; }, array_keys( $counts ), $counts ) ) . '.' ) : array();
        self::add_counts( $k['email'], $counts );
        return self::answer( (bool) $counts, (bool) $kept, array_merge( $msgs, $kept ), $done );
    }

    // ── the four pages ──────────────────────────────────────────────

    /** @return array [ counts, kept messages ] */
    private static function page_files( $k ) {
        global $wpdb;
        $p     = $wpdb->prefix;
        $files = array(); // path => sub-folder its relative form lives in
        if ( $k['progress'] ) {
            $in = self::in( $k['progress'] );
            foreach ( array(
                "SELECT stage1_pdf_stored_path FROM {$p}hdlv2_form_progress WHERE id IN ($in)",
                "SELECT pdf_stored_path FROM {$p}hdlv2_why_profiles WHERE form_progress_id IN ($in)",
                "SELECT pdf_stored_path FROM {$p}hdlv2_reports WHERE form_progress_id IN ($in)",
            ) as $sql ) {
                foreach ( $wpdb->get_col( $sql ) as $f ) { $files[ (string) $f ] = 'hdlv2-reports'; }
            }
        }
        if ( $k['leads'] ) {
            foreach ( $wpdb->get_col( "SELECT stage1_pdf_stored_path FROM {$p}hdlv2_widget_leads WHERE id IN (" . self::in( $k['leads'] ) . ')' ) as $f ) {
                $files[ (string) $f ] = 'hdlv2-reports';
            }
        }
        if ( $k['plans'] ) {
            foreach ( $wpdb->get_col( "SELECT pdf_stored_path FROM {$p}hdlv2_flight_plans WHERE id IN (" . self::in( $k['plans'] ) . ')' ) as $f ) {
                $files[ (string) $f ] = 'hdlv2-reports';
            }
        }
        $iris_where = self::by_record_or_user( $k );
        if ( $iris_where && self::has( 'hdlv2_iris_results' ) ) {
            foreach ( $wpdb->get_results( "SELECT image_l_path, image_r_path, map_l_path, map_r_path FROM {$p}hdlv2_iris_results WHERE $iris_where" ) as $r ) {
                foreach ( (array) $r as $f ) { $files[ (string) $f ] = 'hdlv2-iris'; }
            }
        }
        $jobs = self::jobs_of( $k );
        foreach ( $jobs as $payload ) {
            if ( ! empty( $payload['file_path'] ) && is_string( $payload['file_path'] ) ) {
                $files[ $payload['file_path'] ] = 'hdlv2-audio';
            }
        }
        unset( $files[''] );
        $removed = 0;
        $refused = 0;
        foreach ( $files as $path => $sub ) {
            $r = self::unlink_private( $path, $sub );
            $removed += 'removed' === $r ? 1 : 0;
            $refused += 'refused' === $r ? 1 : 0;
        }
        $counts = array( 'files' => $removed );
        if ( $jobs ) {
            $counts['hdlv2_jobs'] = (int) $wpdb->query( "DELETE FROM {$p}hdlv2_jobs WHERE id IN (" . self::in( array_keys( $jobs ) ) . ')' );
        }
        $kept = $refused ? array( sprintf( '%d file path(s) point outside the private folder and were left alone for a person to check.', $refused ) ) : array();
        return array( $counts, $kept );
    }

    /** V2 rows. Runs inside a transaction; a failed step throws. */
    private static function page_v2( $k ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $c    = array();
        $uid  = $k['user'];
        $pids = $k['progress'] ? self::in( $k['progress'] ) : '';
        $lids = $k['leads'] ? self::in( $k['leads'] ) : '';

        if ( self::has( 'hdlv2_timeline_links' ) ) {
            // Invited on someone else's timeline: their three numbers and the withdraw link go, as a
            // withdraw does (HDLV2_Timeline_Links::cleared); the inviter's own label stays.
            // withdrawn_at is set before status: MySQL reads assignments left to right.
            if ( $lids ) {
                $now = gmdate( 'Y-m-d H:i:s' );
                $c['hdlv2_timeline_links withdrawn'] = self::run( $wpdb->prepare(
                    "UPDATE {$p}hdlv2_timeline_links SET withdrawn_at = CASE WHEN status = 'accepted' THEN %s ELSE withdrawn_at END,
                     status = CASE WHEN status = 'accepted' THEN 'withdrawn' ELSE status END,
                     shared_rate = NULL, shared_age = NULL, shared_sex = NULL, withdraw_token_hash = NULL, lead_id = NULL, updated_at = %s
                     WHERE lead_id IN ($lids)" . ( $pids ? " AND owner_progress_id NOT IN ($pids)" : '' ),
                    $now, $now
                ) );
            }
            // Their own timeline: the links, the tickets they sent with them ('tl:<link id>') and their packs.
            if ( $pids ) {
                $own = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$p}hdlv2_timeline_links WHERE owner_progress_id IN ($pids)" ) );
                if ( $own ) {
                    $refs = implode( ',', array_map( function ( $id ) { return "'tl:" . (int) $id . "'"; }, $own ) );
                    $c['hdlv2_widget_invites timeline'] = self::run( "DELETE FROM {$p}hdlv2_widget_invites WHERE source = 'timeline_link' AND external_ref IN ($refs)" );
                }
                $c['hdlv2_timeline_links'] = self::run( "DELETE FROM {$p}hdlv2_timeline_links WHERE owner_progress_id IN ($pids)" );
                if ( self::has( 'hdlv2_timeline_packs' ) ) {
                    $c['hdlv2_timeline_packs'] = self::run( "DELETE FROM {$p}hdlv2_timeline_packs WHERE owner_progress_id IN ($pids)" );
                }
            }
        }

        // The records' children, by record or by user (every practitioner's).
        $where = self::by_record_or_user( $k );
        if ( $where ) {
            foreach ( array( 'hdlv2_why_profiles', 'hdlv2_reports', 'hdlv2_consultation_addenda', 'hdlv2_consultation_notes', 'hdlv2_audio_extractions', 'hdlv2_iris_results' ) as $t ) {
                if ( self::has( $t ) ) {
                    $c[ $t ] = self::run( "DELETE FROM {$p}$t WHERE $where" );
                }
            }
        }
        if ( $uid ) {
            foreach ( array( 'hdlv2_flight_plan_ticks' => 'client_id', 'hdlv2_flight_plans' => 'client_id', 'hdlv2_checkins' => 'client_id', 'hdlv2_timeline' => 'client_id', 'hdlv2_monthly_summaries' => 'client_id', 'hdlv2_challenge_days' => 'client_user_id' ) as $t => $col ) {
                if ( self::has( $t ) ) {
                    $c[ $t ] = self::run( $wpdb->prepare( "DELETE FROM {$p}$t WHERE $col = %d", $uid ) );
                }
            }
        }
        foreach ( array( 'hdlv2_widget_invites' => 'client_email', 'hdlv2_automation_tokens' => 'client_email', 'hdlv2_pending_leads' => 'visitor_email' ) as $t => $col ) {
            if ( self::has( $t ) ) {
                $c[ $t ] = ( $c[ $t ] ?? 0 ) + self::run( $wpdb->prepare( "DELETE FROM {$p}$t WHERE LOWER(TRIM($col)) = %s", $k['email'] ) );
            }
        }
        if ( $lids ) {
            $c['hdlv2_widget_leads'] = self::run( "DELETE FROM {$p}hdlv2_widget_leads WHERE id IN ($lids)" );
        }
        if ( $pids ) {
            $c['hdlv2_form_progress'] = self::run( "DELETE FROM {$p}hdlv2_form_progress WHERE id IN ($pids)" );
        }
        return $c;
    }

    /** V1 rows and the mail log. Runs inside a transaction; a failed step throws. */
    private static function page_v1( $k ) {
        global $wpdb;
        $p   = $wpdb->prefix;
        $eh  = $k['email_hash'];
        $uh  = $k['user_hash'];
        $e   = $k['email'];
        $uid = $k['user'];
        $by_hash = $uh ? $wpdb->prepare( '( user_hash = %s OR email_hash = %s )', $uh, $eh ) : $wpdb->prepare( 'email_hash = %s', $eh );
        $stores  = array(
            'health_tracker_submissions'          => $by_hash,
            'health_tracker_progress'             => $uh ? $wpdb->prepare( 'user_hash = %s', $uh ) : '',
            'health_tracker_sessions'             => $by_hash,
            'health_tracker_verification_codes'   => $wpdb->prepare( 'email_hash = %s', $eh ),
            'health_tracker_practitioner_clients' => $wpdb->prepare( 'client_email_hash = %s', $eh ),
            'hdl_messages'                        => $wpdb->prepare( 'client_email_hash = %s', $eh ),
            'hdl_messaging_prefs'                 => $wpdb->prepare( 'client_email_hash = %s', $eh ),
            'hdl_invites'                         => $wpdb->prepare( 'LOWER(TRIM(email)) = %s', $e ),
            'hdl_lab_audit_log'                   => $wpdb->prepare( 'LOWER(TRIM(email)) = %s', $e ),
            'hdl_user_credits'                    => $uid ? $wpdb->prepare( 'user_id = %d', $uid ) : '',
            'hdl_usage_logs'                      => $uid ? $wpdb->prepare( 'user_id = %d', $uid ) : '',
            // Post SMTP's log holds whole emails, sign-in links included.
            'post_smtp_logs'                      => $wpdb->prepare( '( LOWER(TRIM(original_to)) = %s OR LOWER(TRIM(to_header)) = %s )', $e, $e ),
        );
        $c = array();
        foreach ( $stores as $t => $where ) {
            if ( $where && self::has( $t ) ) {
                $c[ $t ] = self::run( "DELETE FROM {$p}$t WHERE $where" );
            }
        }
        return $c;
    }

    /** @return string[] the kept-payment message, or none. */
    private static function kept_payments( $k ) {
        global $wpdb;
        if ( ! $k['user'] || ! self::has( 'hdl_credit_purchases' ) ) {
            return array();
        }
        $n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}hdl_credit_purchases WHERE user_id = %d", $k['user'] ) );
        return $n ? array( sprintf( 'Kept: %d payment record(s) (hdl_credit_purchases), which the law requires us to keep.', $n ) ) : array();
    }

    /** @return array [ counts, kept messages ] */
    private static function page_account( $k ) {
        $user = $k['user'] ? get_userdata( $k['user'] ) : false;
        if ( ! $user ) {
            return array( array(), array() );
        }
        if ( array_diff( (array) $user->roles, self::CLIENT_ROLES ) ) {
            return array( array(), array( 'Kept: the sign-in account has a role other than client, so a person decides about it.' ) );
        }
        if ( ! function_exists( 'wp_delete_user' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        return array( array( 'sign-in account' => wp_delete_user( $k['user'] ) ? 1 : 0 ), array() );
    }

    // ── helpers ─────────────────────────────────────────────────────

    /**
     * The person's jobs, id => payload. The audio jobs are matched by payload: a recording made for
     * a consultation's notes by that consultation; any other by the person's own stamp (their user
     * id, or the hash of one of their record tokens), never by reference alone, whose meaning
     * varies by context.
     * ponytail: reads every transcribe_audio row; a few hundred today. Index the stamps if it grows.
     */
    private static function jobs_of( $k ) {
        global $wpdb;
        $t   = $wpdb->prefix . 'hdlv2_jobs';
        $ids = array( 'progress' => $k['progress'], 'consults' => $k['consults'], 'reports' => $k['reports'], 'plans' => $k['plans'], 'user' => $k['user'] ? array( $k['user'] ) : array() );
        $out = array();
        foreach ( self::JOB_REFS as $type => $key ) {
            if ( $ids[ $key ] ) {
                foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, payload FROM $t WHERE job_type = %s AND reference_id IN (" . self::in( $ids[ $key ] ) . ')', $type ) ) as $r ) {
                    $out[ (int) $r->id ] = (array) json_decode( (string) $r->payload, true );
                }
            }
        }
        $hashes = array_map( function ( $tok ) { return hash_hmac( 'sha256', $tok, wp_salt( 'auth' ) ); }, $k['tokens'] );
        foreach ( $wpdb->get_results( "SELECT id, reference_id, payload FROM $t WHERE job_type = 'transcribe_audio'" ) as $r ) {
            $pl   = (array) json_decode( (string) $r->payload, true );
            $mine = ( $k['user'] && (int) ( $pl['_owner_user'] ?? 0 ) === $k['user'] )
                || ( ! empty( $pl['_owner_token_hash'] ) && in_array( $pl['_owner_token_hash'], $hashes, true ) )
                || ( 'consultation_notes' === ( $pl['context_type'] ?? '' ) && in_array( (int) $r->reference_id, $k['consults'], true ) );
            if ( $mine ) {
                $out[ (int) $r->id ] = $pl;
            }
        }
        return $out;
    }

    /**
     * Same base as HDLV2_Report_PDF / HDLV2_Audio_Service / HDLV2_Iris_Consult::private_dir.
     * A path is unlinked only if its real path is a file inside that folder.
     *
     * @return string removed | missing | refused
     */
    private static function unlink_private( $path, $sub ) {
        $base = defined( 'HDLV2_PRIVATE_DIR' ) ? rtrim( HDLV2_PRIVATE_DIR, '/' ) : dirname( rtrim( ABSPATH, '/' ) ) . '/hdlv2-private';
        $abs  = '/' === substr( $path, 0, 1 ) ? $path : $base . '/' . $sub . '/' . $path;
        $real = realpath( $abs );
        $root = realpath( $base );
        if ( false === $real || ! is_file( $real ) ) {
            return 'missing';
        }
        if ( false === $root || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
            return 'refused';
        }
        return unlink( $real ) ? 'removed' : 'refused';
    }

    /** "form_progress_id IN (…) OR client_user_id = n", or '' when the person has neither. */
    private static function by_record_or_user( $k ) {
        $or = array();
        if ( $k['progress'] ) {
            $or[] = 'form_progress_id IN (' . self::in( $k['progress'] ) . ')';
        }
        if ( $k['user'] ) {
            $or[] = 'client_user_id = ' . (int) $k['user'];
        }
        return implode( ' OR ', $or );
    }

    private static function in_transaction( $fn ) {
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        try {
            $c = $fn();
            $wpdb->query( 'COMMIT' );
            return $c;
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'hdlv2_erase_failed', 'A database step failed and this page was rolled back. Nothing on it changed. Press Erase again.' );
        }
    }

    /** One write; false (a failed statement) throws, so the page rolls back. */
    private static function run( $sql ) {
        global $wpdb;
        $n = $wpdb->query( $sql );
        if ( false === $n ) {
            throw new RuntimeException( 'erase step failed' );
        }
        return (int) $n;
    }

    private static function has( $table ) {
        global $wpdb;
        static $seen = array();
        $t = $wpdb->prefix . $table;
        if ( ! isset( $seen[ $t ] ) ) {
            $seen[ $t ] = $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
        }
        return $seen[ $t ];
    }

    private static function in( $ids ) {
        return implode( ',', array_map( 'intval', $ids ) );
    }

    private static function answer( $removed, $retained, $messages, $done ) {
        return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $messages, 'done' => $done );
    }

    // ── what the four pages share: the practitioners to tell, and the counts ──

    private static function state_key( $email ) {
        return 'hdlv2_erase_' . md5( $email );
    }

    /** The practitioners are read before page 2 removes the rows that name them. */
    private static function remember( $email, $practitioners, $fresh ) {
        $s = $fresh ? array() : (array) get_transient( self::state_key( $email ) );
        $s['practitioners'] = array_values( array_unique( array_merge( $s['practitioners'] ?? array(), $practitioners ) ) );
        $s['counts']        = $s['counts'] ?? array();
        set_transient( self::state_key( $email ), $s, DAY_IN_SECONDS );
    }

    private static function add_counts( $email, $counts ) {
        $s = (array) get_transient( self::state_key( $email ) );
        foreach ( $counts as $store => $n ) {
            $s['counts'][ $store ] = ( $s['counts'][ $store ] ?? 0 ) + $n;
        }
        set_transient( self::state_key( $email ), $s, DAY_IN_SECONDS );
    }
}

HDLV2_Privacy_Eraser::init();
