<?php
/**
 * WP-CLI command group: trustorder.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\CLI;

use LightweightPlugins\TrustOrder\Admin\SiteState;
use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Checkout\Decision;
use LightweightPlugins\TrustOrder\Identity\MxCache;
use LightweightPlugins\TrustOrder\Rest\BackfillController;
use LightweightPlugins\TrustOrder\Rest\ConnectionController;
use WP_Error;

/**
 * Manages TrustOrder from the command line, using the same services as the
 * admin screen. Exit status 0 on success, 1 on an API or validation error
 * (the message is the error code only).
 *
 * ## EXAMPLES
 *
 *     wp trustorder status
 *     wp trustorder check --email=buyer@example.com
 */
final class Command {

	/**
	 * Register `wp trustorder` and its subcommand groups (WP-CLI only).
	 */
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'trustorder', self::class );
		\WP_CLI::add_command( 'trustorder settings', SettingsCommand::class );
		\WP_CLI::add_command( 'trustorder backfill', BackfillCommand::class );
		\WP_CLI::add_command( 'trustorder signal', SignalCommand::class );
		\WP_CLI::add_command( 'trustorder exceptions', ExceptionsCommand::class );
	}

	/**
	 * Shows the key (prefix only), the connection, the last error, the
	 * environment checks and the backfill state of both environments.
	 *
	 * Exits with status 1 when the service cannot be reached.
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
		$format = Output::format( $assoc );
		$local  = SiteState::overview();
		$test   = ConnectionController::test();
		$fill   = BackfillController::status()->get_data();
		$data   = [
			'env'             => $local['env'],
			'api_key'         => $local['key_prefix'],
			'enabled'         => $local['enabled'],
			'connection'      => self::connection( $test ),
			'last_error'      => $local['last_error'],
			'intl'            => $local['intl'],
			'hpos'            => $local['hpos'],
			'blocks_checkout' => $local['blocks_checkout'],
			'breaker'         => $local['breaker'],
			'submit_cap'      => $local['submit_cap'],
			'backfill'        => [
				'live' => $fill['live'],
				'test' => $fill['test'],
			],
		];
		Output::pairs( $data, $format );
		if ( $test instanceof WP_Error && 'trustorder_no_key' !== $test->get_error_code() ) {
			Output::wpError( $test );
		}
	}

	/**
	 * Checks one buyer identity against the service. Bypasses the result
	 * cache and the checkout quotas (admin tool); the input is hashed locally
	 * and never logged.
	 *
	 * ## OPTIONS
	 *
	 * --email=<email>
	 * : Buyer e-mail address.
	 *
	 * [--phone=<phone>]
	 * : Phone number (international form, or national with --country).
	 *
	 * [--country=<country>]
	 * : Two-letter country code (default: the shop's base country).
	 *
	 * [--postcode=<postcode>]
	 * : Delivery postcode.
	 *
	 * [--address=<address>]
	 * : Delivery street and house number.
	 *
	 * [--strictness=<strictness>]
	 * : lenient, normal or strict (default: the saved setting).
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @param array<int, string>   $args  Positional args.
	 * @param array<string, mixed> $assoc Associative args.
	 */
	public function check( array $args, array $assoc ): void {
		$format     = Output::format( $assoc );
		$strictness = CheckInput::strictness( $assoc );
		$ids        = CheckInput::identity( $assoc );

		$body                    = $ids->toCheckBody();
		$body['email_domain_mx'] = MxCache::get( (string) $ids->emailDomain() );
		$body['strictness']      = $strictness;

		$decision = Decision::fromResponse( Client::fromSettings()->check( $body ) );
		if ( $decision->failedOpen() ) {
			Output::fail( (string) $decision->errorCode() );
		}
		Output::pairs(
			[
				'verdict'  => $decision->verdict(),
				'level'    => $decision->level(),
				'score'    => $decision->score(),
				'reasons'  => $decision->reasons(),
				'check_id' => $decision->checkId(),
			],
			$format
		);
	}

	/**
	 * The `/me` result for the status output.
	 *
	 * @param mixed $test Result of ConnectionController::test().
	 * @return array<string, mixed>
	 */
	private static function connection( $test ): array {
		if ( $test instanceof WP_Error ) {
			$data = $test->get_error_data();
			$code = is_array( $data ) && isset( $data['api_code'] ) ? $data['api_code'] : null;

			return 'trustorder_no_key' === $test->get_error_code()
				? [ 'status' => 'no_key' ]
				: [
					'status' => 'error',
					'error'  => $code,
				];
		}

		return [ 'status' => 'ok' ] + $test->get_data()['me'];
	}
}
