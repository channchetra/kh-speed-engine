<?php
/**
 * KH Speed Engine — preload/cli.php
 *
 * WP-CLI commands for the durable preload queue. These are the reliable
 * trigger for container hosts where WP-Cron and loopback requests are not
 * available.
 *
 * @package   KH Speed Engine
 * @license   GPLv3 (or later)
 */

if ( ! defined( 'KHSE_INC' ) ) {
	die;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage the background preload queue.
 */
class KHSE_Preload_CLI_Command {

	/**
	 * Show queue counters and the next jobs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp khse preload status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$status = khse_get_preload_queue_status();
		$counts = function_exists( 'khse_preload_queue_counts' ) ? khse_preload_queue_counts() : array(
			'pending'    => (int) $status['remaining'],
			'processing' => 0,
			'failed'     => (int) $status['failed'],
		);

		WP_CLI::line( sprintf( 'Pending:    %d', (int) $counts['pending'] ) );
		WP_CLI::line( sprintf( 'Running:    %d', (int) $counts['processing'] ) );
		WP_CLI::line( sprintf( 'Failed:     %d', (int) $counts['failed'] ) );
		WP_CLI::line( sprintf( 'Processed:  %d', (int) $status['processed'] ) );
		WP_CLI::line( sprintf( 'State:      %s', khse_is_preload_queue_paused() ? 'Paused' : 'Running' ) );

		$jobs = khse_preload_queue_jobs( 20 );

		if ( ! empty( $jobs ) && class_exists( 'WP_CLI\\Utils' ) ) {
			WP_CLI\Utils\format_items( 'table', $jobs, array( 'id', 'label', 'status', 'attempts', 'updated' ) );
		}
	}

	/**
	 * Process queued preload work.
	 *
	 * ## OPTIONS
	 *
	 * [--batches=<number>]
	 * : Maximum number of batches to process. Default 1.
	 *
	 * [--pause=<seconds>]
	 * : Sleep between batches. Default 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp khse preload run --batches=4
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		$batches = isset( $assoc_args['batches'] ) ? max( 1, (int) $assoc_args['batches'] ) : 1;
		$pause   = isset( $assoc_args['pause'] ) ? max( 0, (int) $assoc_args['pause'] ) : 0;
		$total   = 0;

		if ( khse_is_preload_queue_paused() ) {
			WP_CLI::warning( 'The background queue is paused. Run `wp khse preload resume` first.' );
			return;
		}

		for ( $batch = 0; $batch < $batches; $batch++ ) {
			$count = khse_process_preload_queue();
			$total += (int) $count;

			if ( $count <= 0 || ! khse_preload_queue_has_pending() ) {
				break;
			}

			if ( $pause > 0 ) {
				sleep( $pause );
			}
		}

		WP_CLI::success( sprintf( 'Attempted %d queued URL(s).', $total ) );
	}

	/**
	 * Build the preload queue.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Maximum URLs to queue. Defaults to the saved preload limit.
	 *
	 * ## EXAMPLES
	 *
	 *     wp khse preload build --limit=100
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function build( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : null;
		$count = khse_preload_cache( $limit );

		WP_CLI::success( sprintf( 'Queued %d URL(s).', (int) $count ) );
	}

	/**
	 * Clear the background queue.
	 *
	 * ## EXAMPLES
	 *
	 *     wp khse preload clear
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function clear( $args, $assoc_args ) {
		khse_reset_preload_queue_state();

		WP_CLI::success( 'Preload queue cleared.' );
	}

	/**
	 * Pause optional background warming.
	 *
	 * ## EXAMPLES
	 *
	 *     wp khse preload pause
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function pause( $args, $assoc_args ) {
		khse_set_preload_queue_paused( true );

		WP_CLI::success( 'Background queue paused. Cache invalidation keeps running.' );
	}

	/**
	 * Resume optional background warming.
	 *
	 * ## EXAMPLES
	 *
	 *     wp khse preload resume
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function resume( $args, $assoc_args ) {
		khse_set_preload_queue_paused( false );
		khse_schedule_preload_queue();
		khse_kick_preload_queue();

		WP_CLI::success( 'Background queue resumed.' );
	}

	/**
	 * Print the token-protected batch endpoint.
	 *
	 * Use this URL from a host cron when WP-CLI is not available.
	 *
	 * ## EXAMPLES
	 *
	 *     wp khse preload endpoint
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function endpoint( $args, $assoc_args ) {
		$url = add_query_arg(
			array(
				'action' => 'khse_preload_batch',
				'token'  => khse_get_preload_token(),
			),
			admin_url( 'admin-ajax.php' )
		);

		WP_CLI::line( $url );
	}
}

WP_CLI::add_command( 'khse preload', 'KHSE_Preload_CLI_Command' );
