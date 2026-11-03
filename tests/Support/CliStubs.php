<?php
/**
 * WP-CLI polyfills: the WP_CLI class (output capture, error() throws instead
 * of exiting) and the WP_CLI\Utils helpers the commands call.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

// phpcs:ignoreFile -- Test-only global polyfills that mirror WP-CLI signatures.

namespace {

	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Thrown by WP_CLI::error() (the real one exits with status 1).
		 */
		class TrustOrder_Test_CliError extends \RuntimeException {}

		/**
		 * Progress bar stand-in.
		 */
		class TrustOrder_Test_Bar {
			public int $ticks = 0;
			public bool $finished = false;
			public function __construct( public string $label, public int $total ) {}
			public function tick( $n = 1 ) {
				$this->ticks += (int) $n;
			}
			public function finish() {
				$this->finished = true;
			}
		}

		/**
		 * Minimal WP_CLI.
		 */
		class WP_CLI {
			/** @var list<string> */
			public static array $lines = [];
			/** @var list<string> */
			public static array $successes = [];
			/** @var list<string> */
			public static array $warnings = [];
			/** @var list<string> */
			public static array $errors = [];
			/** @var array<string, mixed> */
			public static array $commands = [];
			/** @var array<string, mixed> */
			public static array $config = [ 'prompt' => false ];
			/** @var list<array{0: string, 1: array<mixed>, 2: array<mixed>}> */
			public static array $tables = [];
			/** @var list<TrustOrder_Test_Bar> */
			public static array $bars = [];

			public static function reset(): void {
				self::$lines     = [];
				self::$successes = [];
				self::$warnings  = [];
				self::$errors    = [];
				self::$commands  = [];
				self::$config    = [ 'prompt' => false ];
				self::$tables    = [];
				self::$bars      = [];
			}

			public static function line( $message = '' ) {
				self::$lines[] = (string) $message;
			}

			public static function log( $message ) {
				self::$lines[] = (string) $message;
			}

			public static function success( $message ) {
				self::$successes[] = (string) $message;
			}

			public static function warning( $message ) {
				self::$warnings[] = (string) $message;
			}

			public static function error( $message, $exit = true ) {
				self::$errors[] = (string) $message;
				throw new TrustOrder_Test_CliError( (string) $message );
			}

			public static function add_command( $name, $callable, $args = [] ) {
				self::$commands[ $name ] = $callable;
				return true;
			}

			public static function get_config( $key = null ) {
				return null === $key ? self::$config : ( self::$config[ $key ] ?? null );
			}
		}
	}
}

namespace WP_CLI\Utils {

	if ( ! function_exists( __NAMESPACE__ . '\format_items' ) ) {
		function format_items( $format, $items, $fields ) {
			\WP_CLI::$tables[] = [ (string) $format, is_array( $items ) ? $items : iterator_to_array( $items ), (array) $fields ];
		}

		function make_progress_bar( $message, $count, $interval = 100 ) {
			$bar                 = new \TrustOrder_Test_Bar( (string) $message, (int) $count );
			\WP_CLI::$bars[] = $bar;
			return $bar;
		}
	}
}
