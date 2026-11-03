<?php
/**
 * Shared setup for the checkout tests.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Checkout\Checker;
use LightweightPlugins\TrustOrder\Checkout\ClassicCheckout;
use LightweightPlugins\TrustOrder\Checkout\Gatekeeper;
use LightweightPlugins\TrustOrder\Checkout\HideRule;
use LightweightPlugins\TrustOrder\Checkout\QuotaGuard;
use LightweightPlugins\TrustOrder\Checkout\Scope;
use LightweightPlugins\TrustOrder\Settings;
use PHPUnit\Framework\TestCase;
use WP_Error;

abstract class CheckoutTestCase extends TestCase {

	protected const KEY = 'to_test_abcdefghijABCDEFGHIJ0123456789xy';

	/**
	 * API answers, in order (the last one repeats).
	 *
	 * @var list<array<string, mixed>|WP_Error>
	 */
	protected array $answers = [];

	protected function setUp(): void {
		trustorder_test_reset();
		Checker::reset();
		Gatekeeper::reset();
		ClassicCheckout::reset();
		Scope::setRoute( null );
		HideRule::reset();
		QuotaGuard::reset();
		\LightweightPlugins\TrustOrder\Checkout\Counter::setClock( null );
		Settings::update( [ 'api_key' => self::KEY ] );
		Gatekeeper::register();
		$this->answers = [];

		add_filter(
			'pre_http_request',
			function () {
				if ( [] === $this->answers ) {
					return $this->verdict( 'allow' );
				}
				return count( $this->answers ) > 1 ? array_shift( $this->answers ) : $this->answers[0];
			}
		);
	}

	/**
	 * A 200 /check response.
	 *
	 * @return array<string, mixed>
	 */
	protected function verdict( string $verdict, string $level = 'unknown', int $score = 50, array $reasons = [ 'NO_HISTORY' ] ): array {
		return $this->http(
			200,
			(string) json_encode(
				[
					'verdict'  => $verdict,
					'level'    => $level,
					'score'    => $score,
					'reasons'  => $reasons,
					'stats'    => [ 'delivered' => 0, 'refused' => 0, 'accounts_refused' => 0 ],
					'check_id' => '01a1a17c-7e54-7032-95b4-ba478164249b',
				]
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	protected function http( int $code, string $body ): array {
		return [
			'headers'  => [],
			'body'     => $body,
			'response' => [ 'code' => $code, 'message' => '' ],
		];
	}

	/**
	 * Number of /check requests made.
	 */
	protected function checks(): int {
		return count(
			array_filter(
				$GLOBALS['trustorder_test']['http'],
				static fn( array $r ): bool => str_ends_with( $r['url'], '/check' )
			)
		);
	}

	/**
	 * Decoded body of the last /check request.
	 *
	 * @return array<string, mixed>
	 */
	protected function lastBody(): array {
		$all = $GLOBALS['trustorder_test']['http'];
		$this->assertNotEmpty( $all );

		return json_decode( end( $all )['args']['body'], true );
	}

	/**
	 * A classic checkout page with a customer.
	 *
	 * @param array<string, mixed> $customer Customer props.
	 */
	protected function onCheckout( array $customer = [] ): \TrustOrder_Test_WC {
		$GLOBALS['trustorder_test']['checkout'] = true;

		return trustorder_test_wc( $customer + self::customer() );
	}

	/**
	 * @return array<string, string>
	 */
	protected static function customer( string $email = 'buyer@example.com' ): array {
		return [
			'billing_email'      => $email,
			'billing_phone'      => '+36 30 123 4567',
			'billing_country'    => 'HU',
			'shipping_country'   => 'HU',
			'shipping_postcode'  => '1051',
			'shipping_address_1' => 'Fő utca 3',
			'shipping_address_2' => '',
		];
	}

	/**
	 * Gateway ids left after the filter.
	 *
	 * @return list<string>
	 */
	protected function visibleGateways(): array {
		return array_keys( WC()->payment_gateways->get_available_payment_gateways() );
	}
}
