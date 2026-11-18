<?php
/**
 * HDLV2_Unproven_Guard — v0.48.85 (plan 2k, planner's gate G3.2).
 *
 * An account carrying hdlv2_unproven_entry went straight into the Full Report form on a browser pass
 * (typing, not owning, the e-mail). Opening the purchase e-mail's link returns it to a clean state
 * (HDL_Stage1_Ticket::inbox_proof()). Until then nothing may move the account out of that proof's reach:
 *   - its e-mail cannot change, by any route that saves a user: the ?invite= handler finds the account
 *     by e-mail, so a changed e-mail would send the owner to a fresh account and the proof would never run;
 *   - the older plugin's "switch practitioner" call is refused (it moves the account under a practitioner
 *     the request names, and the proof cannot undo that).
 * A password or application password set meanwhile needs no block here: the proof replaces both.
 *
 * @package HDL_Longevity_V2
 * @since   0.48.85
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Unproven_Guard {

    public static function register_hooks() {
        add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'keep_email' ), 10, 3 );
        add_filter( 'send_email_change_email', array( __CLASS__, 'no_change_mail' ), 10, 2 );
        add_action( 'wp_ajax_health_tracker_switch_practitioner', array( __CLASS__, 'refuse_switch' ), 0 );
    }

    /** Core REST, the profile screen and any plugin that calls wp_update_user() all pass through here. */
    public static function keep_email( $data, $update, $user_id ) {
        if ( $update && HDL_Stage1_Ticket::is_unproven( $user_id ) ) {
            $stored = get_userdata( (int) $user_id );
            if ( $stored ) {
                $data['user_email'] = $stored->user_email;
            }
        }
        return $data;
    }

    /** No "your e-mail was changed" mail to the owner for a change keep_email() undid. */
    public static function no_change_mail( $send, $user ) {
        return HDL_Stage1_Ticket::is_unproven( (int) ( $user['ID'] ?? 0 ) ) ? false : $send;
    }

    /** Runs before the older plugin's handler; its own error words, so no new words reach a reader. */
    public static function refuse_switch() {
        if ( HDL_Stage1_Ticket::is_unproven( get_current_user_id() ) ) {
            error_log( '[HDL full-report-open] guard switch' );
            wp_send_json_error( array( 'message' => 'Security check failed', 'code' => 'UNPROVEN' ) );
        }
    }
}
