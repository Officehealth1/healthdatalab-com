<?php
/**
 * Single-form WHY picks — catalogue, switch, validation, composed text.
 *
 * Slice 1 of the longevity form rethink (Matthew, 2026-10-02): after Stage 1
 * a covered client answers ONE questionnaire. Its first page replaces the
 * recorded/typed WHY with 5–10 statements picked from this catalogue, a box
 * for the people who matter and an optional own-words line. The server turns
 * those into one first-person paragraph (vision_text) and runs the EXISTING
 * Claude WHY extraction on it, so every why_profiles reader stays unchanged.
 *
 * Switch: option `hdlv2_ff_single_form` — absent/empty = off; a comma list of
 * practitioner user IDs = on for their clients; `all` = everyone. Once a row
 * has submitted the picks it carries `form_flow: "single"` in stage2_data and
 * that marker, not the option, decides its handling.
 *
 * Pure functions, no database writes. Ids are permanent (rows store them): a
 * dropped statement is marked `retired` (a fourth slot, `true`), never deleted.
 *
 * Slice 2 (v0.47.93): every statement stands for abilities (NEEDS), and a
 * fixed table (MEASURES) names the health answers that measure each one.
 * abilities_profile() joins a client's choices to their saved scores, fresh
 * each time and never stored, for the picks page, the practitioner's WHY tab
 * and the draft + milestones prompts. Tags and table are draft for Matthew.
 *
 * v0.48.11 (Matthew, 2026-10-09): six abilities, not eight (flexibility is
 * read as mobility, energy as stamina, through ALIASES, so saved rows and
 * retired statements are never rewritten). Each tag carries a weight 1-3
 * (`mobility:3`; a bare tag reads as 2) and an ability's need is the share
 * of the chosen life that leans on it, 0 to 5 whatever the number of picks.
 *
 * @package HDL_Longevity_V2
 * @since 0.47.92
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class HDLV2_Why_Picks {

    const MIN_PICKS          = 5;
    const MAX_PICKS          = 10;
    const MAX_PEOPLE_LEN     = 300;
    const MAX_OWN_WORDS_LEN  = 600;
    const OPTION             = 'hdlv2_ff_single_form';
    const FLOW               = 'single';

    /** Ability ids, in the order they are listed and ties are broken. */
    const NEEDS = array( 'strength', 'mobility', 'balance', 'stamina', 'mind', 'connection' );

    const ABILITY_LABELS = array(
        'strength'   => 'Strength',
        'mobility'   => 'Mobility and flexibility',
        'balance'    => 'Balance',
        'stamina'    => 'Stamina and energy',
        'mind'       => 'A sharp mind',
        'connection' => 'Connection',
    );

    /** v0.48.0 — one-word names for the chart on the picks page. */
    const ABILITY_SHORT = array(
        'strength'   => 'Strength',
        'mobility'   => 'Mobility',
        'balance'    => 'Balance',
        'stamina'    => 'Stamina',
        'mind'       => 'Mind',
        'connection' => 'Connection',
    );

    /** v0.48.11 — ids merged into another; tags are read through this. */
    const ALIASES = array( 'flexibility' => 'mobility', 'energy' => 'stamina' );

    /** Weight of a tag written without one (`stamina` = `stamina:2`). */
    const DEFAULT_WEIGHT = 2;
    const MAX_WEIGHT     = 3;
    const MAX_NEED       = 5;

    /**
     * v0.48.12 — the projection to 90 (Matthew, 2026-10-09: "every decade
     * you lose ... Twenty percent I think. Anyway, double check that.").
     * Research puts strength loss at about 15% a decade in the fifties and
     * about 26% after 60 (von Haehling, Morley, Anker 2010), so 0.20 is his
     * round figure and his to change. Same for the target age.
     */
    const LOSS_PER_DECADE = 0.20;
    const TARGET_AGE      = 90;

    /**
     * Which saved answers measure each ability. `stage3` keys are
     * HDLV2_Rate_Calculator::calculate_full() scores (0-5), `stage1` keys are
     * calculate_quick() raw scores (1-5); tests/single-form checks every key
     * against the real calculator.
     */
    const MEASURES = array(
        'strength'    => array(
            array( 'source' => 'stage3', 'key' => 'sitToStand', 'label' => 'Chair stand (30 seconds)' ),
            array( 'source' => 'stage1', 'key' => 'q5_sts', 'label' => 'Getting up from the floor' ),
        ),
        'mobility'    => array(
            array( 'source' => 'stage1', 'key' => 'q5_sts', 'label' => 'Getting up from the floor' ),
        ),
        'balance'     => array(
            array( 'source' => 'stage3', 'key' => 'balance', 'label' => 'Standing on one leg' ),
        ),
        'stamina'     => array(
            array( 'source' => 'stage3', 'key' => 'physicalActivity', 'label' => 'Physical activity' ),
            array( 'source' => 'stage1', 'key' => 'q4_vo2', 'label' => 'Climbing stairs' ),
            array( 'source' => 'stage3', 'key' => 'heartRateScore', 'label' => 'Resting heart rate' ),
            array( 'source' => 'stage3', 'key' => 'sleepDuration', 'label' => 'Sleep duration' ),
            array( 'source' => 'stage3', 'key' => 'sleepQuality', 'label' => 'Sleep quality' ),
            array( 'source' => 'stage3', 'key' => 'dietQuality', 'label' => 'Diet' ),
        ),
        'mind'        => array(
            array( 'source' => 'stage3', 'key' => 'cognitiveActivity', 'label' => 'Mental activity' ),
            array( 'source' => 'stage3', 'key' => 'stressLevels', 'label' => 'Stress' ),
            array( 'source' => 'stage3', 'key' => 'sleepQuality', 'label' => 'Sleep quality' ),
        ),
        'connection'  => array(
            array( 'source' => 'stage3', 'key' => 'socialConnections', 'label' => 'Time with people' ),
        ),
    );

    /** An ability is a focus area only when its measured score is at or under this. */
    const FOCUS_MAX_SCORE = 3.0;
    const FOCUS_LIMIT     = 3;

    /**
     * Groups in display order: title, short title, items. Tags are
     * `ability:weight` (weight 1-3: 3 = the statement leans on it, 1 = it
     * helps a little), in NEEDS order; v0.48.11 weights are draft for Matthew.
     * v0.48.0 (Matthew, 2026-10-05: fewer words, an emotional pull, three
     * columns): the page offers 24 short statements in three groups. Every
     * earlier statement stays below, retired, with its old wording and tags,
     * so saved rows keep reading it. Draft copy for Matthew to edit (labels
     * may be reworded; ids may not change).
     */
    public static function catalogue() {
        $groups = array(
            'people' => array( 'People I love', 'People I love', array(
                array( 'floor_grandkids', 'Play on the floor with my grandkids', 'strength:2,mobility:3,balance:1,stamina:1,connection:1' ),
                array( 'lift_grandchild', 'Pick up a grandchild for a hug', 'strength:3,mobility:1,balance:2' ),
                array( 'fetch_with_dog', 'Play fetch with the dog in the park', 'strength:1,mobility:1,balance:1,stamina:3' ),
                array( 'dance_wedding', 'Dance with my grandkids at their wedding', 'mobility:2,balance:2,stamina:2,connection:2' ),
                array( 'remember_family', 'Remember every grandchild\'s name and story', 'mind:3,connection:2' ),
                array( 'help_loved_one', 'Help a loved one who has hurt themselves', 'strength:3,mobility:2,balance:2,mind:1,connection:1' ),
                array( 'not_a_burden', 'Never burden loved ones in my last decade', 'strength:2,mobility:2,balance:2,stamina:1,mind:2,connection:1' ),
            ) ),
            'freedom' => array( 'My freedom', 'My freedom', array(
                array( 'live_in_own_home', 'Stay in my own home, on my terms', 'strength:2,mobility:2,balance:3,stamina:1,mind:3' ),
                array( 'stairs_no_rail', 'Walk anywhere and climb stairs with confidence', 'strength:2,mobility:2,balance:3,stamina:2' ),
                array( 'up_after_fall', 'Feel robust and get back up by myself if I trip', 'strength:3,mobility:3,balance:2' ),
                array( 'keep_driving', 'Drive safely', 'mobility:2,balance:1,mind:3' ),
                array( 'manage_own_affairs', 'Stay sharp and run my life with enthusiasm', 'stamina:2,mind:3,connection:1' ),
                array( 'dress_myself', 'Wash and dress myself, with dignity', 'strength:1,mobility:3,balance:2' ),
                array( 'uneven_ground', 'Live without fear of falling', 'strength:2,mobility:1,balance:3' ),
            ) ),
            'things' => array( 'Things I love', 'Things I love', array(
                array( 'keep_my_sport', 'Keep playing the sport I love', 'strength:2,mobility:3,balance:2,stamina:3' ),
                array( 'day_in_garden', 'Spend a whole day pottering in my garden', 'strength:2,mobility:3,balance:1,stamina:2' ),
                array( 'hike_hills', 'Hike the hills with friends', 'strength:1,mobility:1,balance:2,stamina:3,connection:2' ),
                array( 'swim_in_sea', 'Swim in the sea', 'strength:2,mobility:2,stamina:3' ),
                array( 'walk_city_all_day', 'Explore new places', 'mobility:1,stamina:3,mind:1' ),
                array( 'keep_working', 'Keep doing work that matters to me', 'stamina:2,mind:3,connection:1' ),
                array( 'wake_rested', 'Wake up full of energy', 'stamina:3,mind:1' ),
            ) ),
            // Retired in v0.48.0 (or earlier), in their 0.47.99 order. Never offered.
            'retired' => array( 'Earlier statements', 'Earlier statements', array(
                array( 'host_family_meal', 'Cook and host a big family meal on my own', 'stamina,connection', true ),
                array( 'carer_not_cared_for', 'Be the one who looks after my partner, not the one being looked after', 'strength,stamina', true ),
                array( 'teach_grandchild', 'Teach a grandchild something I am good at', 'mind,connection', true ),
                array( 'carry_shopping', 'Carry my own shopping from the car to the kitchen', 'strength', true ),
                array( 'up_from_low_seat', 'Get up from a low sofa without using my hands', 'strength,mobility', true ),
                array( 'bath_shower_alone', 'Get in and out of the bath or shower safely on my own', 'balance,mobility', true ),
                array( 'reach_top_cupboard', 'Reach the top cupboard and lift something down', 'flexibility,strength', true ),
                array( 'walk_to_shops', 'Walk to the shops and back without stopping to rest', 'stamina', true ),
                array( 'lift_suitcase', 'Lift my own suitcase into the overhead locker', 'strength', true ),
                array( 'long_flight', 'Take a long flight and feel fine the next day', 'energy', true ),
                array( 'trip_short_notice', 'Say yes to a trip at short notice', 'energy,stamina', true ),
                array( 'ride_bicycle', 'Ride a bicycle', 'balance,stamina', true ),
                array( 'visit_far_family', 'Travel to see family or friends who live far away', 'stamina,connection', true ),
                array( 'run_for_bus', 'Run for a bus or a train, and catch it', 'stamina', true ),
                array( 'dance_regularly', 'Dance regularly', 'balance,stamina', true ),
                array( 'do_own_diy', 'Do my own repairs at home: climb a ladder, carry tools', 'balance,strength', true ),
                array( 'walk_dog_daily', 'Walk the dog every day, whatever the weather', 'stamina', true ),
                array( 'sit_on_floor', 'Sit cross-legged on the floor and get up easily', 'flexibility,mobility', true ),
                array( 'steady_hands_craft', 'Keep playing my instrument or doing my craft with steady hands', 'mind', true ),
                array( 'intimate_life', 'Keep an active intimate life with my partner', 'stamina,energy', true ),
                array( 'sharp_conversation', 'Stay sharp in conversation and follow a fast discussion', 'mind', true ),
                array( 'read_for_an_hour', 'Read a book for an hour without losing concentration', 'mind', true ),
                array( 'volunteer_mentor', 'Volunteer or mentor, and be useful to others', 'energy,connection', true ),
                array( 'never_lost_for_words', 'Find the right word or name the moment I want it', 'mind', true ),
                array( 'write_life_story', 'Write down my life story for my family', 'mind', true ),
                array( 'energy_in_evening', 'Have energy left in the evening for the people I love', 'energy', true ),
                array( 'move_without_aches', 'Move through the day without aches deciding what I do', 'mobility', true ),
                array( 'steady_in_body', 'Feel confident and steady in my own body', 'balance,strength', true ),
                array( 'calm_under_pressure', 'Stay calm and good-humoured under pressure', 'mind', true ),
                // Retired in v0.48.9 (Matthew's wording, 2026-10-07), in their 0.48.0 order.
                array( 'keep_up_children', 'Chase the kids around the park', 'stamina', true ),
                array( 'walk_with_partner', 'Walk hand in hand with my partner', 'stamina,connection', true ),
                array( 'see_friends_weekly', 'Laugh with my friends every week', 'connection', true ),
                array( 'present_big_moments', 'Be there for every big family moment', 'energy,connection', true ),
                array( 'learn_something_new', 'Learn something new every year', 'mind', true ),
            ) ),
        );

        $out = array();
        foreach ( $groups as $gid => $g ) {
            $items = array();
            foreach ( $g[2] as $it ) {
                $weights = self::parse_tags( $it[2] );
                $items[] = array( 'id' => $it[0], 'label' => $it[1], 'needs' => array_keys( $weights ), 'weights' => $weights, 'retired' => ! empty( $it[3] ) );
            }
            $out[] = array( 'id' => $gid, 'title' => $g[0], 'short' => $g[1], 'items' => $items );
        }
        return $out;
    }

    /**
     * v0.48.11 — 'strength:3,balance' → [ strength => 3, balance => 2 ], ids
     * read through ALIASES. An id named twice (flexibility + mobility) keeps
     * its larger weight, so a merge never counts one statement twice. A
     * weight outside 1-MAX_WEIGHT is pulled inside it.
     */
    public static function parse_tags( $tags ) {
        $out = array();
        foreach ( explode( ',', (string) $tags ) as $tag ) {
            $parts = explode( ':', trim( $tag ), 2 );
            $id    = self::ALIASES[ $parts[0] ] ?? $parts[0];
            $w     = isset( $parts[1] ) ? max( 1, min( self::MAX_WEIGHT, (int) $parts[1] ) ) : self::DEFAULT_WEIGHT;
            $out[ $id ] = max( $out[ $id ] ?? 0, $w );
        }
        return $out;
    }

    /** What /form/load sends the page: groups with active items, and only those items. */
    public static function options_for_page() {
        $out = array();
        foreach ( static::catalogue() as $g ) {
            $items = array();
            foreach ( $g['items'] as $it ) {
                if ( empty( $it['retired'] ) ) $items[] = array( 'id' => $it['id'], 'label' => $it['label'], 'needs' => $it['needs'], 'weights' => $it['weights'] );
            }
            if ( $items ) $out[] = array( 'id' => $g['id'], 'title' => $g['title'], 'short' => $g['short'], 'items' => $items );
        }
        return $out;
    }

    public static function enabled_for( $practitioner_id ) {
        // A row with no practitioner has nobody who opted in (and no one to
        // read the WHY), so it stays on today's form even under `all`.
        $practitioner_id = (int) $practitioner_id;
        if ( $practitioner_id <= 0 ) return false;
        $raw = trim( (string) get_option( self::OPTION, '' ) );
        if ( '' === $raw ) return false;
        if ( 'all' === strtolower( $raw ) ) return true;
        foreach ( explode( ',', $raw ) as $part ) {
            $part = trim( $part );
            if ( ctype_digit( $part ) && (int) $part === $practitioner_id ) return true;
        }
        return false;
    }

    /**
     * True when the row has submitted the picks (marker), or when the switch
     * covers its practitioner and its WHY is still open at stage 1 or 2.
     */
    public static function row_uses_single_form( $progress ) {
        if ( self::is_single( json_decode( (string) ( $progress->stage2_data ?? '' ), true ) ) ) return true;
        return empty( $progress->stage2_completed_at )
            && (int) ( $progress->current_stage ?? 0 ) <= 2
            && self::enabled_for( (int) ( $progress->practitioner_user_id ?? 0 ) );
    }

    /** Whether decoded stage2_data carries the single-form marker. */
    public static function is_single( $stage2_data ) {
        return is_array( $stage2_data ) && self::FLOW === ( $stage2_data['form_flow'] ?? '' );
    }

    /** @return true|WP_Error */
    public static function validate( $data ) {
        $picks = $data['why_picks'] ?? null;
        if ( ! is_array( $picks ) || count( $picks ) < self::MIN_PICKS ) {
            return self::error( 'Please choose at least ' . self::MIN_PICKS . '.' );
        }
        if ( count( $picks ) > self::MAX_PICKS ) {
            return self::error( 'Please choose no more than ' . self::MAX_PICKS . '.' );
        }
        $active = array();
        foreach ( static::catalogue() as $g ) {
            foreach ( $g['items'] as $it ) {
                if ( empty( $it['retired'] ) ) $active[ $it['id'] ] = true;
            }
        }
        $seen = array();
        foreach ( $picks as $id ) {
            if ( ! is_string( $id ) || ! isset( $active[ $id ] ) || isset( $seen[ $id ] ) ) {
                return self::error( 'Please choose from the list.' );
            }
            $seen[ $id ] = true;
        }
        $people = $data['key_people_text'] ?? '';
        $own    = $data['own_words'] ?? '';
        if ( ! is_string( $people ) || mb_strlen( $people ) > self::MAX_PEOPLE_LEN ) {
            return self::error( 'Please keep the names to ' . self::MAX_PEOPLE_LEN . ' characters.' );
        }
        if ( ! is_string( $own ) || mb_strlen( $own ) > self::MAX_OWN_WORDS_LEN ) {
            return self::error( 'Please keep your own words to ' . self::MAX_OWN_WORDS_LEN . ' characters.' );
        }
        return true;
    }

    /** The first-person paragraph the existing WHY extraction reads. */
    public static function compose_vision_text( $pick_ids, $people, $own_words ) {
        $labels = self::labels( $pick_ids );
        $text   = "In my later years, I want to:\n- " . implode( "\n- ", array_column( $labels, 'label' ) );
        $people = self::plain( $people, self::MAX_PEOPLE_LEN );
        $own    = self::plain( $own_words, self::MAX_OWN_WORDS_LEN );
        if ( '' !== $people ) $text .= "\nThe people I most want to stay healthy and strong for: " . $people;
        if ( '' !== $own )    $text .= "\nIn my own words: " . $own;
        return $text;
    }

    /**
     * What the chosen statements depend on, joined to the saved scores.
     * Abilities with at least one choice, in NEEDS order:
     * [ id, label, count (choices that need it), need (0-5, see tally()),
     * score (1-decimal average of the measures that have a number, or null),
     * measures [ label, score|null, source ], focus ]. Focus = the
     * FOCUS_LIMIT abilities with a score at or under FOCUS_MAX_SCORE and the
     * highest need x (5 - score). A missing number is never filled in.
     * v0.48.12 — with the client's age, each row also carries projection().
     *
     * @param array $pick_ids      stage2_data.why_picks
     * @param array $stage1_raw    stage1_data.server_result.raw
     * @param array $stage3_scores stage3_data.server_result.scores
     */
    public static function abilities_profile( $pick_ids, $stage1_raw = array(), $stage3_scores = array(), $age = null ) {
        $out = array();
        foreach ( self::tally( self::labels( $pick_ids ) ) as $id => $t ) {
            $m         = self::measured( $id, $stage1_raw, $stage3_scores );
            $out[ $id ] = array(
                'id'       => $id,
                'label'    => self::ABILITY_LABELS[ $id ],
                'count'    => $t['count'],
                'need'     => $t['need'],
                'score'    => $m['score'],
                'measures' => $m['measures'],
                'focus'    => false,
            ) + self::projection( $t['need'], $m['score'], $age );
        }
        foreach ( self::focus_ids( $out ) as $id ) $out[ $id ]['focus'] = true;
        return array_values( $out );
    }

    /**
     * v0.48.11 — per ability the picks need, in NEEDS order, abilities no
     * pick needs left out: count = choices that need it, need = the share of
     * the choices that lean on it, 5 x (sum of its weights) / (MAX_WEIGHT x
     * picks), 1 decimal. 5 only when every pick leans on it fully, so the
     * number of picks never fills the chart. Worked out on whole numbers
     * first so the page (hdlv2-staged-form.js abilityCounts) rounds the same.
     *
     * @param array $items labels() entries.
     */
    private static function tally( $items ) {
        $sum = array_fill_keys( self::NEEDS, 0 ); $count = $sum;
        foreach ( $items as $it ) {
            foreach ( $it['weights'] as $n => $w ) {
                if ( ! isset( $sum[ $n ] ) ) continue;
                $sum[ $n ] += $w;
                $count[ $n ]++;
            }
        }
        $out = array();
        foreach ( array_filter( $count ) as $n => $c ) {
            $need      = round( self::MAX_NEED * 10 * $sum[ $n ] / ( self::MAX_WEIGHT * count( $items ) ) ) / 10;
            $out[ $n ] = array( 'count' => $c, 'sum' => $sum[ $n ], 'need' => min( (float) self::MAX_NEED, $need ) );
        }
        return $out;
    }

    /** One ability's measures from the saved answers, and their 1-decimal average (null when none has a number). */
    private static function measured( $id, $stage1_raw, $stage3_scores ) {
        $answers  = array( 'stage1' => (array) $stage1_raw, 'stage3' => (array) $stage3_scores );
        $measures = array(); $numbers = array();
        foreach ( self::MEASURES[ $id ] as $m ) {
            $v = $answers[ $m['source'] ][ $m['key'] ] ?? null;
            $v = is_numeric( $v ) ? $v + 0 : null;
            if ( null !== $v ) $numbers[] = $v;
            $measures[] = array( 'label' => $m['label'], 'score' => $v, 'source' => $m['source'] );
        }
        return array( 'measures' => $measures, 'score' => $numbers ? round( array_sum( $numbers ) / count( $numbers ), 1 ) : null );
    }

    /**
     * v0.48.12 — Matthew's "age adjusted redundancy", on the 0-5 scale:
     * at_90 = today's score after LOSS_PER_DECADE for each decade to
     * TARGET_AGE (null when unmeasured); need_now = what the need asks for
     * today so that it is still there at TARGET_AGE, capped at MAX_NEED
     * (need_now_capped says the cap was hit). Arithmetic on one assessment,
     * not a measured trend. No usable age: both null.
     */
    public static function projection( $need, $score, $age ) {
        if ( ! is_numeric( $age ) || $age <= 0 ) return array( 'at_90' => null, 'need_now' => null, 'need_now_capped' => false );
        $keep = pow( 1 - self::LOSS_PER_DECADE, max( 0, ( self::TARGET_AGE - $age ) / 10 ) );
        $want = $need / $keep;
        return array(
            'at_90'           => null === $score ? null : round( $score * $keep, 1 ),
            'need_now'        => round( min( (float) self::MAX_NEED, $want ), 1 ),
            'need_now_capped' => $want > self::MAX_NEED,
        );
    }

    /** The client's age from decoded stage1_data, or null. */
    public static function age_of( $stage1_data ) {
        $age = is_array( $stage1_data ) ? ( $stage1_data['q1_age'] ?? $stage1_data['age'] ?? null ) : null;
        return is_numeric( $age ) && $age > 0 ? $age + 0 : null;
    }

    /**
     * v0.48.12 — the abilities chart's two series, one source for the report
     * page (Chart.js) and the PDFs (chart_url()): every ability in NEEDS
     * order, needed = its need (0 when no choice needs it), now = its
     * measured score (null when nothing measured it), scale 5.
     */
    public static function ability_chart_series( $profile, $stage1_raw = array(), $stage3_scores = array() ) {
        $need = array_column( (array) $profile, 'need', 'id' );
        $out  = array( 'labels' => array(), 'needed' => array(), 'now' => array(), 'scale' => self::MAX_NEED );
        foreach ( self::NEEDS as $id ) {
            $out['labels'][] = self::ABILITY_SHORT[ $id ];
            $out['needed'][] = (float) ( $need[ $id ] ?? 0 );
            $out['now'][]    = self::measured( $id, $stage1_raw, $stage3_scores )['score'];
        }
        return $out;
    }

    /**
     * QuickChart picture of ability_chart_series() for the PDFs, drawn like
     * HDLV2_Final_Report::build_radar_chart_url() (Chart.js v4, 0-5, 540px).
     * '' when fewer than two abilities have a number: no picture beats a
     * one-spoke "you now".
     */
    public static function chart_url( $series ) {
        if ( count( array_filter( (array) ( $series['now'] ?? array() ), 'is_numeric' ) ) < 2 ) return '';
        $cfg = array(
            'type'    => 'radar',
            'data'    => array(
                'labels'   => $series['labels'],
                'datasets' => array(
                    array( 'label' => 'What your choices need', 'data' => $series['needed'], 'fill' => true,
                        'backgroundColor' => 'rgba(61,141,160,0.15)', 'borderColor' => '#3d8da0', 'borderWidth' => 2,
                        'pointBackgroundColor' => '#3d8da0', 'pointRadius' => 3 ),
                    array( 'label' => 'You now', 'data' => $series['now'], 'fill' => false,
                        'borderColor' => '#d97706', 'borderWidth' => 2, 'borderDash' => array( 6, 4 ),
                        'pointBackgroundColor' => '#d97706', 'pointRadius' => 3, 'spanGaps' => true ),
                ),
            ),
            'options' => array(
                'scales'  => array( 'r' => array(
                    'min' => 0, 'max' => 5, 'beginAtZero' => true,
                    'ticks'       => array( 'stepSize' => 1, 'showLabelBackdrop' => false, 'font' => array( 'size' => 9 ), 'color' => '#999', 'precision' => 0 ),
                    'grid'        => array( 'color' => 'rgba(0,0,0,0.08)' ),
                    'angleLines'  => array( 'color' => 'rgba(0,0,0,0.08)' ),
                    'pointLabels' => array( 'font' => array( 'size' => 11, 'family' => "'Inter',sans-serif", 'weight' => '600' ), 'color' => '#2c3e50' ),
                ) ),
                'plugins' => array(
                    'legend'  => array( 'display' => true, 'position' => 'bottom', 'labels' => array( 'font' => array( 'size' => 11, 'family' => "'Inter',sans-serif" ), 'color' => '#2c3e50', 'boxWidth' => 14 ) ),
                    'title'   => array( 'display' => false ),
                    'tooltip' => array( 'enabled' => false ),
                ),
                'maintainAspectRatio' => true,
                'aspectRatio'         => 1,
            ),
        );
        return 'https://quickchart.io/chart?c=' . rawurlencode( wp_json_encode( $cfg ) ) . '&w=540&h=540&bkg=white&v=4';
    }

    /** "about 1.6 at 90 on today's path" / "needs about 1.3 now to keep it" words for a profile row, '' parts left out. */
    private static function projection_words( $a, $per5 = '' ) {
        $out = array();
        if ( null !== ( $a['at_90'] ?? null ) ) $out['at_90'] = 'about ' . $a['at_90'] . $per5 . ' at ' . self::TARGET_AGE . " on today's path";
        if ( null !== ( $a['need_now'] ?? null ) ) $out['need_now'] = ! empty( $a['need_now_capped'] ) ? '5 or more' : 'about ' . $a['need_now'] . $per5;
        return $out;
    }

    /** Focus ability ids from abilities_profile() rows, strongest claim first. */
    private static function focus_ids( $rows ) {
        $weight = array();
        foreach ( $rows as $a ) {
            if ( null !== $a['score'] && $a['score'] <= self::FOCUS_MAX_SCORE ) $weight[ $a['id'] ] = $a['need'] * ( 5 - $a['score'] );
        }
        // Weight desc; ties keep NEEDS order (stable sort, PHP 8+).
        arsort( $weight );
        return array_slice( array_keys( $weight ), 0, self::FOCUS_LIMIT );
    }

    /** The chosen statements for the practitioner: label + ability names + group title. */
    public static function picks_for_display( $pick_ids, $stored_labels = array() ) {
        $out = array();
        foreach ( self::labels( $pick_ids, $stored_labels ) as $it ) {
            $names = array();
            foreach ( $it['needs'] as $n ) $names[] = self::ABILITY_LABELS[ $n ];
            $out[] = array( 'label' => $it['label'], 'needs' => $names, 'group' => $it['group'] );
        }
        return $out;
    }

    /**
     * Stage 1 raw scores that come from a real answer. calculate_quick()
     * fills a missing or invalid q3-q9 with 3; those are dropped here so a
     * question nobody answered is never reported as measured.
     *
     * @param array $stage1_data Decoded form_progress.stage1_data.
     */
    public static function stage1_raw( $stage1_data ) {
        $raw = is_array( $stage1_data ) ? ( $stage1_data['server_result']['raw'] ?? array() ) : array();
        if ( ! is_array( $raw ) ) return array();
        foreach ( $raw as $key => $v ) {
            // 'q5_sts' is scored from the answer stored under 'q5'.
            $answer = $stage1_data[ strtok( (string) $key, '_' ) ] ?? '';
            if ( ! is_string( $answer ) || ! preg_match( '/^[a-e]$/i', $answer ) ) unset( $raw[ $key ] );
        }
        return $raw;
    }

    /**
     * prompt_block() for a form_progress row and its freshly calculated
     * Stage 3 scores: the one call every prompt caller makes. $use as in
     * prompt_block().
     */
    public static function block_for_row( $progress, $stage3_scores, $use = false ) {
        $s1 = json_decode( (string) ( $progress->stage1_data ?? '' ), true ) ?: array();
        return self::prompt_block(
            json_decode( (string) ( $progress->stage2_data ?? '' ), true ) ?: array(),
            self::stage1_raw( $s1 ),
            is_array( $stage3_scores ) ? $stage3_scores : array(),
            $use,
            self::age_of( $s1 )
        );
    }

    /**
     * v0.48.8 — what a single-form row chose, for the PDFs: picks ({ label,
     * group } in pick order, v0.48.10), abilities [ label, short, count, need, score|null, focus ],
     * focus_areas (labels), people, own_words. Empty array for every other
     * row. Read from picks_for_display() + abilities_profile(); nothing new
     * is worked out here.
     */
    public static function summary_for_row( $progress, $stage3_scores ) {
        $s2 = json_decode( (string) ( $progress->stage2_data ?? '' ), true ) ?: array();
        if ( ! self::is_single( $s2 ) || ! is_array( $s2['why_picks'] ?? null ) ) return array();
        $ids = array_slice( $s2['why_picks'], 0, self::MAX_PICKS );

        $abilities = array(); $focus = array();
        $s1        = json_decode( (string) ( $progress->stage1_data ?? '' ), true ) ?: array();
        $raw       = self::stage1_raw( $s1 );
        $scores    = is_array( $stage3_scores ) ? $stage3_scores : array();
        $profile   = self::abilities_profile( $ids, $raw, $scores, self::age_of( $s1 ) );
        foreach ( $profile as $a ) {
            $abilities[] = array( 'label' => $a['label'], 'short' => self::ABILITY_SHORT[ $a['id'] ], 'count' => $a['count'], 'need' => $a['need'], 'score' => $a['score'], 'focus' => $a['focus'],
                'at_90' => $a['at_90'], 'need_now' => $a['need_now'], 'need_now_capped' => $a['need_now_capped'] );
            if ( $a['focus'] ) $focus[] = $a['label'];
        }
        $picks = array();
        foreach ( self::picks_for_display( $ids, $s2['why_pick_labels'] ?? array() ) as $p ) $picks[] = array( 'label' => $p['label'], 'group' => $p['group'] );
        return array(
            'picks'       => $picks,
            'abilities'   => $abilities,
            'focus_areas' => $focus,
            'people'      => self::plain( $s2['key_people_text'] ?? '', self::MAX_PEOPLE_LEN ),
            'own_words'   => self::plain( $s2['own_words'] ?? '', self::MAX_OWN_WORDS_LEN ),
            'chart_url'   => self::chart_url( self::ability_chart_series( $profile, $raw, $scores ) ),
        );
    }

    /**
     * summary_for_row() as nine flat strings for the PDF payloads (v0.48.12:
     * why_chart_url, the abilities chart picture, '' when it has none). Make's
     * PDFMonkey step is a JSON text template, so lists go as one string
     * (" | " between items, a template splits on it). v0.48.10 — the three
     * why_picks_<group> keys hold the statements under each heading, so the
     * PDFs print them from data. All '' for every row that did not use the
     * choices page.
     */
    public static function payload_fields( $progress, $stage3_scores ) {
        $s     = self::summary_for_row( $progress, $stage3_scores );
        $picks = $s['picks'] ?? array();
        $abs   = array();
        foreach ( $s['abilities'] ?? array() as $a ) {
            $w     = self::projection_words( $a );
            $abs[] = $a['label'] . ' (need ' . $a['need'] . ' of 5'
                . ( null !== $a['score'] ? ', now ' . $a['score'] : '' )
                . ( isset( $w['at_90'] ) ? ', ' . $w['at_90'] : '' )
                . ( isset( $w['need_now'] ) ? ', needs ' . $w['need_now'] . ' now to keep it' : '' )
                . ( $a['focus'] ? ', focus area' : '' ) . ')';
        }
        $out = array(
            'why_picks'       => implode( ' | ', array_column( $picks, 'label' ) ),
            'why_abilities'   => implode( ' | ', $abs ),
            'why_focus_areas' => implode( ', ', $s['focus_areas'] ?? array() ),
            'why_people'      => $s['people'] ?? '',
            'why_own_words'   => $s['own_words'] ?? '',
        );
        // ponytail: a retired statement has no heading, so it is in why_picks only.
        foreach ( static::catalogue() as $g ) {
            if ( 'retired' === $g['id'] ) continue;
            $in = array();
            foreach ( $picks as $p ) if ( $g['title'] === $p['group'] ) $in[] = $p['label'];
            $out[ 'why_picks_' . $g['id'] ] = implode( ' | ', $in );
        }
        $out['why_chart_url'] = $s['chart_url'] ?? '';
        return $out;
    }

    /**
     * Optional block for the draft-report and milestones prompts. '' unless
     * the data is a single-form submit with picks, so every other prompt
     * stays byte-identical. With the saved scores it also says what was
     * measured for each ability and names the focus areas. The milestones
     * prompt has no LIFT / THRIVE sections, so it gets its own instruction.
     * $use: false = draft and final report (LIFT / THRIVE), true = milestones,
     * 'facts' (v0.48.8) = the same data with no section instruction, for the
     * PDF sections, weekly flight plan and consultation-notes prompts.
     */
    public static function prompt_block( $stage2_data, $stage1_raw = array(), $stage3_scores = array(), $use = false, $age = null ) {
        if ( ! self::is_single( $stage2_data ) || ! is_array( $stage2_data['why_picks'] ?? null ) ) return '';
        $items = self::labels( array_slice( $stage2_data['why_picks'], 0, self::MAX_PICKS ), $stage2_data['why_pick_labels'] ?? array() );
        if ( ! $items ) return '';
        $use = true === $use ? 'milestones' : ( 'facts' === $use ? 'facts' : 'draft' );

        $block  = "=== WHAT THIS CLIENT CHOSE (picked from a list, their own priorities for later life) ===\n";
        foreach ( $items as $it ) {
            $block .= '- ' . $it['label'] . ( '' !== $it['group'] ? ' (' . $it['group'] . ')' : '' ) . ' [depends on: ' . implode( ', ', $it['needs'] ) . "]\n";
        }
        // Need desc; ties keep NEEDS order (stable sort, PHP 8+).
        $tally = self::tally( $items );
        uasort( $tally, function ( $a, $b ) { return $b['need'] <=> $a['need']; } );
        $parts = array();
        foreach ( $tally as $n => $t ) $parts[] = $n . ' ' . $t['need'];
        $block .= 'What these choices need of each ability, out of 5 (5 = every choice leans on it fully): ' . implode( ', ', $parts ) . "\n";

        $people = self::plain( $stage2_data['key_people_text'] ?? '', self::MAX_PEOPLE_LEN );
        if ( '' !== $people ) $block .= 'People they named: ' . $people . "\n";
        if ( 'draft' === $use ) {
            $block .= "In LIFT, where one of their weakest scores limits an ability these choices depend on, say so in one clause and name the choice. In THRIVE, use their choices and the people they named. Do not invent choices or people that are not listed here.\n";
        }

        $scored = $stage1_raw || $stage3_scores;
        $focus  = array();
        if ( $scored ) {
            $block .= "What their choices depend on, with what was measured (out of 5, higher is better; Stage 1 answers run 1 to 5, Stage 3 answers 0 to 5):\n";
            $profile = self::abilities_profile( array_column( $items, 'id' ), $stage1_raw, $stage3_scores, $age );
            foreach ( $profile as $a ) {
                $from = array();
                foreach ( $a['measures'] as $m ) {
                    if ( null !== $m['score'] ) $from[] = $m['label'] . ( 'stage1' === $m['source'] ? ' (Stage 1) ' : ' ' ) . $m['score'] . '/5';
                }
                $block .= '- ' . $a['label'] . ': needed ' . $a['need'] . '/5 by their choices (' . $a['count'] . ' of ' . count( $items ) . ' picks); '
                    . ( null === $a['score'] ? 'no answer recorded' : 'measured ' . $a['score'] . '/5 from ' . implode( ', ', $from ) );
                $w = self::projection_words( $a, '/5' );
                if ( isset( $w['at_90'] ) ) $block .= '; ' . $w['at_90'];
                if ( isset( $w['need_now'] ) ) $block .= '; keeping it to ' . self::TARGET_AGE . ' needs ' . $w['need_now'] . ' now';
                $block .= "\n";
            }
            foreach ( self::focus_ids( $profile ) as $id ) $focus[] = self::ABILITY_LABELS[ $id ];
            if ( $focus ) $block .= 'Focus areas (most needed, weakest measured): ' . implode( ', ', $focus ) . "\n";
        }
        // v0.48.10 (Quim, 2026-10-07): the choices explain the results. One
        // assessment is a position, not a trend.
        $block .= "HOW TO USE THESE CHOICES: this client has one assessment, so describe where they stand today against what each choice needs, not a trend. For each focus area, name the choice it serves (quote the statement word for word), the measure that drags it, and what keeping that ability over the coming years needs. Where a measure is above what a choice needs, say so in one clause and name the choice. Explain the client's position in terms of their choices, never in abilities alone. Do not invent choices or people that are not listed here.\n";
        // v0.48.12 — only when the block carries projections (an age is known).
        if ( $scored && null !== $age && is_numeric( $age ) && $age > 0 ) {
            $block .= "The projection to " . self::TARGET_AGE . " is arithmetic on today's number (about " . round( self::LOSS_PER_DECADE * 100 ) . "% less each decade), not a trend: use it to say what keeping the ability needs, never as a measured change.\n";
        }
        // An instruction about focus areas is only given when some are named.
        if ( 'milestones' === $use ) {
            $block .= 'Aim the milestones at what this client chose' . ( $focus ? ', starting with the focus areas' : '' ) . ", and keep every milestone within its word limit.\n";
        } elseif ( 'draft' === $use && $focus ) {
            $block .= "Use the focus areas to decide what LIFT puts first. Name the choice each one serves.\n";
        }
        if ( $scored ) $block .= "Do not quote these ability scores as if they were one of the 21 health scores; they are averages for your guidance.\n";
        return $block . "\n";
    }

    /**
     * v0.47.94 — for each second-form question that measures an ability:
     * the ability the choices need most (v0.48.11: by summed weight; ties:
     * NEEDS order), how many choices need it, and the first of them in pick
     * order. Questions no
     * choice reaches are left out. The tap-card form orders its questions
     * and writes its reason lines from this.
     *
     * @return array field => [ ability, count, choice ]
     */
    public static function second_form_ties( $pick_ids, $stored_labels = array() ) {
        $items = self::labels( array_slice( (array) $pick_ids, 0, self::MAX_PICKS ), $stored_labels );
        $first = array();
        foreach ( $items as $it ) {
            foreach ( $it['needs'] as $n ) {
                if ( ! isset( $first[ $n ] ) ) $first[ $n ] = $it['label'];
            }
        }
        $out = array(); $best = array();
        foreach ( self::tally( $items ) as $ability => $t ) {
            foreach ( self::MEASURES[ $ability ] as $m ) {
                // heartRateScore is worked out from the heart rate, not asked.
                if ( 'stage3' !== $m['source'] || 'heartRateScore' === $m['key'] ) continue;
                if ( ! isset( $best[ $m['key'] ] ) || $t['sum'] > $best[ $m['key'] ] ) {
                    $best[ $m['key'] ] = $t['sum'];
                    $out[ $m['key'] ]  = array( 'ability' => $ability, 'count' => $t['count'], 'choice' => $first[ $ability ] );
                }
            }
        }
        return $out;
    }

    /**
     * v0.48.5 — id => wording for known picks as offered today, in pick
     * order. Stored beside why_picks at submit (why_pick_labels).
     */
    public static function label_map( $pick_ids ) {
        $map = array();
        foreach ( self::labels( $pick_ids ) as $it ) $map[ $it['id'] ] = $it['label'];
        return $map;
    }

    /**
     * Catalogue entries (retired included) for known string ids, in pick
     * order. v0.48.5 — a wording stored at submit ($stored, id => label)
     * replaces today's, so a reworded statement still reads as the client
     * saw it; rows without one read the catalogue.
     */
    private static function labels( $pick_ids, $stored = array() ) {
        $by_id = array();
        foreach ( static::catalogue() as $g ) {
            // v0.48.10 — the heading it is offered under; a retired statement has none.
            foreach ( $g['items'] as $it ) $by_id[ $it['id'] ] = $it + array( 'group' => $it['retired'] ? '' : $g['title'] );
        }
        $stored = is_array( $stored ) ? $stored : array();
        $out    = array();
        foreach ( (array) $pick_ids as $id ) {
            if ( ! is_string( $id ) || ! isset( $by_id[ $id ] ) ) continue;
            $it = $by_id[ $id ];
            if ( is_string( $stored[ $id ] ?? null ) && '' !== trim( $stored[ $id ] ) ) $it['label'] = $stored[ $id ];
            $out[] = $it;
        }
        return $out;
    }

    /** Client text as plain text, capped. */
    private static function plain( $s, $max ) {
        return is_string( $s ) ? mb_substr( wp_strip_all_tags( $s ), 0, $max ) : '';
    }

    private static function error( $message ) {
        return new WP_Error( 'invalid_why_picks', $message, array( 'status' => 400 ) );
    }
}
