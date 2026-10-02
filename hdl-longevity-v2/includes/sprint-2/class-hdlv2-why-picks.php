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
 * dropped statement is marked `retired`, never deleted.
 *
 * Slice 2 (v0.47.93): every statement stands for abilities (NEEDS), and a
 * fixed table (MEASURES) names the health answers that measure each one.
 * abilities_profile() joins a client's choices to their saved scores, fresh
 * each time and never stored, for the picks page, the practitioner's WHY tab
 * and the draft + milestones prompts. Tags and table are draft for Matthew.
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
    const NEEDS = array( 'strength', 'mobility', 'flexibility', 'balance', 'stamina', 'mind', 'energy', 'connection' );

    const ABILITY_LABELS = array(
        'strength'    => 'Strength',
        'mobility'    => 'Mobility',
        'flexibility' => 'Flexibility',
        'balance'     => 'Balance',
        'stamina'     => 'Stamina',
        'mind'        => 'A sharp mind',
        'energy'      => 'Energy and sleep',
        'connection'  => 'Connection',
    );

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
        'flexibility' => array(
            array( 'source' => 'stage1', 'key' => 'q5_sts', 'label' => 'Getting up from the floor' ),
        ),
        'balance'     => array(
            array( 'source' => 'stage3', 'key' => 'balance', 'label' => 'Standing on one leg' ),
        ),
        'stamina'     => array(
            array( 'source' => 'stage3', 'key' => 'physicalActivity', 'label' => 'Physical activity' ),
            array( 'source' => 'stage1', 'key' => 'q4_vo2', 'label' => 'Climbing stairs' ),
            array( 'source' => 'stage3', 'key' => 'heartRateScore', 'label' => 'Resting heart rate' ),
        ),
        'mind'        => array(
            array( 'source' => 'stage3', 'key' => 'cognitiveActivity', 'label' => 'Mental activity' ),
            array( 'source' => 'stage3', 'key' => 'stressLevels', 'label' => 'Stress' ),
            array( 'source' => 'stage3', 'key' => 'sleepQuality', 'label' => 'Sleep quality' ),
        ),
        'energy'      => array(
            array( 'source' => 'stage3', 'key' => 'sleepDuration', 'label' => 'Sleep duration' ),
            array( 'source' => 'stage3', 'key' => 'sleepQuality', 'label' => 'Sleep quality' ),
            array( 'source' => 'stage3', 'key' => 'dietQuality', 'label' => 'Diet' ),
        ),
        'connection'  => array(
            array( 'source' => 'stage3', 'key' => 'socialConnections', 'label' => 'Time with people' ),
        ),
    );

    /** No question measures these directly; their measure is the nearest one. */
    const INDIRECT = array( 'flexibility' );

    /** An ability is a focus area only when its measured score is at or under this. */
    const FOCUS_MAX_SCORE = 3.0;
    const FOCUS_LIMIT     = 3;

    /**
     * Groups in display order: title, short title (the theme tile), items.
     * Draft copy for Matthew to edit (labels may be reworded; ids may not
     * change).
     */
    public static function catalogue() {
        $groups = array(
            'family' => array( 'Family and the people I love', 'Family', array(
                array( 'floor_grandkids', 'Get down on the floor to play with my grandchildren, and get back up without help', 'mobility,flexibility,strength' ),
                array( 'lift_grandchild', 'Pick up a grandchild and carry them on my hip', 'strength,balance' ),
                array( 'keep_up_children', 'Keep up with the children at the park without needing to sit down', 'stamina' ),
                array( 'dance_wedding', 'Dance at a family wedding until the last song', 'stamina,balance' ),
                array( 'host_family_meal', 'Cook and host a big family meal on my own', 'stamina,strength' ),
                array( 'walk_with_partner', 'Walk hand in hand with my partner for an hour', 'stamina,mobility' ),
                array( 'carer_not_cared_for', 'Be the one who looks after my partner, not the one being looked after', 'strength,stamina' ),
                array( 'remember_family', 'Remember every grandchild\'s name, birthday and story', 'mind' ),
                array( 'teach_grandchild', 'Teach a grandchild something I am good at', 'mind,connection' ),
                array( 'present_big_moments', 'Be fully present at the big moments: graduations, weddings, great-grandchildren', 'energy,mind' ),
            ) ),
            'home' => array( 'Staying independent at home', 'Independence', array(
                array( 'stairs_no_rail', 'Climb the stairs in my own home without holding the rail', 'strength,balance' ),
                array( 'carry_shopping', 'Carry my own shopping from the car to the kitchen', 'strength' ),
                array( 'up_from_low_seat', 'Get up from a low sofa without using my hands', 'strength,mobility' ),
                array( 'dress_myself', 'Dress myself, socks and shoelaces included', 'flexibility,balance' ),
                array( 'bath_shower_alone', 'Get in and out of the bath or shower safely on my own', 'balance,mobility' ),
                array( 'reach_top_cupboard', 'Reach the top cupboard and lift something down', 'flexibility,strength' ),
                array( 'keep_driving', 'Keep driving safely, including turning to look over my shoulder', 'mind,flexibility' ),
                array( 'manage_own_affairs', 'Manage my own money, appointments and paperwork', 'mind' ),
                array( 'live_in_own_home', 'Live in my own home for as long as I choose', 'strength,balance,mind' ),
                array( 'up_after_fall', 'Get up from the floor by myself if I ever fall', 'strength,mobility' ),
            ) ),
            'travel' => array( 'Travel and adventure', 'Travel', array(
                array( 'lift_suitcase', 'Lift my own suitcase into the overhead locker', 'strength' ),
                array( 'walk_city_all_day', 'Walk around a new city all day and still enjoy dinner', 'stamina' ),
                array( 'long_flight', 'Take a long flight and feel fine the next day', 'energy' ),
                array( 'hike_hills', 'Hike a hill or a coastal path with friends', 'stamina,balance' ),
                array( 'swim_in_sea', 'Swim in the sea', 'stamina,strength' ),
                array( 'uneven_ground', 'Walk on cobbles, sand or a rocky path without fear of falling', 'balance' ),
                array( 'trip_short_notice', 'Say yes to a trip at short notice', 'energy,stamina' ),
                array( 'ride_bicycle', 'Ride a bicycle', 'balance,stamina' ),
            ) ),
            'movement' => array( 'Sport, movement and things I enjoy', 'Sport and hobbies', array(
                array( 'keep_my_sport', 'Keep playing my sport: golf, tennis, bowls, skiing', 'mobility,strength,balance' ),
                array( 'day_in_garden', 'Spend a full day in the garden: digging, kneeling, lifting', 'strength,mobility' ),
                array( 'run_for_bus', 'Run for a bus or a train, and catch it', 'stamina' ),
                array( 'dance_regularly', 'Dance regularly', 'balance,stamina' ),
                array( 'do_own_diy', 'Do my own repairs at home: climb a ladder, carry tools', 'balance,strength' ),
                array( 'walk_dog_daily', 'Walk the dog every day, whatever the weather', 'stamina' ),
                array( 'sit_on_floor', 'Sit cross-legged on the floor and get up easily', 'flexibility,mobility' ),
                array( 'steady_hands_craft', 'Keep playing my instrument or doing my craft with steady hands', 'mind,mobility' ),
                array( 'intimate_life', 'Keep an active intimate life with my partner', 'stamina,energy' ),
            ) ),
            'mind' => array( 'Mind, work and purpose', 'Mind and purpose', array(
                array( 'sharp_conversation', 'Stay sharp in conversation and follow a fast discussion', 'mind' ),
                array( 'keep_working', 'Keep working, or running my business, for as long as I want', 'mind,energy' ),
                array( 'learn_something_new', 'Learn something new: a language, an instrument, a skill', 'mind' ),
                array( 'read_for_an_hour', 'Read a book for an hour without losing concentration', 'mind' ),
                array( 'volunteer_mentor', 'Volunteer or mentor, and be useful to others', 'energy,connection' ),
                array( 'never_lost_for_words', 'Not be lost for a word or a name mid-sentence', 'mind' ),
                array( 'write_life_story', 'Write down my life story for my family', 'mind' ),
            ) ),
            'everyday' => array( 'Everyday energy and feeling well', 'Everyday energy', array(
                array( 'wake_rested', 'Wake up rested and ready for the day', 'energy' ),
                array( 'energy_in_evening', 'Have energy left in the evening for the people I love', 'energy' ),
                array( 'move_without_aches', 'Move through the day without aches deciding what I do', 'mobility' ),
                array( 'see_friends_weekly', 'See my friends every week', 'connection' ),
                array( 'steady_in_body', 'Feel confident and steady in my own body', 'balance,strength' ),
                array( 'calm_under_pressure', 'Stay calm and good-humoured under pressure', 'mind' ),
            ) ),
        );

        $out = array();
        foreach ( $groups as $gid => $g ) {
            $items = array();
            foreach ( $g[2] as $it ) {
                $items[] = array( 'id' => $it[0], 'label' => $it[1], 'needs' => explode( ',', $it[2] ), 'retired' => false );
            }
            $out[] = array( 'id' => $gid, 'title' => $g[0], 'short' => $g[1], 'items' => $items );
        }
        return $out;
    }

    /** What /form/load sends the page: groups with their active items. */
    public static function options_for_page() {
        $out = array();
        foreach ( static::catalogue() as $g ) {
            $items = array();
            foreach ( $g['items'] as $it ) {
                if ( empty( $it['retired'] ) ) $items[] = array( 'id' => $it['id'], 'label' => $it['label'], 'needs' => $it['needs'] );
            }
            $out[] = array( 'id' => $g['id'], 'title' => $g['title'], 'short' => $g['short'], 'items' => $items );
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
        $text   = "In my later years, I want to be able to:\n- " . implode( "\n- ", array_column( $labels, 'label' ) );
        $people = self::plain( $people, self::MAX_PEOPLE_LEN );
        $own    = self::plain( $own_words, self::MAX_OWN_WORDS_LEN );
        if ( '' !== $people ) $text .= "\nThe people I most want to stay healthy and strong for: " . $people;
        if ( '' !== $own )    $text .= "\nIn my own words: " . $own;
        return $text;
    }

    /**
     * What the chosen statements depend on, joined to the saved scores.
     * Abilities with at least one choice, in NEEDS order:
     * [ id, label, count, score (1-decimal average of the measures that have
     * a number, or null), measures [ label, score|null, source ], indirect,
     * focus ]. Focus = the FOCUS_LIMIT abilities with a score at or under
     * FOCUS_MAX_SCORE and the highest count x (5 - score). A missing number
     * is never filled in.
     *
     * @param array $pick_ids      stage2_data.why_picks
     * @param array $stage1_raw    stage1_data.server_result.raw
     * @param array $stage3_scores stage3_data.server_result.scores
     */
    public static function abilities_profile( $pick_ids, $stage1_raw = array(), $stage3_scores = array() ) {
        $answers = array( 'stage1' => (array) $stage1_raw, 'stage3' => (array) $stage3_scores );
        $counts  = array_fill_keys( self::NEEDS, 0 );
        foreach ( self::labels( $pick_ids ) as $it ) {
            foreach ( $it['needs'] as $n ) {
                if ( isset( $counts[ $n ] ) ) $counts[ $n ]++;
            }
        }

        $out = array();
        foreach ( array_filter( $counts ) as $id => $count ) {
            $measures = array(); $numbers = array();
            foreach ( self::MEASURES[ $id ] as $m ) {
                $v = $answers[ $m['source'] ][ $m['key'] ] ?? null;
                $v = is_numeric( $v ) ? $v + 0 : null;
                if ( null !== $v ) $numbers[] = $v;
                $measures[] = array( 'label' => $m['label'], 'score' => $v, 'source' => $m['source'] );
            }
            $out[ $id ] = array(
                'id'       => $id,
                'label'    => self::ABILITY_LABELS[ $id ],
                'count'    => $count,
                'score'    => $numbers ? round( array_sum( $numbers ) / count( $numbers ), 1 ) : null,
                'measures' => $measures,
                'indirect' => in_array( $id, self::INDIRECT, true ),
                'focus'    => false,
            );
        }
        foreach ( self::focus_ids( $out ) as $id ) $out[ $id ]['focus'] = true;
        return array_values( $out );
    }

    /** Focus ability ids from abilities_profile() rows, strongest claim first. */
    private static function focus_ids( $rows ) {
        $weight = array();
        foreach ( $rows as $a ) {
            if ( null !== $a['score'] && $a['score'] <= self::FOCUS_MAX_SCORE ) $weight[ $a['id'] ] = $a['count'] * ( 5 - $a['score'] );
        }
        // Weight desc; ties keep NEEDS order (stable sort, PHP 8+).
        arsort( $weight );
        return array_slice( array_keys( $weight ), 0, self::FOCUS_LIMIT );
    }

    /** The chosen statements for the practitioner: label + ability names. */
    public static function picks_for_display( $pick_ids ) {
        $out = array();
        foreach ( self::labels( $pick_ids ) as $it ) {
            $names = array();
            foreach ( $it['needs'] as $n ) $names[] = self::ABILITY_LABELS[ $n ];
            $out[] = array( 'label' => $it['label'], 'needs' => $names );
        }
        return $out;
    }

    /**
     * Optional block for the draft-report and milestones prompts. '' unless
     * the data is a single-form submit with picks, so every other prompt
     * stays byte-identical. With the saved scores it also says what was
     * measured for each ability and names the focus areas.
     */
    public static function prompt_block( $stage2_data, $stage1_raw = array(), $stage3_scores = array() ) {
        if ( ! self::is_single( $stage2_data ) || ! is_array( $stage2_data['why_picks'] ?? null ) ) return '';
        $items = self::labels( array_slice( $stage2_data['why_picks'], 0, self::MAX_PICKS ) );
        if ( ! $items ) return '';

        $block  = "=== WHAT THIS CLIENT CHOSE (picked from a list, their own priorities for later life) ===\n";
        $counts = array_fill_keys( self::NEEDS, 0 );
        foreach ( $items as $it ) {
            $block .= '- ' . $it['label'] . ' [depends on: ' . implode( ', ', $it['needs'] ) . "]\n";
            foreach ( $it['needs'] as $n ) {
                if ( isset( $counts[ $n ] ) ) $counts[ $n ]++;
            }
        }
        // Count desc; ties keep NEEDS order (stable sort, PHP 8+).
        $counts = array_filter( $counts );
        uasort( $counts, function ( $a, $b ) { return $b <=> $a; } );
        $parts = array();
        foreach ( $counts as $n => $c ) $parts[] = "$n ($c)";
        $block .= 'Abilities these choices depend on most: ' . implode( ', ', $parts ) . "\n";

        $people = self::plain( $stage2_data['key_people_text'] ?? '', self::MAX_PEOPLE_LEN );
        if ( '' !== $people ) $block .= 'People they named: ' . $people . "\n";
        $block .= "In LIFT, where one of their weakest scores limits an ability these choices depend on, say so in one clause and name the choice. In THRIVE, use their choices and the people they named. Do not invent choices or people that are not listed here.\n";

        if ( $stage1_raw || $stage3_scores ) {
            $block .= "What their choices depend on, with what was measured (0-5, higher is better):\n";
            $profile = self::abilities_profile( array_column( $items, 'id' ), $stage1_raw, $stage3_scores );
            foreach ( $profile as $a ) {
                $from = array();
                foreach ( $a['measures'] as $m ) {
                    if ( null !== $m['score'] ) $from[] = $m['label'] . ' ' . $m['score'] . '/5';
                }
                $block .= '- ' . $a['label'] . ': ' . $a['count'] . ' of their choices; '
                    . ( null === $a['score']
                        ? 'not measured by the questionnaire'
                        : ( $a['indirect'] ? 'nearest measure ' : 'measured ' ) . $a['score'] . '/5 from ' . implode( ', ', $from ) )
                    . "\n";
            }
            $focus = array();
            foreach ( self::focus_ids( $profile ) as $id ) $focus[] = self::ABILITY_LABELS[ $id ];
            if ( $focus ) $block .= 'Focus areas (most chosen, weakest measured): ' . implode( ', ', $focus ) . "\n";
            $block .= "Use the focus areas to decide what LIFT puts first and what the milestones aim at. Name the choice each one serves. Do not quote these ability scores as if they were one of the 21 health scores; they are averages for your guidance.\n";
        }
        return $block . "\n";
    }

    /** Catalogue entries (retired included) for known string ids, in pick order. */
    private static function labels( $pick_ids ) {
        $by_id = array();
        foreach ( static::catalogue() as $g ) {
            foreach ( $g['items'] as $it ) $by_id[ $it['id'] ] = $it;
        }
        $out = array();
        foreach ( (array) $pick_ids as $id ) {
            if ( is_string( $id ) && isset( $by_id[ $id ] ) ) $out[] = $by_id[ $id ];
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
