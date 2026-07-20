<?php
/**
 * HDL V2 — Flags notifier (action C: single notification path).
 *
 * Used by BOTH the deterministic front-door safety screen (HDLV2_Safety_Screen)
 * and the AI red-flag scan (HDLV2_Redflag_Jobs) so a flag notifies identically
 * however it was raised. Picks the transport:
 *
 *   - HDLV2_MAKE_REDFLAG defined + non-empty  → fire the Make.com scenario
 *     (report_type=redflag), which fans out the client + practitioner emails.
 *   - otherwise                                → wp_mail() fallback. Keeps STBY
 *     (which has no Make.com) working and is the resilience path on LIVE if the
 *     webhook is unset/down.
 *
 * Either way it stamps messaged_at on the merged flags so a later re-scan never
 * re-messages the same flag.
 *
 * No PDF is generated on this path — the Stage-1 PDF is a separate webhook
 * (report_type=stage1) fired from widget-config and is unaffected by flags.
 *
 * @package HDL_Longevity_V2
 * @since 0.44.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HDLV2_Flags_Store {

	/**
	 * Send the right notification(s) for new flags, then stamp messaged_at.
	 *
	 * @param object $progress   form_progress row — needs client_email, client_name,
	 *                           client_user_id, practitioner_user_id.
	 * @param array  $to_message newly-added, non-CONTEXT flag objects to message.
	 * @param array  $merged     full merged flag list (by ref) — messaged_at stamped here.
	 */
	public static function notify( $progress, $to_message, &$merged, $opts = array() ) {
		if ( empty( $to_message ) || empty( $progress->client_email ) ) {
			return;
		}

		// Split crisis (self-harm) from standard (GP-nudge) flags.
		$crisis        = array(); // crisis flag objects
		$findings      = array(); // client-facing wording strings (Make payload)
		$finding_flags = array(); // full non-crisis flag objects (premium cards)
		foreach ( $to_message as $f ) {
			if ( ! empty( $f['is_crisis'] ) ) {
				$crisis[] = $f;
			} elseif ( ! empty( $f['client_facing_wording'] ) ) {
				$findings[]      = $f['client_facing_wording'];
				$finding_flags[] = $f;
			}
		}

		// Linked practitioner's WP login email (same field the Stage-1 results
		// email uses) + a deep-link straight to this client's record.
		$prac_email = '';
		if ( ! empty( $progress->practitioner_user_id ) ) {
			$prac = get_userdata( (int) $progress->practitioner_user_id );
			$prac_email = $prac ? $prac->user_email : '';
		}
		// Practitioner CTA = one-shot auto-login link (Part 2, v0.45.3). The
		// button signs the practitioner in and lands them in the right place:
		// the Pending Leads inbox for an unconfirmed public lead (no record yet,
		// dest=pending_leads), or this client's record for a confirmed client /
		// AI re-scan (release deep-link). Falls back to a plain dashboard URL if
		// the minter is unavailable so the link still works (minus auto-login).
		$cta_prac_id = (int) ( $progress->practitioner_user_id ?? 0 );
		$cta_dest    = isset( $opts['dest'] ) ? (string) $opts['dest'] : '';
		if ( $cta_prac_id > 0 && class_exists( 'HDLV2_Staged_Form' ) && method_exists( 'HDLV2_Staged_Form', 'mint_prac_login_url' ) ) {
			if ( 'pending_leads' === $cta_dest ) {
				$dashboard_url = HDLV2_Staged_Form::mint_prac_login_url( $cta_prac_id, 0, 'pending_leads' );
			} else {
				$dashboard_url = HDLV2_Staged_Form::mint_prac_login_url( $cta_prac_id, (int) ( $progress->id ?? 0 ), 'release' );
			}
		} else {
			$slug          = trim( apply_filters( 'hdlv2_practitioner_dashboard_slug', 'clients' ), '/' );
			$dashboard_url = home_url( '/' . $slug . '/' );
		}

		// Build the practitioner flag rows once — reused by Make payload + wp_mail.
		$prac_flags_html = self::practitioner_flags_html( $to_message );

		$webhook = defined( 'HDLV2_MAKE_REDFLAG' ) ? HDLV2_MAKE_REDFLAG : '';
		if ( $webhook ) {
			$sent_ok = self::fire_make( $webhook, $progress, $prac_email, $dashboard_url, $crisis, $findings, $prac_flags_html, $to_message );
		} else {
			$sent_ok = self::fire_wp_mail( $progress, $prac_email, $dashboard_url, $crisis, $finding_flags, $prac_flags_html );
		}

		// v0.46.21 (QA F3) — only stamp messaged_at when the send actually
		// succeeded. A failed crisis / GP-nudge send must stay UN-messaged so a
		// later red-flag rescan re-attempts it. Previously messaged_at was
		// stamped unconditionally, so one transient SMTP error or one dropped
		// (non-blocking) Make POST permanently dropped a self-harm client's
		// 999/Samaritans email — no retry, no surfaced error.
		if ( ! $sent_ok ) {
			error_log( sprintf(
				'[HDLV2 SAFETY] flag notification send FAILED — messaged_at left unset for retry (client_user_id=%d, flags=%d)',
				(int) ( $progress->client_user_id ?? 0 ), count( $to_message )
			) );
			return;
		}

		// Stamp messaged_at so a later scan never re-messages these flags.
		$now  = current_time( 'mysql', true );
		$sent = array();
		foreach ( $to_message as $f ) {
			if ( ! empty( $f['signature'] ) ) {
				$sent[ $f['signature'] ] = true;
			}
		}
		foreach ( $merged as &$m ) {
			if ( ! empty( $m['signature'] ) && isset( $sent[ $m['signature'] ] ) ) {
				$m['messaged_at'] = $now;
			}
		}
		unset( $m );
	}

	/** Fire the Make.com scenario (report_type=redflag) — fans out all three emails. */
	private static function fire_make( $webhook, $progress, $prac_email, $dashboard_url, $crisis, $findings, $prac_flags_html, $to_message ) {
		$prac_id = $progress->practitioner_user_id ?? null;
		$logo    = class_exists( 'HDLV2_Email_Templates' ) ? HDLV2_Email_Templates::resolve_logo( $prac_id ) : '';
		$shape   = class_exists( 'HDLV2_Email_Templates' ) ? HDLV2_Email_Templates::resolve_logo_shape( $prac_id ) : '';
		$prac    = $prac_id ? get_userdata( (int) $prac_id ) : null;

		$findings_html = '';
		foreach ( $findings as $f ) {
			$findings_html .= '<li style="margin-bottom:8px;">' . esc_html( $f ) . '</li>';
		}

		// Most-urgent label for the subject/summary.
		$rank = array( 'TODAY' => 3, 'THIS_WEEK' => 2, 'WITHIN_WEEKS' => 1 );
		$top = ''; $top_n = 0;
		foreach ( $to_message as $f ) {
			$u = strtoupper( (string) ( $f['urgency'] ?? '' ) );
			if ( isset( $rank[ $u ] ) && $rank[ $u ] > $top_n ) { $top_n = $rank[ $u ]; $top = $u; }
		}

		$payload = array(
			'report_type'             => 'redflag',
			'client_name'             => (string) $progress->client_name,
			'client_email'            => (string) $progress->client_email,
			'practitioner_name'       => $prac ? $prac->display_name : '',
			'practitioner_email'      => (string) $prac_email,
			'practitioner_logo_url'   => (string) $logo,
			'practitioner_logo_shape' => (string) $shape,
			'is_crisis'               => empty( $crisis ) ? 'no' : 'yes',
			'has_findings'            => empty( $findings ) ? 'no' : 'yes',
			'flag_count'              => (string) count( $to_message ),
			'top_urgency'             => $top,
			'client_findings_html'    => $findings_html,
			'practitioner_flags_html' => $prac_flags_html,
			'dashboard_url'           => $dashboard_url,
			'submitted_at'            => current_time( 'c' ),
		);

		// v0.46.21 (QA F3) — blocking + return success. Safety/crisis sends must
		// confirm delivery; a non-blocking fire-and-forget can't tell a 500 /
		// dropped POST from a success, which previously let notify() stamp
		// messaged_at on a send that never reached the client.
		$res = wp_remote_post( $webhook, array(
			'body'     => wp_json_encode( $payload ),
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'timeout'  => 5,
			'blocking' => true,
		) );
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return ( $code >= 200 && $code < 300 );
	}

	/**
	 * wp_mail fallback — used when HDLV2_MAKE_REDFLAG is unset (e.g. STBY).
	 *
	 * @param array $finding_flags Full non-crisis flag objects (label, concern,
	 *                             urgency, client_facing_wording) for the cards.
	 */
	private static function fire_wp_mail( $progress, $prac_email, $dashboard_url, $crisis, $finding_flags, $prac_flags_html ) {
		if ( ! class_exists( 'HDLV2_Email_Templates' ) ) {
			return false; // v0.46.21 (QA F3) — could not send → not a success
		}
		$prac_id = $progress->practitioner_user_id ?? null;
		$ok      = true; // v0.46.21 (QA F3) — track delivery; notify() stamps only on success

		// ONE combined client safety email — crisis support on top (when a
		// self-harm flag is present) + a card per symptom/mood finding. Replaces
		// the previous two separate sends (crisis + GP-nudge) so a flagged client
		// never receives more than one safety email.
		if ( ! empty( $crisis ) || ! empty( $finding_flags ) ) {
			$sent = HDLV2_Email_Templates::client_safety_alert( array(
				'client_email'    => $progress->client_email,
				'client_name'     => $progress->client_name,
				'practitioner_id' => $prac_id,
				'crisis'          => ! empty( $crisis ),
				'findings'        => $finding_flags,
			) );
			if ( false === $sent ) { $ok = false; }
		}

		if ( $prac_email ) {
			$sent = HDLV2_Email_Templates::practitioner_redflag_alert( array(
				'practitioner_email' => $prac_email,
				'practitioner_id'    => $prac_id,
				'client_name'        => $progress->client_name,
				'dashboard_url'      => $dashboard_url,
				'flags_html'         => $prac_flags_html,
			) );
			if ( false === $sent ) { $ok = false; }
		}

		return $ok;
	}

	/**
	 * Build the practitioner-facing flag rows (status-palette chip + note).
	 * Shared by the Make payload and the wp_mail practitioner alert.
	 */
	public static function practitioner_flags_html( $flags ) {
		$rows = '';
		foreach ( $flags as $f ) {
			$cat = strtoupper( (string) ( $f['category'] ?? '' ) );
			$urg = strtoupper( str_replace( '_', ' ', (string) ( $f['urgency'] ?? '' ) ) );
			if ( 'HARD' === $cat ) {
				$bg = '#fef2f2'; $fg = '#dc2626'; $bd = '#fecaca';
			} elseif ( 'AMBER' === $cat ) {
				$bg = '#fffbeb'; $fg = '#d97706'; $bd = '#fde68a';
			} else {
				$bg = '#eff6ff'; $fg = '#3b82f6'; $bd = '#bfdbfe';
			}
			$label = trim( $cat . ( $urg ? ' · ' . $urg : '' ) );
			$note  = esc_html( (string) ( $f['practitioner_note'] ?? ( $f['concern'] ?? '' ) ) );
			$rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #f0f1f3;">'
				. '<span style="background:' . $bg . ';color:' . $fg . ';border:1px solid ' . $bd . ';border-radius:4px;padding:2px 8px;font-size:11px;font-weight:600;">' . esc_html( $label ) . '</span>'
				. '<div style="margin-top:6px;font-size:13px;color:#2c3e50;line-height:1.55;">' . $note . '</div>'
				. '</td></tr>';
		}
		return $rows;
	}
}
