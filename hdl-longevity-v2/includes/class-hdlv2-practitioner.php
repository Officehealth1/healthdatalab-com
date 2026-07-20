<?php
/**
 * Practitioner-level reads — single source of truth for cross-cutting
 * practitioner data (logo URL today; future: notification email,
 * practitioner profile fields, etc.).
 *
 * Why this class exists: prior to v0.29.4 the practitioner logo was read
 * inline by every consumer (Stage 1 widget, Stage 2 webhook, Final Report
 * webhook, Flight Plan webhook, Client Draft View). Two of those consumers
 * read from `wp_hdlv2_widget_config.logo_url` (canonical, written by the
 * Widget Settings upload UI) and one read from `user_meta.practitioner_logo_url`
 * (legacy, never written by the current UI) — so Draft View always rendered
 * an empty logo regardless of what the practitioner uploaded.
 *
 * Consolidating the read into HDLV2_Practitioner::get_logo_url() means:
 *   - One canonical SELECT path (widget_config) with one legacy fallback
 *     (user_meta) so any historical data isn't lost.
 *   - File-existence validation: if the URL points at a local file that
 *     no longer exists on disk, return '' so consumers can degrade
 *     gracefully (e.g. PDF cover falls back to the avatar lockup) instead
 *     of shipping a 404 <img> URL through Make.com → PDFMonkey.
 *   - One place to fix bugs, add caching, or extend (e.g. CDN URL rewrite)
 *     in the future.
 *
 * @package HDL_Longevity_V2
 * @since 0.29.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HDLV2_Practitioner {

	/**
	 * HDL platform-level logo. Returned by get_logo_url() when the caller
	 * passes $fallback_to_default = true and the practitioner has not
	 * uploaded a logo of their own. Used by surfaces (Stage 1 result page
	 * email, Stage 1 PDF) where an empty <img src> would render a broken
	 * image; the cover of the v3 Final Report is NOT one of these — that
	 * surface prefers an empty string so the Liquid template can switch
	 * to the avatar lockup branch.
	 */
	const DEFAULT_LOGO_URL = 'https://healthdatalab.net/wp-content/uploads/2023/09/HDL-Logo-2309-4-d-sss.png';

	/**
	 * Resolve a practitioner's logo URL.
	 *
	 * Priority:
	 *   1. wp_hdlv2_widget_config.logo_url        (canonical — written by ajax_upload_logo)
	 *   2. user_meta.practitioner_logo_url        (legacy — read-only, kept to avoid losing
	 *                                              data for any practitioner whose URL was
	 *                                              ever migrated/imported into user_meta)
	 *
	 * For URLs that resolve to a path under wp-content/uploads (the path
	 * ajax_upload_logo writes to), validates the file exists on disk and
	 * returns '' if the file is missing. Remote URLs (e.g. CDN, third-party
	 * hosting) are trusted as-is — we don't attempt a HEAD request because
	 * (a) it would block the webhook firing path, (b) we have no network
	 * authority to validate third-party endpoints anyway.
	 *
	 * @param int  $practitioner_id     WP user ID. Non-positive returns ''.
	 * @param bool $fallback_to_default When true, returns DEFAULT_LOGO_URL if
	 *                                  the practitioner has no usable logo.
	 *                                  Default false (caller's preferred
	 *                                  no-logo behaviour wins — Final Report
	 *                                  cover wants empty so the template
	 *                                  renders the avatar lockup instead).
	 *
	 * @return string Logo URL, or '' if no logo and $fallback_to_default
	 *                is false, or DEFAULT_LOGO_URL if no logo and
	 *                $fallback_to_default is true.
	 */
	public static function get_logo_url( $practitioner_id, $fallback_to_default = false ) {
		$resolved = self::resolve_logo_url( (int) $practitioner_id );

		if ( $resolved !== '' ) {
			return esc_url_raw( $resolved );
		}

		return $fallback_to_default ? self::DEFAULT_LOGO_URL : '';
	}

	/**
	 * Internal: read + validate. Returns '' on any miss/invalid path so the
	 * caller's $fallback_to_default branch can fire from a single check.
	 *
	 * @param int $practitioner_id Sanitised user ID.
	 * @return string URL or empty string.
	 */
	private static function resolve_logo_url( $practitioner_id ) {
		if ( $practitioner_id <= 0 ) {
			return '';
		}

		global $wpdb;

		// Canonical read: per-practitioner widget_config row written by
		// HDLV2_Widget_Config::ajax_upload_logo().
		$logo_url = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT logo_url FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
			$practitioner_id
		) );

		// Legacy read: any practitioner whose logo URL was historically
		// written to user_meta (pre-widget-config era or via a migration).
		// Reading this is harmless when nothing is set; keeps us from
		// silently dropping any pre-existing data during the consolidation.
		if ( $logo_url === '' ) {
			$legacy   = get_user_meta( $practitioner_id, 'practitioner_logo_url', true );
			$logo_url = is_string( $legacy ) ? $legacy : '';
		}

		if ( $logo_url === '' ) {
			return '';
		}

		// Validate file existence for URLs that point inside our own uploads
		// directory. A 404 here means the file was deleted (manual cleanup,
		// failed migration, broken backup restore) but the row still points
		// at it. Returning '' lets PDF/email consumers fall through to their
		// no-logo branch — preferable to shipping a broken-image URL into
		// Make.com → PDFMonkey or a Brevo email.
		$upload_dir = wp_upload_dir();
		if ( is_array( $upload_dir ) && ! empty( $upload_dir['baseurl'] ) && ! empty( $upload_dir['basedir'] ) ) {
			$base_url = (string) $upload_dir['baseurl'];
			if ( strpos( $logo_url, $base_url ) === 0 ) {
				$rel = substr( $logo_url, strlen( $base_url ) );
				$abs = (string) $upload_dir['basedir'] . $rel;
				if ( ! is_file( $abs ) ) {
					return '';
				}
			}
		}

		return $logo_url;
	}

	/**
	 * Get the practitioner logo aspect-ratio shape ('square'|'wordmark'|'tall').
	 *
	 * v0.36.0 (Phase P) — pairs with get_logo_url(). Same priority chain:
	 * widget_config first, falls back to 'wordmark' for the HDL default
	 * (the 265x25 wordmark image). Used by every render surface that
	 * stamps a logo into a shape-aware container — widget, email, PDF.
	 *
	 * @param int  $practitioner_id     WP user ID.
	 * @param bool $fallback_to_default When true and no practitioner shape
	 *                                  is on file, returns 'wordmark'
	 *                                  (the shape of DEFAULT_LOGO_URL).
	 *                                  Pairs with get_logo_url's flag.
	 * @return string One of 'square', 'wordmark', 'tall'. Always non-empty.
	 */
	public static function get_logo_shape( $practitioner_id, $fallback_to_default = false ) {
		$practitioner_id = (int) $practitioner_id;
		if ( $practitioner_id <= 0 ) {
			return $fallback_to_default ? 'wordmark' : 'square';
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT logo_url, logo_shape FROM {$wpdb->prefix}hdlv2_widget_config WHERE practitioner_user_id = %d LIMIT 1",
			$practitioner_id
		) );
		// No row, or no practitioner-configured logo → caller is rendering
		// the HDL fallback wordmark.
		if ( ! $row || empty( $row->logo_url ) ) {
			return $fallback_to_default ? 'wordmark' : 'square';
		}
		$shape = (string) ( $row->logo_shape ?: 'square' );
		if ( ! in_array( $shape, array( 'square', 'wordmark', 'tall' ), true ) ) {
			$shape = 'square';
		}
		return $shape;
	}
}
