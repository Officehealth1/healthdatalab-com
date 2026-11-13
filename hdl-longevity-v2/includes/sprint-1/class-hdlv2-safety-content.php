<?php
/**
 * HDL V2 — the safety notice's words and numbers, one source (v0.48.66).
 *
 * The notices a visitor sees after ticking something on the Stage 1 safety
 * check, the country table their numbers come from, and the rule that picks
 * the notice. Read by the widget (public config), the client email and the
 * practitioner alert, so the screen and the email never disagree.
 *
 * Signposting only: the notice is chosen from each row's existing urgency /
 * is_crisis mark in HDLV2_Safety_Screen. No extra question, no ranking, no AI.
 *
 * DRAFT copy: every visitor sentence comes verbatim from the owner's sheet
 * (2026-11-13) and stays behind hdlv2_ff_safety_notice_v2 until he signs it.
 * Change VERSION whenever a sentence or a number changes. Every number is
 * opened on its official page before it goes in, with the link and the date.
 *
 * @package HDL_Longevity_V2
 * @since 0.48.66
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HDLV2_Safety_Content {

	const VERSION = '2026-11-13.1';

	/** The notices, keyed by language. English only until the owner names others. */
	const NOTICES = array(
		'en' => array(
			'check'    => 'This check is automatic. No one reads your answers straight away. If you feel very unwell right now, call {EMERGENCY}.',
			'a'        => array(
				'title'  => 'Please read this before your result',
				'body'   => array(
					'If this is happening now, or happened in the last 24 hours, call {EMERGENCY} now. Do not drive yourself.',
					"If it happened longer ago and has passed, ask for an urgent doctor's appointment today. Contact {DOCTOR}.",
					'Tell them what you felt, when it happened and how long it lasted.',
					"Signs like these can pass quickly and still need checking. You are not wasting anyone's time.",
					'This check is automatic. It is not a diagnosis. Please do not wait to hear from us.',
				),
				'button' => 'I have read this. Show my result',
			),
			'b'        => array(
				'title'  => 'One thing before your result',
				'body'   => array(
					"Please book a doctor's appointment this week. Contact {DOCTOR}.",
					'Tell them what you noticed and how long you have had it.',
					"Most of the time the cause is something less serious. It is still worth checking, and you are not wasting anyone's time.",
					'If it gets suddenly worse, call {EMERGENCY}.',
					'This check is automatic. It is not a diagnosis. Please do not wait to hear from us.',
				),
				'button' => 'I have read this. Show my result',
			),
			'c'        => array(
				'title' => 'You do not have to face this alone',
				'body'  => array(
					'Thank you for telling us.',
					'If you are in danger right now, call {EMERGENCY}.',
					'You can talk to someone now. It is free and private: {CRISIS}.',
					'These feelings can pass, and support helps.',
					'This check is automatic. Please do not wait to hear from us.',
				),
			),
			'also'      => 'You also ticked something worth showing a doctor this week.',
			'reminder'  => 'Reminder:',
			'country'   => 'Country',
			'email_end' => array(
				'This email was sent automatically. Please do not reply to it: replies are not read.',
				'If you are a client, your practitioner will see these answers before your first session.',
			),
		),
	);

	/**
	 * Country rows, alphabetical by name. emergency = digits only; the rest
	 * fall back to GENERIC. Each number was opened on the linked page on the
	 * checked date. No Australian nurse line (it changed on 2026-01-01); no
	 * helpline directory (its terms forbid commercial use).
	 */
	const COUNTRIES = array(
		'AU' => array(
			'name'          => 'Australia',
			'emergency'     => '000',
			'crisis'        => 'Lifeline, call 13 11 14, any time',
			'crisis_number' => '13 11 14',
			'sources'       => array( 'https://www.lifeline.org.au/' ),
			'checked'       => '2026-11-13',
		),
		'CA' => array(
			'name'          => 'Canada',
			'emergency'     => '911',
			'crisis'        => 'call or text 988, any time',
			'crisis_number' => '988',
			'sources'       => array( 'https://988.ca/', 'https://transition.fcc.gov/pshs/services/911-services/consumer.html' ),
			'checked'       => '2026-11-13',
		),
		'IE' => array(
			'name'          => 'Ireland',
			'emergency'     => '112',
			'crisis'        => 'Samaritans, call 116 123, free, any time',
			'crisis_number' => '116 123',
			'sources'       => array( 'https://digital-strategy.ec.europa.eu/en/policies/112', 'https://www.samaritans.org/how-we-can-help/contact-samaritan/' ),
			'checked'       => '2026-11-13',
		),
		'NZ' => array(
			'name'          => 'New Zealand',
			'emergency'     => '111',
			'crisis'        => 'call or text 1737, free, any time',
			'crisis_number' => '1737',
			'sources'       => array( 'https://www.police.govt.nz/call-111', 'https://www.healthnz.govt.nz/health-topics/tests-and-treatments/emergencies-and-first-aid/emergency-medical-help', 'https://1737.org.nz/' ),
			'checked'       => '2026-11-13',
		),
		'PH' => array(
			'name'      => 'Philippines',
			'emergency' => '911',
			'doctor'    => 'a doctor or clinic',
			'sources'   => array( 'https://calabarzon.dilg.gov.ph/one-number-for-all-emergencies-unified-911-to-launch-nationwide/' ),
			'checked'   => '2026-11-13',
		),
		'SE' => array(
			'name'      => 'Sweden',
			'emergency' => '112',
			'sources'   => array( 'https://digital-strategy.ec.europa.eu/en/policies/112' ),
			'checked'   => '2026-11-13',
		),
		'GB' => array(
			'name'          => 'United Kingdom',
			'emergency'     => '999',
			'doctor'        => 'NHS 111 or your GP',
			'gp'            => 'GP',
			'advice'        => 'NHS 111',
			'crisis'        => 'Samaritans, call 116 123, free, any time',
			'crisis_number' => '116 123',
			'sources'       => array( 'https://www.nhs.uk/conditions/stroke/symptoms/', 'https://www.samaritans.org/how-we-can-help/contact-samaritan/' ),
			'checked'       => '2026-11-13',
		),
		'US' => array(
			'name'          => 'United States',
			'emergency'     => '911',
			'crisis'        => 'call or text 988, free, any time',
			'crisis_number' => '988',
			'sources'       => array( 'https://transition.fcc.gov/pshs/services/911-services/consumer.html', 'https://988lifeline.org/' ),
			'checked'       => '2026-11-13',
		),
	);

	/** The country is unknown or not in the table. */
	const GENERIC = array(
		'code'           => '',
		'name'           => 'Country not known',
		'emergency'      => '',
		'emergency_text' => 'your local emergency number',
		'doctor'         => 'a doctor',
		'gp'             => 'doctor',
		'advice'         => 'a doctor',
		'crisis'         => 'a crisis line in your country',
		'crisis_number'  => '',
	);

	/** Both switches: the screen itself, and these notices on it. */
	public static function enabled() {
		return (bool) get_option( 'hdlv2_ff_safety_screen', false ) && (bool) get_option( 'hdlv2_ff_safety_notice_v2', false );
	}

	/** The notices for a language; English when that language is absent. */
	public static function notices( $lang ) {
		return self::NOTICES[ is_string( $lang ) && isset( self::NOTICES[ $lang ] ) ? $lang : 'en' ];
	}

	/** The row for an ISO country code; the generic row for anything else. */
	public static function resolve( $country ) {
		$cc = is_string( $country ) ? strtoupper( trim( $country ) ) : '';
		if ( '' === $cc || ! isset( self::COUNTRIES[ $cc ] ) ) {
			return self::GENERIC;
		}
		return array( 'code' => $cc ) + self::COUNTRIES[ $cc ] + self::GENERIC;
	}

	/** Plain-text token swap. {GP} and {ADVICE} serve the existing email sentences. */
	public static function tokens( $text, $row ) {
		return strtr( (string) $text, array(
			'{EMERGENCY}' => '' !== $row['emergency'] ? $row['emergency'] : $row['emergency_text'],
			'{DOCTOR}'    => $row['doctor'],
			'{CRISIS}'    => $row['crisis'],
			'{GP}'        => $row['gp'],
			'{ADVICE}'    => $row['advice'],
		) );
	}

	/** HTML token swap for the emails: text escaped, numbers bold and tel: linked. */
	public static function tokens_html( $text, $row ) {
		$tel    = function ( $n ) {
			return '<a href="tel:' . preg_replace( '/\D/', '', $n ) . '" style="color:inherit;"><strong>' . esc_html( $n ) . '</strong></a>';
		};
		$crisis = esc_html( $row['crisis'] );
		if ( '' !== $row['crisis_number'] ) {
			$crisis = str_replace( esc_html( $row['crisis_number'] ), $tel( $row['crisis_number'] ), $crisis );
		}
		return strtr( esc_html( (string) $text ), array(
			'{EMERGENCY}' => '' !== $row['emergency'] ? $tel( $row['emergency'] ) : '<strong>' . esc_html( $row['emergency_text'] ) . '</strong>',
			'{DOCTOR}'    => esc_html( $row['doctor'] ),
			'{CRISIS}'    => '<strong>' . $crisis . '</strong>',
		) );
	}

	/** a = act now, b = this week, c = support, none = no notice; from the row's own marks only. */
	public static function tier_for( $group, $key ) {
		$list = 'mh' === $group ? HDLV2_Safety_Screen::MH_FLAGS : HDLV2_Safety_Screen::SYMPTOM_FLAGS;
		if ( ! is_string( $key ) || ! isset( $list[ $key ] ) ) {
			return 'none';
		}
		$def = $list[ $key ];
		if ( ! empty( $def['is_crisis'] ) ) {
			return 'c';
		}
		return 'TODAY' === $def['urgency'] ? 'a' : ( 'THIS_WEEK' === $def['urgency'] ? 'b' : 'none' );
	}

	/**
	 * What the screen shows for a set of (sanitised) ticks: C first when a
	 * crisis row is ticked, then A or B; A carries the "also" line when B rows
	 * were ticked too.
	 */
	public static function notice_for( $safety ) {
		$seen = array();
		if ( is_array( $safety ) ) {
			foreach ( array( 'symptoms', 'mh' ) as $g ) {
				foreach ( ( isset( $safety[ $g ] ) && is_array( $safety[ $g ] ) ) ? $safety[ $g ] : array() as $k ) {
					$seen[ self::tier_for( $g, $k ) ] = true;
				}
			}
		}
		$a = isset( $seen['a'] );
		$b = isset( $seen['b'] );
		return array( 'crisis' => isset( $seen['c'] ), 'notice' => $a ? 'a' : ( $b ? 'b' : '' ), 'also' => $a && $b );
	}

	/**
	 * The "I have read this" press from the widget: { notice, version, country },
	 * stamped with the server's time. Anything else returns null (the lead is
	 * still accepted, the press is just not kept).
	 */
	public static function sanitize_ack( $raw ) {
		if ( ! is_array( $raw )
			|| ! in_array( $raw['notice'] ?? '', array( 'a', 'b', 'c' ), true )
			|| ! is_string( $raw['version'] ?? null )
			|| ! preg_match( '/^\d{4}-\d{2}-\d{2}\.\d{1,3}$/', $raw['version'] ) ) {
			return null;
		}
		return array(
			'notice'  => $raw['notice'],
			'version' => $raw['version'],
			'country' => self::resolve( $raw['country'] ?? '' )['code'],
			'at'      => current_time( 'mysql', true ),
		);
	}

	/**
	 * What the emails need, or null while the switch is off. The country the
	 * visitor last saw on the notice wins over the looked-up one.
	 */
	public static function email_context( $stage1_data, $visitor_country ) {
		if ( ! self::enabled() ) {
			return null;
		}
		$s1  = is_array( $stage1_data ) ? $stage1_data : array();
		$ack = isset( $s1['_safety_ack'] ) && is_array( $s1['_safety_ack'] ) ? $s1['_safety_ack'] : null;
		return array(
			'row'   => self::resolve( $ack ? $ack['country'] : $visitor_country ),
			'shown' => self::notice_for( $s1['_safety'] ?? array() ),
			'ack'   => $ack,
		);
	}

	/** The widget's share: words, the eighteen rows with their notice, the countries. */
	public static function public_payload() {
		$rows = array();
		foreach ( array( 'symptoms' => HDLV2_Safety_Screen::SYMPTOM_FLAGS, 'mh' => HDLV2_Safety_Screen::MH_FLAGS ) as $g => $list ) {
			$rows[ $g ] = array();
			foreach ( $list as $k => $def ) {
				$rows[ $g ][] = array( 'k' => $k, 't' => $def['label'], 'tier' => self::tier_for( $g, $k ) );
			}
		}
		$countries = array();
		foreach ( array_keys( self::COUNTRIES ) as $cc ) {
			$r           = self::resolve( $cc );
			$countries[] = array_intersect_key( $r, array_flip( array( 'code', 'name', 'emergency', 'doctor', 'crisis', 'crisis_number' ) ) );
		}
		return array(
			'version'   => self::VERSION,
			'notices'   => self::NOTICES,
			'rows'      => $rows,
			'countries' => $countries,
			'generic'   => array_intersect_key( self::GENERIC, array_flip( array( 'code', 'name', 'emergency', 'emergency_text', 'doctor', 'crisis', 'crisis_number' ) ) ),
		);
	}
}
