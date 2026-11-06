<?php
/**
 * KH Speed Engine — cache-preload.php
 *
 * Stable aggregator for the preload package.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
    die;
}

require_once __DIR__ . '/queue-store.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/crawl.php';
require_once __DIR__ . '/runner.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/cli.php';
}
