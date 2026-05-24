<?php
/**
 * Daily digest cron — emails each practitioner once per 24h with the list
 * of their clients currently in `needs_attention` status.
 *
 * Without this, practitioners only see the red badge if they happen to
 * load /clients/. A client with critical flags or low adherence could
 * deteriorate for a week before anyone notices.
 *
 * Dedupe via `hdlv2_attention_last_sent` user meta (timestamp). Cron runs
 * daily; if last digest was sent < 24h ago for a given practitioner, skip.
 *
 * Bug-3 in PRACTITIONER-DASHBOARD-V2-BRAINSTORM-2026-05-15.md.
 *
 * @since 0.41.8
 */

defined( 'ABSPATH' ) || exit;

class HDLV2_Attention_Cron {

	/** @var self */
	private static $instance;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_hooks() {
		add_action( 'hdlv2_attention_email_cron', array( $this, 'run' ) );
	}

	/**
	 * Cron entry point. Iterates practitioners and dispatches per-practitioner
	 * digest emails. Cheap to run daily; the inner loop is gated by status
	 * calc which already runs frequently from the dashboard poll.
	 */
	public function run() {
		if ( ! class_exists( 'HDLV2_Compatibility' ) || ! class_exists( 'HDLV2_Client_Status' ) ) {
			error_log( '[HDLV2 Attention Cron] dependencies missing — skipping run' );
			return;
		}
		// Practitioners + administrators (admins may also have linked clients).
		$prac_ids = get_users( array(
			'role__in' => array( 'um_practitioner', 'administrator' ),
			'fields'   => 'ID',
		) );
		$dispatched = 0;
		foreach ( $prac_ids as $prac_id ) {
			if ( $this->process_practitioner( (int) $prac_id ) ) {
				$dispatched++;
			}
		}
		error_log( sprintf( '[HDLV2 Attention Cron] scanned %d practitioner(s), dispatched %d digest email(s).', count( $prac_ids ), $dispatched ) );
	}

	/**
	 * Process one practitioner. Returns true if a digest was sent, false otherwise.
	 *
	 * @param int $prac_id Practitioner WP user ID.
	 * @return bool
	 */
	public function process_practitioner( $prac_id ) {
		// 24h dedupe — never spam practitioners more than daily.
		$last_sent = (int) get_user_meta( $prac_id, 'hdlv2_attention_last_sent', true );
		if ( $last_sent && ( time() - $last_sent ) < DAY_IN_SECONDS ) {
			return false;
		}

		$clients = HDLV2_Compatibility::get_clients_for_practitioner( $prac_id );
		if ( empty( $clients ) ) {
			return false;
		}

		$attention = array();
		foreach ( $clients as $client_id ) {
			$status = HDLV2_Client_Status::calculate_status( (int) $client_id );
			if ( empty( $status['status'] ) || 'needs_attention' !== $status['status'] ) {
				continue;
			}
			$user = get_userdata( (int) $client_id );
			$attention[] = array(
				'client_id' => (int) $client_id,
				'name'      => $user ? $user->display_name : 'Client #' . (int) $client_id,
				'email'     => $user ? $user->user_email : '',
				'reasons'   => isset( $status['reasons'] ) && is_array( $status['reasons'] ) ? $status['reasons'] : array(),
			);
		}

		if ( empty( $attention ) ) {
			return false;
		}

		$sent = $this->send_digest( (int) $prac_id, $attention );
		if ( $sent ) {
			update_user_meta( $prac_id, 'hdlv2_attention_last_sent', time() );
		}
		return $sent;
	}

	/**
	 * Send the digest email for one practitioner.
	 *
	 * @param int   $prac_id   Practitioner WP user ID.
	 * @param array $attention Each row: client_id, name, email, reasons[].
	 * @return bool wp_mail() result.
	 */
	private function send_digest( $prac_id, array $attention ) {
		$prac = get_userdata( $prac_id );
		if ( ! $prac || empty( $prac->user_email ) ) {
			return false;
		}
		if ( ! class_exists( 'HDLV2_Email_Templates' ) ) {
			return false;
		}

		$count   = count( $attention );
		$subject = sprintf(
			'[HealthDataLab] %d %s need your attention',
			$count,
			1 === $count ? 'client' : 'clients'
		);

		$body = HDLV2_Email_Templates::attention_digest( array(
			'practitioner'  => $prac,
			'clients'       => $attention,
			'dashboard_url' => home_url( '/clients/' ),
		) );

		return (bool) wp_mail(
			$prac->user_email,
			$subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}
}
