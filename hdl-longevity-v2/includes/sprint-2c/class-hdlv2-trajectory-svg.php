<?php
/**
 * HDL V2 — Trajectory Chart SVG Generator.
 *
 * Port of assets/js/hdl-trajectory-chart-hero.js to PHP for static PDF
 * embedding. Renders the same V1 "health over life" chart the client sees on
 * /longevity-draft-report/?t=<token> (via HDLTrajectoryChart.render) but as
 * a server-side SVG returned at a public URL that PDFMonkey can fetch and
 * embed as <img src=...>.
 *
 * Chart anatomy:
 *   • 9 population percentile bands (5th → 95th) as curves age 0-120
 *   • User's personal health curve from age 0 to current age (blue)
 *   • Optimistic projection (green dashed, "With changes")
 *   • Pessimistic projection (red dashed, "Without changes")
 *   • Zone shading for "Independence at risk" (20-50%) and "Critical" (0-20%)
 *   • "Now" vertical divider
 *   • Peak annotation on optimistic curve
 *   • Rate badge (coloured by severity)
 *
 * REST endpoint: GET /wp-json/hdl-v2/v1/trajectory-svg?chrono=<int>&rate=<float>
 *   Returns: image/svg+xml
 *   Cache: public, 1-year (chart is pure function of chrono+rate — no PII)
 *
 * Math: every function is a straight translation of the JS helpers in
 * hdl-trajectory-chart-hero.js. Keeping the same names so future divergence
 * between JS/PHP versions stays easy to spot.
 *
 * @package HDL_Longevity_V2
 * @since 0.20.17
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Trajectory_SVG {

    const PEAK_AGE         = 28;
    const CHART_MAX_AGE    = 120;
    const DECLINE_EXPONENT = 1.8;

    const CW = 780;
    const CH = 440;
    const MARGIN_TOP    = 30;
    const MARGIN_RIGHT  = 30;
    const MARGIN_BOTTOM = 50;
    const MARGIN_LEFT   = 55;

    /**
     * Population percentile bands. Each is a life-long health curve peaking
     * at PEAK_AGE with a given rate of decline. POP_IDX (4 = 50th percentile)
     * is emphasised in the render.
     */
    private static function bands() {
        return array(
            array( 'id' => 1, 'label' => '5th',  'rate' => 1.50, 'peak' => 62, 'endAge' => 64,  'color' => '#e74c3c' ),
            array( 'id' => 2, 'label' => '10th', 'rate' => 1.35, 'peak' => 66, 'endAge' => 69,  'color' => '#e67e22' ),
            array( 'id' => 3, 'label' => '20th', 'rate' => 1.20, 'peak' => 70, 'endAge' => 74,  'color' => '#f0a500' ),
            array( 'id' => 4, 'label' => '35th', 'rate' => 1.08, 'peak' => 75, 'endAge' => 78,  'color' => '#c4b34d' ),
            array( 'id' => 5, 'label' => '50th', 'rate' => 1.00, 'peak' => 80, 'endAge' => 79,  'color' => '#95a5a6' ),
            array( 'id' => 6, 'label' => '65th', 'rate' => 0.93, 'peak' => 84, 'endAge' => 87,  'color' => '#7dba7d' ),
            array( 'id' => 7, 'label' => '80th', 'rate' => 0.86, 'peak' => 88, 'endAge' => 96,  'color' => '#48a999' ),
            array( 'id' => 8, 'label' => '90th', 'rate' => 0.80, 'peak' => 91, 'endAge' => 105, 'color' => '#2d8e82' ),
            array( 'id' => 9, 'label' => '95th', 'rate' => 0.74, 'peak' => 94, 'endAge' => 115, 'color' => '#1a6b5a' ),
        );
    }

    const POP_IDX = 4; // 50th percentile — emphasised as "Population Avg"

    // ──────────────────────────────────────────────────────────────
    //  WP HOOKS
    // ──────────────────────────────────────────────────────────────

    public static function init() {
        // Serve via a plain query-param handler on `init` — bypasses REST's
        // JSON wrapping and the various filters that kept wrapping our raw
        // SVG bytes into a JSON-encoded string.
        add_action( 'init', array( __CLASS__, 'maybe_serve_svg' ), 1 );
    }

    public static function maybe_serve_svg() {
        if ( ! isset( $_GET['hdlv2_trajectory_svg'] ) ) return;

        $chrono = isset( $_GET['chrono'] ) ? (int) $_GET['chrono'] : 50;
        $rate   = isset( $_GET['rate'] )   ? (float) $_GET['rate'] : 1.0;
        $svg    = self::build( $chrono, $rate );

        while ( ob_get_level() ) ob_end_clean();
        nocache_headers();
        header( 'Content-Type: image/svg+xml; charset=utf-8' );
        header( 'Cache-Control: public, max-age=31536000, immutable' );
        header( 'X-Robots-Tag: noindex' );
        header( 'Content-Length: ' . strlen( $svg ) );
        echo $svg;
        exit;
    }

    /**
     * Public URL that Make.com receives in the webhook and that PDFMonkey
     * embeds via <img src=...>.
     */
    public static function url_for( $chrono, $rate ) {
        $chrono = max( 1, (int) $chrono );
        $rate   = max( 0.1, round( (float) $rate, 2 ) );
        return add_query_arg(
            array( 'hdlv2_trajectory_svg' => '1', 'chrono' => $chrono, 'rate' => $rate ),
            home_url( '/' )
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  CORE BAND MATH — verbatim port from hdl-trajectory-chart-hero.js
    // ──────────────────────────────────────────────────────────────

    private static function get_band_health( $band_idx, $age ) {
        $bands = self::bands();
        if ( ! isset( $bands[ $band_idx ] ) || $age < 0 ) return null;
        $b = $bands[ $band_idx ];

        if ( $age <= self::PEAK_AGE ) {
            $x = $age / self::PEAK_AGE;
            return $b['peak'] * $x * ( 2 - $x );
        }
        $bio_age = self::PEAK_AGE + ( $age - self::PEAK_AGE ) * $b['rate'];
        $yrs     = $bio_age - self::PEAK_AGE;
        $total   = ( $b['endAge'] * $b['rate'] ) - self::PEAK_AGE + 10;
        $prog    = min( 1, $yrs / $total );
        $curve   = pow( $prog, self::DECLINE_EXPONENT );
        $h       = $b['peak'] - ( $b['peak'] * $curve );
        return $h < 1 ? null : max( 0, $h );
    }

    private static function interpolate_health_for_rate( $age, $rate ) {
        $bands = self::bands();
        for ( $i = 0; $i < count( $bands ) - 1; $i++ ) {
            if ( $rate >= $bands[ $i + 1 ]['rate'] && $rate <= $bands[ $i ]['rate'] ) {
                $frac = ( $bands[ $i ]['rate'] - $rate ) / ( $bands[ $i ]['rate'] - $bands[ $i + 1 ]['rate'] );
                $hL   = self::get_band_health( $i,     $age );
                $hU   = self::get_band_health( $i + 1, $age );
                if ( $hL === null || $hU === null ) return null;
                return $hL + $frac * ( $hU - $hL );
            }
        }
        if ( $rate >= $bands[0]['rate'] ) return self::get_band_health( 0, $age );
        if ( $rate <= $bands[8]['rate'] ) return self::get_band_health( 8, $age );
        return self::get_band_health( 4, $age );
    }

    private static function generate_user_history( $effective_age, $rate ) {
        $pts = array();
        for ( $a = 0; $a <= $effective_age; $a += 0.5 ) {
            $h = self::interpolate_health_for_rate( $a, $rate );
            if ( $h === null ) break;
            $pts[] = array( 'age' => $a, 'health' => $h );
        }
        return $pts;
    }

    private static function band_path_data( $idx, $start_age = 0, $end_age = null ) {
        if ( $end_age === null ) $end_age = self::CHART_MAX_AGE;
        $pts = array();
        for ( $a = $start_age; $a <= $end_age; $a += 0.5 ) {
            $h = self::get_band_health( $idx, $a );
            if ( $h === null ) break;
            $pts[] = array( 'age' => $a, 'health' => $h );
        }
        return $pts;
    }

    private static function generate_optimistic( $start_age, $start_health, $current_rate ) {
        $improved_rate = max( 0.70, $current_rate * 0.75 );
        if ( $current_rate >= 1.1 ) {
            $improvement_potential = min( 18, ( $current_rate - 0.85 ) * 20 );
        } elseif ( $current_rate >= 1.0 ) {
            $improvement_potential = min( 12, ( $current_rate - 0.85 ) * 15 );
        } else {
            $improvement_potential = min( 7, max( 2, ( 100 - $start_health ) * 0.12 ) );
        }
        $ceiling        = min( 95, $start_health + $improvement_potential );
        $rise_duration  = min( 8, max( 3, $improvement_potential * 0.5 ) );
        if ( $improved_rate < 0.80 )      $end_age = 102;
        elseif ( $improved_rate < 0.85 )  $end_age = 98;
        elseif ( $improved_rate < 0.90 )  $end_age = 94;
        elseif ( $improved_rate < 0.95 )  $end_age = 90;
        elseif ( $improved_rate < 1.00 )  $end_age = 86;
        else                              $end_age = 80;
        $effective_end    = max( $start_age + 12, $end_age );
        $decline_duration = $effective_end - ( $start_age + $rise_duration );
        $peak_health      = $ceiling;
        $floor_health     = max( 30, $peak_health * 0.35 );

        $points = array();
        for ( $age = $start_age; $age <= $effective_end; $age += 0.5 ) {
            $yfn = $age - $start_age;
            if ( $yfn <= $rise_duration ) {
                $rp   = $yfn / $rise_duration;
                $sine = sin( $rp * M_PI / 2 );
                $h    = $start_health + ( $peak_health - $start_health ) * $sine;
            } else {
                $dy   = $yfn - $rise_duration;
                $dp   = min( 1, $dy / $decline_duration );
                $dc   = pow( $dp, 1.9 );
                $h    = $peak_health - ( $peak_health - $floor_health ) * $dc;
            }
            if ( $h < $floor_health ) break;
            $points[] = array( 'age' => $age, 'health' => $h );
        }
        return array(
            'points'        => $points,
            'peakHealth'    => $peak_health,
            'endAge'        => $effective_end,
            'improvedRate'  => $improved_rate,
        );
    }

    private static function generate_pessimistic( $start_age, $start_health, $current_rate ) {
        $worsened_rate = min( 1.65, $current_rate * 1.30 );
        if ( $worsened_rate >= 1.50 )      $end_age = 62;
        elseif ( $worsened_rate >= 1.35 )  $end_age = 67;
        elseif ( $worsened_rate >= 1.20 )  $end_age = 72;
        elseif ( $worsened_rate >= 1.10 )  $end_age = 76;
        elseif ( $worsened_rate >= 1.00 )  $end_age = 80;
        else                               $end_age = 85;
        $effective_end  = max( $start_age + 10, $end_age );
        $remaining      = $effective_end - $start_age;
        $floor_health   = 3;
        if ( $worsened_rate >= 1.30 )      $decline_exp = 1.4;
        elseif ( $worsened_rate >= 1.10 )  $decline_exp = 1.5;
        else                               $decline_exp = 1.6;

        $points = array();
        for ( $age = $start_age; $age <= $effective_end; $age += 0.5 ) {
            $yfn  = $age - $start_age;
            $prog = $yfn / $remaining;
            if ( $prog > 1.0 ) break;
            $dc     = pow( $prog, $decline_exp );
            $range  = $start_health - $floor_health;
            $h      = $start_health - ( $range * $dc );
            if ( $h < $floor_health ) break;
            $points[] = array( 'age' => $age, 'health' => max( $floor_health, $h ) );
        }
        return array(
            'points'        => $points,
            'endAge'        => $effective_end,
            'worsenedRate'  => $worsened_rate,
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  COORDINATE HELPERS
    // ──────────────────────────────────────────────────────────────

    private static function iw() { return self::CW - self::MARGIN_LEFT - self::MARGIN_RIGHT; }
    private static function ih() { return self::CH - self::MARGIN_TOP - self::MARGIN_BOTTOM; }

    private static function sx( $age ) {
        return self::MARGIN_LEFT + ( $age / self::CHART_MAX_AGE ) * self::iw();
    }

    private static function sy( $health ) {
        return self::MARGIN_TOP + self::ih() - ( $health / 100 ) * self::ih();
    }

    private static function make_path( $pts ) {
        $parts = array();
        foreach ( $pts as $p ) {
            if ( ! isset( $p['health'] ) || $p['health'] === null ) continue;
            $cmd = empty( $parts ) ? 'M' : 'L';
            $parts[] = $cmd . number_format( self::sx( $p['age'] ), 1, '.', '' ) . ',' .
                              number_format( self::sy( $p['health'] ), 1, '.', '' );
        }
        return implode( ' ', $parts );
    }

    private static function xml( $str ) {
        return htmlspecialchars( (string) $str, ENT_QUOTES | ENT_XML1, 'UTF-8' );
    }

    // ──────────────────────────────────────────────────────────────
    //  SVG BUILDER
    // ──────────────────────────────────────────────────────────────

    /**
     * Build the full SVG string.
     *
     * @param int   $chrono_age Current chronological age (int years).
     * @param float $rate       Current rate of ageing (e.g. 1.17).
     * @return string SVG markup.
     */
    public static function build( $chrono_age, $rate ) {
        $chrono_age = max( 1, (int) $chrono_age );
        $rate       = max( 0.1, (float) $rate );

        $effective_age  = $chrono_age;
        $current_health = self::interpolate_health_for_rate( $effective_age, $rate );
        if ( $current_health === null ) $current_health = 50;

        $user_history = self::generate_user_history( $effective_age, $rate );
        $optimistic   = self::generate_optimistic( $effective_age, $current_health, $rate );
        $pessimistic  = self::generate_pessimistic( $effective_age, $current_health, $rate );

        $cw = self::CW;
        $ch = self::CH;
        $ml = self::MARGIN_LEFT;
        $mr = self::MARGIN_RIGHT;
        $mt = self::MARGIN_TOP;
        $mb = self::MARGIN_BOTTOM;
        $iw = self::iw();
        $ih = self::ih();

        $sx_now = self::sx( $effective_age );
        $sy_now = self::sy( $current_health );

        // ── SVG HEADER + DEFS ──────────────────────────────────────
        $out  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $cw . ' ' . $ch . '" ';
        $out .= 'style="width:100%;height:auto;font-family:Inter,system-ui,-apple-system,sans-serif;background:#fff;">';

        $out .= '<defs>';
        $out .= '<linearGradient id="hdl-indepZone" x1="0" y1="0" x2="0" y2="1">';
        $out .=   '<stop offset="0%" stop-color="#94a3b8" stop-opacity="0.06"/>';
        $out .=   '<stop offset="100%" stop-color="#94a3b8" stop-opacity="0.15"/>';
        $out .= '</linearGradient>';
        $out .= '<linearGradient id="hdl-criticalZone" x1="0" y1="0" x2="0" y2="1">';
        $out .=   '<stop offset="0%" stop-color="#78879a" stop-opacity="0.18"/>';
        $out .=   '<stop offset="100%" stop-color="#64748b" stop-opacity="0.32"/>';
        $out .= '</linearGradient>';
        $out .= '<clipPath id="hdl-clipPast">';
        $out .=   '<rect x="' . $ml . '" y="' . $mt . '" width="' . max( 0, $sx_now - $ml ) . '" height="' . $ih . '"/>';
        $out .= '</clipPath>';
        $out .= '<clipPath id="hdl-clipFuture">';
        $out .=   '<rect x="' . $sx_now . '" y="' . $mt . '" width="' . max( 0, $cw - $mr - $sx_now ) . '" height="' . $ih . '"/>';
        $out .= '</clipPath>';
        $out .= '</defs>';

        // ── GRID LINES (horizontal = health%) ──────────────────────
        $health_grids = array( 0, 20, 40, 50, 60, 80, 100 );
        foreach ( $health_grids as $gh ) {
            $y  = self::sy( $gh );
            $sw = ( $gh === 50 ) ? 0.8 : 0.4;
            $sc = ( $gh === 50 ) ? '#cbd5e1' : '#eef1f5';
            $out .= '<line x1="' . $ml . '" x2="' . ( $cw - $mr ) . '" y1="' . $y . '" y2="' . $y . '" stroke="' . $sc . '" stroke-width="' . $sw . '"/>';
            $out .= '<text x="' . ( $ml - 7 ) . '" y="' . ( $y + 3 ) . '" text-anchor="end" font-size="11" fill="#94a3b8">' . $gh . '%</text>';
        }

        // ── GRID LINES (vertical = age) ────────────────────────────
        $age_grids = array( 0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120 );
        foreach ( $age_grids as $ga ) {
            $x = self::sx( $ga );
            $out .= '<line x1="' . $x . '" x2="' . $x . '" y1="' . $mt . '" y2="' . ( $ch - $mb ) . '" stroke="#eef1f5" stroke-width="0.4"/>';
            $out .= '<text x="' . $x . '" y="' . ( $ch - $mb + 14 ) . '" text-anchor="middle" font-size="11" fill="#94a3b8">' . $ga . '</text>';
        }

        // Axis labels
        $out .= '<text x="' . ( $cw / 2 ) . '" y="' . ( $ch - 5 ) . '" text-anchor="middle" font-size="12.5" fill="#64748b" font-weight="500">Age (years)</text>';
        $out .= '<text x="12" y="' . ( $ch / 2 ) . '" text-anchor="middle" font-size="12.5" fill="#64748b" font-weight="500" transform="rotate(-90, 12, ' . ( $ch / 2 ) . ')">Strength &amp; Wellbeing (%)</text>';

        // ── ZONES (Independence at risk + Critical) ────────────────
        $out .= '<rect x="' . $ml . '" y="' . self::sy( 50 ) . '" width="' . $iw . '" height="' . ( self::sy( 20 ) - self::sy( 50 ) ) . '" fill="url(#hdl-indepZone)"/>';
        $out .= '<rect x="' . $ml . '" y="' . self::sy( 20 ) . '" width="' . $iw . '" height="' . ( self::sy( 0 ) - self::sy( 20 ) ) . '" fill="url(#hdl-criticalZone)"/>';
        $out .= '<text x="' . ( $cw - $mr - 4 ) . '" y="' . self::sy( 35 ) . '" text-anchor="end" font-size="10" fill="#94a3b8" font-style="italic" opacity="0.6">Independence at risk</text>';
        $out .= '<text x="' . ( $cw - $mr - 4 ) . '" y="' . self::sy( 10 ) . '" text-anchor="end" font-size="10" fill="#78879a" font-style="italic" opacity="0.6">Critical</text>';

        // ── POPULATION BANDS (past + future clipped regions) ───────
        $bands = self::bands();

        $out .= '<g clip-path="url(#hdl-clipPast)">';
        foreach ( $bands as $bi => $b ) {
            $path  = self::make_path( self::band_path_data( $bi ) );
            $isPop = ( $bi === self::POP_IDX );
            $sw    = $isPop ? 2   : 0.9;
            $dash  = $isPop ? ''  : 'stroke-dasharray="3,3"';
            $op    = $isPop ? 0.6 : 0.4;
            $out  .= '<path d="' . $path . '" fill="none" stroke="' . $b['color'] . '" stroke-width="' . $sw . '" ' . $dash . ' opacity="' . $op . '"/>';
        }
        $out .= '</g>';

        $out .= '<g clip-path="url(#hdl-clipFuture)">';
        foreach ( $bands as $bi => $b ) {
            $path  = self::make_path( self::band_path_data( $bi ) );
            $isPop = ( $bi === self::POP_IDX );
            $sw    = $isPop ? 1.4  : 0.6;
            $dash  = $isPop ? 'stroke-dasharray="6,4"' : 'stroke-dasharray="3,4"';
            $op    = $isPop ? 0.3  : 0.15;
            $out  .= '<path d="' . $path . '" fill="none" stroke="' . $b['color'] . '" stroke-width="' . $sw . '" ' . $dash . ' opacity="' . $op . '"/>';
        }
        $out .= '</g>';

        // ── "NOW" DIVIDER ──────────────────────────────────────────
        $out .= '<line x1="' . $sx_now . '" x2="' . $sx_now . '" y1="' . $mt . '" y2="' . ( $ch - $mb ) . '" stroke="#0d7377" stroke-width="1" stroke-dasharray="4,3" opacity="0.35"/>';
        $out .= '<text x="' . $sx_now . '" y="' . ( $mt - 5 ) . '" text-anchor="middle" font-size="11" fill="#0d7377" font-weight="600" opacity="0.65">Now</text>';

        // Region labels
        $hist_mid_x = self::sx( $effective_age / 2 );
        $fut_mid_x  = $sx_now + ( $cw - $mr - $sx_now ) / 2;
        $out .= '<text x="' . $hist_mid_x . '" y="' . ( $mt + 12 ) . '" text-anchor="middle" font-size="11" fill="#94a3b8" opacity="0.5" font-weight="500">Your Health History</text>';
        $out .= '<text x="' . $fut_mid_x  . '" y="' . ( $mt + 12 ) . '" text-anchor="middle" font-size="11" fill="#94a3b8" opacity="0.5" font-weight="500">Future Projections</text>';

        // ── PESSIMISTIC (red dashed, "Without changes") ────────────
        if ( count( $pessimistic['points'] ) > 1 ) {
            $rlast = end( $pessimistic['points'] );
            $fill_d = self::make_path( $pessimistic['points'] )
                    . ' L' . number_format( self::sx( $rlast['age'] ), 1, '.', '' ) . ',' . number_format( self::sy( 0 ), 1, '.', '' )
                    . ' L' . number_format( $sx_now, 1, '.', '' ) . ',' . number_format( self::sy( 0 ), 1, '.', '' ) . ' Z';
            $out .= '<path d="' . $fill_d . '" fill="rgba(217,79,79,0.04)"/>';
            $out .= '<path d="' . self::make_path( $pessimistic['points'] ) . '" fill="none" stroke="#d94f4f" stroke-width="2.2" stroke-dasharray="8,4" opacity="0.75"/>';
        }

        // ── OPTIMISTIC (green dashed, "With changes" + Peak label) ─
        if ( count( $optimistic['points'] ) > 1 ) {
            $glast = end( $optimistic['points'] );
            $fill_d = self::make_path( $optimistic['points'] )
                    . ' L' . number_format( self::sx( $glast['age'] ), 1, '.', '' ) . ',' . number_format( self::sy( 0 ), 1, '.', '' )
                    . ' L' . number_format( $sx_now, 1, '.', '' ) . ',' . number_format( self::sy( 0 ), 1, '.', '' ) . ' Z';
            $out .= '<path d="' . $fill_d . '" fill="rgba(39,174,96,0.04)"/>';
            $out .= '<path d="' . self::make_path( $optimistic['points'] ) . '" fill="none" stroke="#27ae60" stroke-width="2.4" stroke-dasharray="5,2,5,2" opacity="0.8"/>';

            // Peak marker
            $peak = $optimistic['points'][0];
            foreach ( $optimistic['points'] as $p ) {
                if ( $p['health'] > $peak['health'] ) $peak = $p;
            }
            $peak_label_y = self::sy( min( 98, $peak['health'] + 8 ) );
            $out .= '<g opacity="0.85">';
            $out .=   '<circle cx="' . self::sx( $peak['age'] ) . '" cy="' . self::sy( $peak['health'] ) . '" r="2.5" fill="#27ae60"/>';
            $out .=   '<line x1="' . self::sx( $peak['age'] ) . '" y1="' . ( self::sy( $peak['health'] ) - 3 ) . '" ';
            $out .=       'x2="' . ( self::sx( $peak['age'] ) + 14 ) . '" y2="' . ( $peak_label_y + 3 ) . '" ';
            $out .=       'stroke="#27ae60" stroke-width="0.6" opacity="0.5"/>';
            $out .=   '<text x="' . ( self::sx( $peak['age'] ) + 16 ) . '" y="' . $peak_label_y . '" font-size="11" fill="#27ae60" font-weight="600">Peak ' . round( $peak['health'] ) . '%</text>';
            $out .= '</g>';
        }

        // ── USER HISTORY LINE (blue, age 0 → now) ──────────────────
        if ( count( $user_history ) > 1 ) {
            $out .= '<path d="' . self::make_path( $user_history ) . '" fill="none" stroke="#1565c0" stroke-width="2.8" stroke-linecap="round"/>';
        }

        // ── ANCHOR DOT ─────────────────────────────────────────────
        $out .= '<circle cx="' . $sx_now . '" cy="' . $sy_now . '" r="7" fill="white" stroke="#1565c0" stroke-width="2.5"/>';
        $out .= '<circle cx="' . $sx_now . '" cy="' . $sy_now . '" r="3" fill="#1565c0"/>';

        // ── RATE BADGE ─────────────────────────────────────────────
        // v0.34.1 — 4-band logic aligned with derive_rate_band() in
        // class-hdlv2-final-report.php. Previously `>= 1.0` triggered
        // "Accelerated" + amber, which made Mary's PDF render
        // "⚠ Accelerated Ageing · Rate: 1.00×" — wrong for someone tracking
        // the calendar exactly. Bands now match the Page 3/5/6 pill colours.
        if ( $rate <= 0.95 ) {
            // slow → optimal green ✓
            $badge_bg   = '#10b981';
            $badge_icon = '✓';
            $badge_lbl  = 'Slower';
        } elseif ( $rate <= 1.05 ) {
            // average → neutral teal
            $badge_bg   = '#3d8da0';
            $badge_icon = '·';
            $badge_lbl  = 'Average';
        } elseif ( $rate <= 1.15 ) {
            // fast → watch amber ⚠
            $badge_bg   = '#d97706';
            $badge_icon = '⚠';
            $badge_lbl  = 'Accelerated';
        } else {
            // very-fast → concern red ⚠
            $badge_bg   = '#dc2626';
            $badge_icon = '⚠';
            $badge_lbl  = 'Significantly Accelerated';
        }
        $badge_y    = min( $sy_now + 50, $ch - $mb - 42 );
        // v0.34.2 — Wider badge for the longer "Significantly Accelerated
        // Ageing" label. Previous 175px was ~30px too narrow at 11pt
        // sans-serif and the "ng" in "Ageing" got clipped on Mike's PDF.
        // Bumped to 220px + repositioned badge_x so the leader line lands
        // on the badge centre.
        if ( $rate > 1.15 ) {
            $badge_w = 220;
            $badge_x = $sx_now - 234;
        } else {
            $badge_w = 135;
            $badge_x = $sx_now - 149;
        }

        $out .= '<g>';
        $out .=   '<line x1="' . $sx_now . '" y1="' . ( $sy_now + 7 ) . '" x2="' . ( $badge_x + intval( $badge_w / 2 ) ) . '" y2="' . $badge_y . '" stroke="' . $badge_bg . '" stroke-width="0.7" opacity="0.35" stroke-dasharray="3,2"/>';
        $out .=   '<rect x="' . $badge_x . '" y="' . $badge_y . '" width="' . $badge_w . '" height="36" rx="5" fill="' . $badge_bg . '"/>';
        $out .=   '<text x="' . ( $badge_x + 7 ) . '" y="' . ( $badge_y + 14 ) . '" font-size="11" fill="rgba(255,255,255,0.88)" font-weight="500">' . self::xml( $badge_icon ) . ' ' . $badge_lbl . ' Ageing</text>';
        $out .=   '<text x="' . ( $badge_x + 7 ) . '" y="' . ( $badge_y + 28 ) . '" font-size="13.5" fill="white" font-weight="700">Rate: ' . number_format( $rate, 2 ) . '×</text>';
        $out .= '</g>';

        // ── LEGEND (below chart, inside SVG so PDF embed picks it up) ─
        $legend_y = $ch - 20;
        $legend_items = array(
            array( 'color' => '#1565c0', 'label' => 'Your Trajectory',    'dash' => false, 'width' => 2.5 ),
            array( 'color' => '#95a5a6', 'label' => 'Population Avg',     'dash' => false, 'width' => 1.5 ),
            array( 'color' => '#27ae60', 'label' => 'With Changes',       'dash' => true,  'width' => 2 ),
            array( 'color' => '#d94f4f', 'label' => 'Without Changes',    'dash' => true,  'width' => 2 ),
        );
        // Approx width budgeting — each item takes ~140px, center the group.
        $item_w  = 150;
        $total_w = count( $legend_items ) * $item_w;
        $start_x = ( $cw - $total_w ) / 2;
        $out .= '<g font-size="11" fill="#64748b">';
        foreach ( $legend_items as $li => $item ) {
            $ix = $start_x + $li * $item_w;
            $dash_attr = $item['dash'] ? ' stroke-dasharray="6,3"' : '';
            $out .= '<line x1="' . $ix . '" x2="' . ( $ix + 22 ) . '" y1="' . $legend_y . '" y2="' . $legend_y . '" stroke="' . $item['color'] . '" stroke-width="' . $item['width'] . '"' . $dash_attr . ' stroke-linecap="round"/>';
            $out .= '<text x="' . ( $ix + 28 ) . '" y="' . ( $legend_y + 4 ) . '">' . self::xml( $item['label'] ) . '</text>';
        }
        $out .= '</g>';

        $out .= '</svg>';
        return $out;
    }
}

HDLV2_Trajectory_SVG::init();
