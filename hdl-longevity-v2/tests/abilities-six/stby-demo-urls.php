<?php
/**
 * The three demo pictures for the Stage 1 sales page (v0.48.12), built by
 * the plugin's own chart builders with fixed example numbers, never a
 * client's. Run on the server whose host the trajectory picture must use:
 * copy to /tmp, `wp eval-file`, delete. Prints JSON for stage1_template.py.
 */
$abilities = HDLV2_Why_Picks::chart_url( array(
    'labels' => array( 'Strength', 'Mobility', 'Balance', 'Stamina', 'Mind', 'Connection' ),
    'needed' => array( 3.3, 4.0, 3.0, 2.7, 1.0, 1.0 ),
    'now'    => array( 2.5, 2.0, 3.5, 2.0, 4.0, 3.0 ),
    // v0.48.13 — the third line for a man of 55: now x HDLV2_Why_Picks::kept().
    'at_90'  => array( 1.2, 1.1, 1.7, 1.0, 3.0, null ),
    'scale'  => 5,
) );
$scores = array(
    'physicalActivity' => 3, 'sitToStand' => 2, 'breathHold' => 3, 'balance' => 2, 'sleepDuration' => 4,
    'sleepQuality' => 3, 'stressLevels' => 2, 'socialConnections' => 5, 'dietQuality' => 3, 'alcoholConsumption' => 4,
    'smokingStatus' => 5, 'cognitiveActivity' => 4, 'sunlightExposure' => 3, 'supplementIntake' => 2, 'dailyHydration' => 3,
    'bmiScore' => 4, 'whrScore' => 3, 'whtrScore' => 3, 'bloodPressureScore' => 4, 'heartRateScore' => 4,
);
echo wp_json_encode( array(
    'abilities'  => $abilities,
    'trajectory' => HDLV2_Trajectory_SVG::url_for( 52, 1.18 ),
    'radar'      => HDLV2_Final_Report::build_radar_chart_url( $scores ),
), JSON_UNESCAPED_SLASHES );
