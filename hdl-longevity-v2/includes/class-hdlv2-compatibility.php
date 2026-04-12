<?php
/**
 * V1 Compatibility Bridge.
 *
 * This is the ONLY class that reads from V1 tables.
 * All V1 data access goes through here — no other V2 class should query V1 tables directly.
 *
 * Read-only except for one write path: inserting into wp_health_tracker_progress
 * when a Final Report is generated (so V1 trajectory charts keep working).
 *
 * @package HDL_Longevity_V2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Compatibility {

    /**
     * Get the practitioner linked to a client.
     *
     * @param int $client_user_id WordPress user ID of the client.
     * @return int|null Practitioner user ID or null.
     */
    public static function get_practitioner_for_client( $client_user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'health_tracker_practitioner_clients';

        if ( ! self::table_exists( $table ) ) {
            return null;
        }

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT practitioner_user_id FROM $table WHERE client_user_id = %d AND deleted_at IS NULL LIMIT 1",
            $client_user_id
        ) );
    }

    /**
     * Get all clients for a practitioner.
     *
     * @param int $practitioner_user_id WordPress user ID of the practitioner.
     * @return array Array of client user IDs.
     */
    public static function get_clients_for_practitioner( $practitioner_user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'health_tracker_practitioner_clients';

        if ( ! self::table_exists( $table ) ) {
            return array();
        }

        return $wpdb->get_col( $wpdb->prepare(
            "SELECT client_user_id FROM $table WHERE practitioner_user_id = %d AND deleted_at IS NULL",
            $practitioner_user_id
        ) );
    }

    /**
     * Check if a user is a practitioner.
     *
     * @param int $user_id WordPress user ID.
     * @return bool
     */
    public static function is_practitioner( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }
        return in_array( 'um_practitioner', (array) $user->roles, true )
            || in_array( 'practitioner', (array) $user->roles, true )
            || in_array( 'administrator', (array) $user->roles, true );
    }

    /**
     * Get latest V1 form submission for a client (for Stage 3 data import).
     *
     * @param int $user_id WordPress user ID.
     * @param string $form_type 'longevity' or 'health'.
     * @return object|null Submission row or null.
     */
    public static function get_latest_submission( $user_id, $form_type = 'longevity' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'health_tracker_submissions';

        if ( ! self::table_exists( $table ) ) {
            return null;
        }

        $user_hash = self::get_user_hash( $user_id );
        if ( ! $user_hash ) {
            return null;
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table WHERE user_hash = %s AND form_type = %s ORDER BY created_at DESC LIMIT 1",
            $user_hash,
            $form_type
        ) );
    }

    /**
     * Create practitioner-client relationship in V1 table.
     *
     * This write makes widget leads visible in the V1 practitioner dashboard.
     * If a relationship already exists (including soft-deleted), it restores it.
     *
     * @param int $practitioner_id Practitioner user ID.
     * @param int $client_id       Client user ID.
     * @return bool Success.
     */
    public static function create_practitioner_client_link( $practitioner_id, $client_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'health_tracker_practitioner_clients';

        if ( ! self::table_exists( $table ) ) {
            return false;
        }

        // Derive email hashes and name from user IDs for V1 dashboard compatibility
        $client_user       = get_userdata( $client_id );
        $practitioner_user = get_userdata( $practitioner_id );

        $client_email_hash       = $client_user ? hash( 'sha256', strtolower( trim( $client_user->user_email ) ) ) : null;
        $practitioner_email_hash = $practitioner_user ? hash( 'sha256', strtolower( trim( $practitioner_user->user_email ) ) ) : null;
        $client_name             = $client_user ? $client_user->display_name : null;

        // Check if relationship exists (including soft-deleted) — match by user ID or email hash
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, deleted_at FROM $table WHERE practitioner_user_id = %d AND (client_user_id = %d OR client_email_hash = %s) LIMIT 1",
            $practitioner_id, $client_id, $client_email_hash ?? ''
        ) );

        if ( $existing ) {
            // Restore soft-deleted relationship and refresh fields
            $update_data = array( 'client_user_id' => $client_id );
            if ( $existing->deleted_at ) {
                $update_data['deleted_at'] = null;
            }
            if ( $client_name ) {
                $update_data['client_name'] = $client_name;
            }
            if ( $client_email_hash ) {
                $update_data['client_email_hash'] = $client_email_hash;
            }
            $wpdb->update( $table, $update_data, array( 'id' => $existing->id ) );
            return true;
        }

        // Create new relationship with all V1-required fields
        $insert_data = array(
            'practitioner_user_id'   => $practitioner_id,
            'client_user_id'         => $client_id,
            'linked_date'            => current_time( 'mysql' ),
            'submission_count'       => 0,
        );
        if ( $practitioner_email_hash ) {
            $insert_data['practitioner_email_hash'] = $practitioner_email_hash;
        }
        if ( $client_email_hash ) {
            $insert_data['client_email_hash'] = $client_email_hash;
        }
        if ( $client_name ) {
            $insert_data['client_name'] = $client_name;
        }

        $wpdb->insert( $table, $insert_data );

        // Set user meta so V1 forms lock the practitioner email field
        update_user_meta( $client_id, 'hdl_invited_by_practitioner', $practitioner_id );

        return true;
    }

    /**
     * Write a progress data point (Final Report → V1 progress tracker).
     *
     * This is the ONLY write to a V1 table. It uses the exact format that
     * V1's trajectory charts expect so existing progress tracking keeps working.
     *
     * @param int    $user_id     WordPress user ID.
     * @param array  $metrics     Array of metric_name => metric_value.
     * @param string $date        Date string (Y-m-d).
     * @return bool Success.
     */
    public static function write_progress_point( $user_id, $metrics, $date = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'health_tracker_progress';

        if ( ! self::table_exists( $table ) ) {
            return false;
        }

        $user_hash = self::get_user_hash( $user_id );
        if ( ! $user_hash ) {
            return false;
        }

        $date = $date ?: current_time( 'Y-m-d' );

        foreach ( $metrics as $metric_name => $metric_value ) {
            $wpdb->insert( $table, array(
                'user_hash'        => $user_hash,
                'measurement_date' => $date,
                'metric_name'      => $metric_name,
                'metric_value'     => $metric_value,
            ), array( '%s', '%s', '%s', '%s' ) );
        }

        return true;
    }

    /**
     * Get user hash from V1 system.
     *
     * @param int $user_id WordPress user ID.
     * @return string|null
     */
    private static function get_user_hash( $user_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return null;
        }
        return wp_hash( strtolower( trim( $user->user_email ) ) );
    }

    /**
     * Check if a V1 table exists (safety check).
     *
     * @param string $table Full table name.
     * @return bool
     */
    private static function table_exists( $table ) {
        global $wpdb;
        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return $result === $table;
    }
}
