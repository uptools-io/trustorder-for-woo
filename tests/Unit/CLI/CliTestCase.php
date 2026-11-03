<?php
/**
 * Shared setup for the WP-CLI command tests.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\CLI;

use LightweightPlugins\TrustOrder\Tests\Unit\Signals\SignalTestCase;
use TrustOrder_Test_CliError;
use WP_CLI;

abstract class CliTestCase extends SignalTestCase {

	protected function setUp(): void {
		parent::setUp();
		WP_CLI::reset();
	}

	/**
	 * Run a command callable: the exit status like the shell sees it (WP_CLI::error() = 1).
	 */
	protected function exec( callable $run ): int {
		try {
			$run();
		} catch ( TrustOrder_Test_CliError $e ) {
			return 1;
		}

		return 0;
	}

	/**
	 * Everything the command printed.
	 */
	protected function output(): string {
		return implode( "\n", array_merge( WP_CLI::$lines, WP_CLI::$successes, WP_CLI::$warnings, WP_CLI::$errors ) ) . json_encode( WP_CLI::$tables );
	}

	/**
	 * The rows of the last printed table, keyed by the first field.
	 *
	 * @return array<string, mixed>
	 */
	protected function table(): array {
		$last = WP_CLI::$tables[ count( WP_CLI::$tables ) - 1 ] ?? [ 'table', [], [] ];

		return $last[1];
	}

	/**
	 * Decoded JSON of the last printed line.
	 *
	 * @return array<string, mixed>
	 */
	protected function json(): array {
		$data = json_decode( WP_CLI::$lines[ count( WP_CLI::$lines ) - 1 ] ?? '', true );
		$this->assertIsArray( $data );

		return $data;
	}
}
