<?php
/**
 * WP-CLI command group: trustorder backfill.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\CLI;

use LightweightPlugins\TrustOrder\Admin\SiteState;
use LightweightPlugins\TrustOrder\Rest\BackfillController;
use LightweightPlugins\TrustOrder\Signals\Backfill;
use LightweightPlugins\TrustOrder\Signals\BackfillSchedule;
use LightweightPlugins\TrustOrder\Signals\OrderPager;
use LightweightPlugins\TrustOrder\Signals\SignalJob;

/**
 * The one-time back-loading of the last 3 years of completed and not picked
 * up orders. start/status are the admin screen's services; run executes the
 * ticks in this process, one page of 200 orders at a time.
 */
final class BackfillCommand {

	private const FIELDS = [ 'env', 'running', 'processed', 'sent', 'failed', 'cursor', 'started_at', 'finished_at' ];

	/**
	 * Starts (or resumes) the backfill for the saved key's environment. It is
	 * then carried on by Action Scheduler, or by `backfill run`.
	 *
	 * ## OPTIONS
	 *
	 * [--env=<env>]
	 * : live or test. Must match the saved key (the environment always comes from the key).
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @param array<int, string>   $args  Positional args.
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public function start( array $args, array $assoc ): void {
		$format = Output::format( $assoc );
		$env    = SignalJob::env();
		if ( null === $env ) {
			Output::fail( 'NO_KEY' );
		}
		$wanted = $assoc['env'] ?? $env;
		if ( ! in_array( $wanted, [ 'live', 'test' ], true ) ) {
			Output::fail( 'Unknown environment. Use live or test.' );
		}
		if ( $wanted !== $env ) {
			Output::fail( sprintf( 'The saved API key is a %s key: the backfill can only start for that environment.', $env ) );
		}
		Backfill::start( $env );
		$this->printStatus( $format );
	}

	/**
	 * Shows the progress of both environments.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @param array<int, string>   $args  Positional args.
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public function status( array $args, array $assoc ): void {
		$this->printStatus( Output::format( $assoc ) );
	}

	/**
	 * Runs the started backfill in this process (progress bar) instead of in
	 * the background. A running Action Scheduler tick is taken over; when the
	 * service is unavailable the retry goes back to the background.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<orders>]
	 * : Stop after the page (200 orders) in which this many orders were processed. The backfill is then paused; `backfill start` resumes it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trustorder backfill start
	 *     wp trustorder backfill run
	 *
	 * @param array<int, string>   $args  Positional args.
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public function run( array $args, array $assoc ): void {
		$limit = $this->limit( $assoc );
		$env   = SignalJob::env();
		if ( null === $env ) {
			Output::fail( 'NO_KEY' );
		}
		$first = Backfill::status( $env );
		if ( ! $first['running'] ) {
			Output::fail( 'There is no running backfill. Start it with: wp trustorder backfill start' );
		}
		BackfillSchedule::cancel();

		$remaining = OrderPager::remaining( $first['cursor'], time() - Backfill::MAX_DAYS * DAY_IN_SECONDS );
		$bar       = \WP_CLI\Utils\make_progress_bar( 'Reporting orders', null === $limit ? $remaining : min( $limit, $remaining ) );
		$end       = $this->loop( $env, $limit, $first['processed'], $bar );
		$bar->finish(); // @phpstan-ignore-line -- make_progress_bar() returns a php-cli-tools Bar, which the WP-CLI stubs do not include.

		$this->conclude( $env, $end['delay'], $end['paused'], $first['started_at'] );
	}

	/**
	 * Tick until the run ends, the limit is reached or a retry is due.
	 *
	 * @param string   $env       Env.
	 * @param int|null $limit     Orders to process, null = all.
	 * @param int      $processed Counter at the start.
	 * @param object   $bar       Progress bar.
	 * @return array{delay: int|null, paused: bool} Delay of the pending retry (null: none); whether the limit paused the run.
	 */
	private function loop( string $env, ?int $limit, int $processed, $bar ): array {
		$start = $processed;
		do {
			$delay = Backfill::step();
			$now   = Backfill::status( $env )['processed'];
			$bar->tick( max( 0, $now - $processed ) );
			$processed = $now;
			if ( null === $delay || $delay > 0 ) {
				return [
					'delay'  => $delay,
					'paused' => false,
				];
			}
			if ( null !== $limit && $processed - $start >= $limit ) {
				Backfill::pause( $env );
				return [
					'delay'  => null,
					'paused' => true,
				];
			}
		} while ( true );
	}

	/**
	 * Report how the run ended.
	 *
	 * @param string      $env   Env.
	 * @param int|null    $delay  Pending retry delay.
	 * @param bool        $paused  Whether the limit paused the run.
	 * @param string|null $started started_at when this command began.
	 */
	private function conclude( string $env, ?int $delay, bool $paused, ?string $started ): void {
		$s = Backfill::status( $env );
		if ( ! $paused && null === $delay && $s['started_at'] !== $started ) {
			Output::fail( 'BACKFILL_RESTARTED' ); // Another start() replaced this run while it was going.
		} elseif ( null !== $delay ) {
			BackfillSchedule::ensure( $delay );
			\WP_CLI::warning( sprintf( 'The service is unavailable. The next attempt is scheduled in %d seconds in the background.', $delay ) );
		} elseif ( null !== $s['finished_at'] ) {
			\WP_CLI::success( sprintf( 'Backfill finished: %d orders processed, %d reported, %d failed.', $s['processed'], $s['sent'], $s['failed'] ) );
		} elseif ( $paused ) {
			\WP_CLI::success( sprintf( 'Paused after %d orders. Resume with: wp trustorder backfill start', $s['processed'] ) );
		} else {
			$code = SiteState::lastError()['code'] ?? null;
			Output::fail( 'BACKFILL_STOPPED' . ( null !== $code ? ': ' . $code : '' ) );
		}
	}

	/**
	 * The validated --limit, null when absent.
	 *
	 * @param array<string, mixed> $assoc Associative args.
	 */
	private function limit( array $assoc ): ?int {
		if ( ! array_key_exists( 'limit', $assoc ) ) {
			return null;
		}
		$raw = $assoc['limit'];
		if ( ( ! is_string( $raw ) && ! is_int( $raw ) ) || 1 !== preg_match( '/^[1-9][0-9]{0,8}$/D', (string) $raw ) ) {
			Output::fail( '--limit must be a positive whole number.' );
		}

		return (int) $raw;
	}

	/**
	 * Print the status of both envs.
	 *
	 * @param string $format table|json.
	 */
	private function printStatus( string $format ): void {
		$data = BackfillController::status()->get_data();
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( $data ) );
			return;
		}
		$rows = [];
		foreach ( [ 'live', 'test' ] as $env ) {
			$rows[] = [ 'env' => $env ] + $data[ $env ];
		}
		Output::rows( $rows, self::FIELDS, 'table' );
	}
}
