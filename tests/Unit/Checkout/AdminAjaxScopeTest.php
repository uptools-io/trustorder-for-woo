<?php
/**
 * Checkout rules on admin-ajax.php (WooCommerce registers its checkout events there too).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Checkout\Enforcer;
use LightweightPlugins\TrustOrder\Checkout\Scope;
use WP_Error;

final class AdminAjaxScopeTest extends CheckoutTestCase {

	public function test_checkout_event_over_admin_ajax_is_live_and_enforced(): void {
		$this->onCheckout();
		Enforcer::register();
		$GLOBALS['trustorder_test']['admin'] = true;
		$GLOBALS['trustorder_test']['ajax']  = true;
		$this->answers                       = [ $this->verdict( 'block', 'blocked' ) ];
		$errors                              = new WP_Error();

		$this->assertSame( Scope::LIVE, Scope::mode() );
		$this->assertSame( [ 'bacs' ], $this->visibleGateways() );

		do_action( 'woocommerce_after_checkout_validation', [ 'payment_method' => 'cod' ], $errors );
		$this->assertSame( [ 'trustorder_payment_not_allowed' ], $errors->get_error_codes() );
	}

	public function test_admin_without_ajax_stays_off(): void {
		$this->onCheckout();
		$GLOBALS['trustorder_test']['admin'] = true;

		$this->assertSame( Scope::OFF, Scope::mode() );
	}

	public function test_other_admin_ajax_action_without_checkout_context_is_off(): void {
		$this->onCheckout();
		$GLOBALS['trustorder_test']['admin']    = true;
		$GLOBALS['trustorder_test']['ajax']     = true;
		$GLOBALS['trustorder_test']['checkout'] = false;

		$this->assertSame( Scope::OFF, Scope::mode() );
	}
}
