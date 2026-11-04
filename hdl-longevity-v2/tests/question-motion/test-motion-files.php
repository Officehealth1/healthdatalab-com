<?php
/**
 * Question motion (v0.48.42; Q5 poses v0.48.43): every scene in assets/images/motion/ obeys DESIGN.md "## Motion".
 * One palette, two stroke weights, round caps, one easing, a 400 ms grid, 4-8 s scenes,
 * reduced motion, no words, no outside references, at most 8 KB. Plain PHP, no stubs.
 * Run: php tests/question-motion/test-motion-files.php   Exit: 0 all pass, 1 any fail
 */
$pass = 0; $fail = 0;
function ok( $c, $l ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? '  PASS  ' : '  FAIL  ' ) . $l . "\n"; }

$dir      = dirname( __DIR__, 2 ) . '/assets/images/motion/';
$poses    = array( 'q5-floor-a.svg', 'q5-floor-b.svg', 'q5-floor-c.svg', 'q5-floor-d.svg', 'q5-floor-e.svg' ); // T6d option B: still, on Q5's answers
$static   = array_merge( array( 'g-waist-hip.svg' ), $poses );
$expected = array( 'q3-zone2.svg', 'q4-stairs.svg', 'q5-floor-a.svg', 'q5-floor-b.svg', 'q5-floor-c.svg', 'q5-floor-d.svg', 'q5-floor-e.svg', 'q6-sleep.svg', 'q7-smoking.svg', 'q8-social.svg',
	'q9-diet.svg', 't-balance.svg', 't-chair-stands.svg', 't-skin-pinch.svg', 'g-waist-hip.svg' );
$palette  = array( '#004f59', '#3d8da0', '#e4e6ea', '#f8f9fb', '#ffffff' );
$ease     = 'cubic-bezier(.4,0,.2,1)';

/** Milliseconds of a CSS time ("6.4s", "400ms"). */
function ms( $t ) { return false !== strpos( $t, 'ms' ) ? (float) $t : (float) $t * 1000; }

