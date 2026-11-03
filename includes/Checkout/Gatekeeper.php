<?php
/**
 * The payment gateway filter shared by the classic and block checkout.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use LightweightPlugins\TrustOrder\Log;
use LightweightPlugins\TrustOrder\Settings;

/**
 * One server-side filter serves both checkouts: the Store API cart's
 * `payment_methods` is built from get_available_payment_gateways()
 * (src/StoreApi/Schemas/V1/CartSchema.php:377), and both checkout routes
 * validate the chosen method against it (class-wc-checkout.php:1061,
 * src/StoreApi/Routes/V1/Checkout.php:973; WC 11.2.1).
 */
final class Gatekeeper {

	/**
	 * Hidden ids of the last filter run in this request.
	 *
	 * @var list<string>
	 */
	private static array $last_hidden = [];

	/**
	 * Whether this request places an order (submit budget).
	 *
	 * @var bool
	 */
	private static bool $submit = false;

	/**
	 * Register the filter (late, after gateways' own availability rules).
	 */
	public static function register(): void {
		add_filter( 'woocommerce_available_payment_gateways', [ self::class, 'filterGateways' ], 100 );
	}

	/**
	 * Forget per-request state (tests).
	 */
	public static function reset(): void {
		self::$last_hidden = [];
		self::$submit      = false;
	}

	/**
	 * The rest of this request places an order: decisions come from
	 * Checker::decideForSubmit().
	 */
	public static function enterSubmit(): void {
		self::$submit = true;
	}

	/**
	 * `woocommerce_available_payment_gateways` callback. Never throws.
	 *
	 * @param mixed $gateways Gateways keyed by id.
	 * @return mixed
	 */
	public static function filterGateways( $gateways ) {
		self::$last_hidden = [];
		if ( ! is_array( $gateways ) || [] === $gateways ) {
			return $gateways;
		}

		try {
			$mode = Scope::mode();
			if ( Scope::OFF === $mode ) {
				return $gateways;
			}
			$settings = Settings::get();
			$decision = Scope::ORDER_PAY === $mode ? OrderPayGate::decision() : self::decision( $mode, $settings['strictness'] );
			if ( null === $decision ) {
				return $gateways;
			}

			self::$last_hidden = HideRule::hidden( $decision, array_map( 'strval', array_keys( $gateways ) ), $settings );
			foreach ( self::$last_hidden as $id ) {
				unset( $gateways[ $id ] );
			}
		} catch ( \Throwable $e ) {
			self::$last_hidden = [];
			Log::error( 'checkout', [ 'code' => Checker::EXCEPTION ] );
		}

		return $gateways;
	}

	/**
	 * Gateway ids hidden for the current request (runs the filter once more;
	 * the decision itself is memoised).
	 *
	 * @return list<string>
	 */
	public static function hiddenNow(): array {
		self::$last_hidden = [];
		try {
			/**
			 * WC() members can be null outside a full frontend request (stubs say otherwise).
			 *
			 * @var mixed $wc
			 */
			$wc = WC();
			if ( is_object( $wc->payment_gateways ?? null ) ) {
				$wc->payment_gateways->get_available_payment_gateways();
			}
		} catch ( \Throwable $e ) {
			Log::error( 'checkout', [ 'code' => Checker::EXCEPTION ] );

			return [];
		}

		return self::$last_hidden;
	}

	/**
	 * Decision for the current checkout identity.
	 *
	 * @param string $mode       Scope::LIVE or Scope::PEEK.
	 * @param string $strictness Strictness.
	 */
	private static function decision( string $mode, string $strictness ): ?Decision {
		$ids = self::currentIdentity();
		if ( null === $ids ) {
			return null;
		}
		$checker = Checker::current();
		if ( Scope::PEEK === $mode ) {
			return $checker->peek( $ids, $strictness );
		}

		return self::$submit ? $checker->decideForSubmit( $ids, $strictness ) : $checker->decide( $ids, $strictness );
	}

	/**
	 * The buyer's identity for this request: the classic review's post_data
	 * when present, else the session customer.
	 */
	private static function currentIdentity(): ?IdentitySet {
		/**
		 * WC() members can be null outside a full frontend request (stubs say otherwise).
		 *
		 * @var mixed $wc
		 */
		$wc = WC();
		if ( ! is_object( $wc->customer ?? null ) || ! $wc->customer instanceof \WC_Customer ) {
			return null;
		}
		$needs   = is_object( $wc->cart ?? null ) && (bool) $wc->cart->needs_shipping();
		$methods = is_object( $wc->session ?? null ) ? array_values( array_map( 'strval', wc_get_chosen_shipping_method_ids() ) ) : [];

		$post_data = ClassicCheckout::reviewData();
		if ( null !== $post_data ) {
			return IdentityBuilder::fromPostData( $post_data, $needs, $methods );
		}

		return IdentityBuilder::fromCustomer( $wc->customer, $needs, $methods );
	}
}
