<?php
/**
 * v0.49.08 (plan 16) — the film picture's address when WordPress is run by WP-CLI.
 * The Stage 1 values go to Make from a cron run (wp cron event run), and there WordPress writes the
 * plugin's own address as http:// even on an https site (no request, so is_ssl() is false). Seen on the
 * test site 2026-11-27. The picture's address follows the site's own address instead.
 *
 * Run:  php scenario-film-cover-scheme.php   (self-asserting, exit 0/1)
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_WARNING & ~E_NOTICE );
define( 'HDLV2_PLUGIN_URL', 'http://site.example.test/wp-content/plugins/hdl-longevity-v2/' ); // as WP-CLI builds it
require __DIR__ . '/../full-report/stubs.php';
load_sources();

$tail = 'site.example.test/wp-content/plugins/hdl-longevity-v2/assets/images/stage1-film-cover-2026-11.jpg';
$GLOBALS['options'] = array( 'home' => 'https://site.example.test' );
check( 'S.1 an https site, run by WP-CLI: the picture\'s address is https', 'https://' . $tail === HDLV2_Stage1_Commentary::film_cover_url() );
$GLOBALS['options'] = array( 'home' => 'http://site.example.test' );
check( 'S.2 a site that is itself http (a local copy): the address stays http', 'http://' . $tail === HDLV2_Stage1_Commentary::film_cover_url() );
done();