ok( is_dir( $dir ), 'the motion folder exists' );
foreach ( $expected as $name ) {
	$f = $dir . $name;
	if ( ! is_file( $f ) ) { ok( false, "$name exists" ); continue; }
	$s = file_get_contents( $f );
	$n = $name;
	ok( filesize( $f ) <= 8192, "$n is at most 8 KB (" . filesize( $f ) . ' bytes)' );
	ok( false !== strpos( $s, 'viewBox="0 0 120 120"' ), "$n viewBox 0 0 120 120" );

	// The frame: a #e4e6ea disc then a #f8f9fb disc 1 unit smaller (the 1 px ring), first in the file.
	ok( 1 === preg_match( '#<svg[^>]*>\s*(<style>.*?</style>\s*)?<circle cx="60" cy="60" r="60" fill="\#e4e6ea"/>\s*<circle cx="60" cy="60" r="59" fill="\#f8f9fb"/>#s', $s ), "$n opens with the frame (ring then disc)" );

	// Palette: every hex is one of five; no rgb()/hsl() or named colours in fill/stroke.
	preg_match_all( '/#[0-9a-fA-F]{3,8}\b/', $s, $hex );
	$bad = array_diff( array_map( 'strtolower', $hex[0] ), $palette );
	ok( ! $bad, "$n colours from the palette only" . ( $bad ? ' (found ' . implode( ' ', array_unique( $bad ) ) . ')' : '' ) );
	preg_match_all( '/(?:fill|stroke)\s*[=:]\s*"?([a-zA-Z(][^";}\s]*)/', $s, $named );
	$named = array_diff( $named[1], array( 'none' ) );
	ok( ! $named && ! preg_match( '/\b(rgb|rgba|hsl|hsla)\(/', $s ), "$n no named, rgb or hsl colour" . ( $named ? ' (found ' . implode( ' ', array_unique( $named ) ) . ')' : '' ) );

	// Strokes: width 6 (figure) or 2 (props, guides); whatever sets a stroke also sets a round cap.
	preg_match_all( '/stroke-width\s*[=:]\s*"?([0-9.]+)/', $s, $w );
	$bad = array_diff( $w[1], array( '6', '2' ) );
	ok( ! $bad, "$n stroke widths 6 or 2 only" . ( $bad ? ' (found ' . implode( ' ', array_unique( $bad ) ) . ')' : '' ) );
	preg_match_all( '/<[a-z]+\b[^>]*\bstroke="(?!none)[^"]*"[^>]*>|\{[^{}]*\bstroke:(?!none)[^{}]*\}/', $s, $stroked );
	$capless = array_filter( $stroked[0], function ( $x ) { return ! preg_match( '/stroke-linecap\s*[=:]\s*"?round/', $x ); } );
	ok( $stroked[0] && ! $capless, "$n every stroke has a round cap (" . count( $stroked[0] ) . ' stroked)' . ( $capless ? ': ' . substr( reset( $capless ), 0, 80 ) : '' ) );

	// No words, no pictures, no outside references, no script.
	ok( ! preg_match( '/<text\b|<image\b|<foreignObject\b|href|url\(|<script\b/i', $s ), "$n no text, image, href, url() or script" );

	if ( in_array( $name, $static, true ) ) {
		ok( ! preg_match( '/@keyframes|animation\s*:(?!\s*none)|animation-(name|duration)/', $s ), "$n is still (no keyframes, no animation)" );
		continue;
	}
	ok( 1 === preg_match( '/<svg\b[^>]*\bid="still"/', $s ) && false !== strpos( $s, '#still:target *{animation:none!important}' ), "$n file.svg#still is the still picture (for <picture> media=reduce)" );
	ok( 1 === preg_match( '/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{\s*\*\s*\{\s*animation:\s*none\s*!important;?\s*\}\s*\}/', $s ), "$n stops for reduced motion" );

	// Timing: animation shorthands "name D easing ..."; every duration on the 400 ms grid, the longest 4-8 s,
	// one easing (steps() for the timer arc), and every keyframe stop on the grid.
	preg_match_all( '/animation\s*:\s*([a-z0-9-]+)\s+([0-9.]+m?s)\s+([^;}]+)/i', $s, $an, PREG_SET_ORDER );
	$an = array_filter( $an, function ( $a ) { return 'none' !== $a[1]; } );
	ok( count( $an ) > 0, "$n has animations (" . count( $an ) . ')' );
	ok( ! preg_match( '/animation-(duration|delay|timing-function)|transition/', $s ), "$n timing only in the animation shorthand" );
	$dur = array(); $longest = 0; $grid = true; $eased = true; $delay = false;
	foreach ( $an as $a ) {
		$d = ms( $a[2] ); $dur[ $a[1] ] = $d; $longest = max( $longest, $d );
		if ( abs( fmod( $d, 400 ) ) > 0.01 ) $grid = false;
		$rest = trim( $a[3] );
		if ( 0 !== strpos( $rest, $ease ) && ! preg_match( '/^steps\(\d+(,\s*(end|start|jump-end|jump-start))?\)/', $rest ) ) $eased = false;
		if ( preg_match( '/\b[0-9.]+m?s\b/', preg_replace( '/^(cubic-bezier|steps)\([^)]*\)/', '', $rest ) ) ) $delay = true;
	}
	ok( $grid, "$n every duration a multiple of 400 ms" );
	ok( $longest >= 4000 && $longest <= 8000, "$n scene length 4-8 s ($longest ms)" );
	ok( $eased, "$n one easing, $ease (steps() for a timer)" );
	ok( ! $delay, "$n no delays (timing lives in the keyframes)" );
	preg_match_all( '/@keyframes\s+([a-z0-9-]+)\s*\{((?:[^{}]*\{[^{}]*\})*)\s*\}/i', $s, $kf, PREG_SET_ORDER );
	$off = array();
	foreach ( $kf as $k ) {
		if ( ! isset( $dur[ $k[1] ] ) ) { $off[] = $k[1] . ' unused'; continue; }
		preg_match_all( '/([0-9.]+)%/', preg_replace( '/\{[^{}]*\}/', '', $k[2] ), $stops );
		foreach ( $stops[1] as $p ) {
			$at = (float) $p * $dur[ $k[1] ] / 100;
			if ( abs( $at - 400 * round( $at / 400 ) ) > 1 ) $off[] = $k[1] . ' ' . $p . '%';
		}
	}
	ok( $kf && ! $off, "$n every keyframe stop on the 400 ms grid" . ( $off ? ' (' . implode( ', ', $off ) . ')' : '' ) );
	ok( count( $kf ) === count( array_unique( array_map( function ( $a ) { return $a[1]; }, $an ) ) ), "$n every animation has its keyframes and nothing else is declared" );
}
// Round 1b: each page's caption map lists exactly the scenes drawn for it, so nothing points at a missing file.
$root  = dirname( __DIR__, 2 );
$have  = array_map( 'basename', glob( $dir . '*.svg' ) ?: array() );
$files = function ( $src, $map ) {
	if ( ! preg_match( '/var ' . $map . ' = \{(.*?)\n  \};/s', $src, $m ) ) return null;
	preg_match_all( "/'([a-z0-9-]+\\.svg)'/", $m[1], $f );
	sort( $f[1] ); return $f[1];
};
$w = $files( file_get_contents( $root . '/widget/hdl-lead-magnet.js' ), 'QUESTION_MOTION' );
$f = $files( file_get_contents( $root . '/assets/js/hdlv2-staged-form.js' ), 'TAP_MOTION' );
$wq = array_values( array_filter( $have, function ( $x ) use ( $poses ) { return 0 === strpos( $x, 'q' ) && ! in_array( $x, $poses, true ); } ) ); sort( $wq );
$wp = array_values( array_intersect( $have, $poses ) ); sort( $wp );
$p  = $files( file_get_contents( $root . '/widget/hdl-lead-magnet.js' ), 'OPTION_POSES' );
$ft = array_values( array_filter( $have, function ( $x ) { return 0 !== strpos( $x, 'q' ); } ) ); sort( $ft );
ok( null !== $w && $w === $wq, 'the widget map (QUESTION_MOTION) lists exactly the drawn q- scenes: ' . implode( ', ', (array) $w ) );
ok( null !== $p && $p === $wp, 'the widget answer-pose map (OPTION_POSES) lists exactly the drawn Q5 poses: ' . implode( ', ', (array) $p ) );
ok( null !== $f && $f === $ft, 'the form map (TAP_MOTION) lists exactly the drawn t- and g- scenes: ' . implode( ', ', (array) $f ) );
echo "PASS=$pass FAIL=$fail\n";
exit( $fail ? 1 : 0 );
