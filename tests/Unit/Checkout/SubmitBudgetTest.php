<?php
/**
 * Place-order budget: shop-wide hourly cap and the first check of a session.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Admin\CapNotice;
use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Checkout\Checker;
use LightweightPlugins\TrustOrder\Checkout\Counter;
use LightweightPlugins\TrustOrder\Checkout\QuotaGuard;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;

final class SubmitBudgetTest extends CheckoutTestCase {

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	private function submit( string $session, string $email ): \LightweightPlugins\TrustOrder\Checkout\Decision {
		$checker = new Checker( new Client( self::KEY, 'https://api.example.test/v1/trustorder' ), 'test', $session );

		return $checker->decideForSubmit( IdentitySet::fromOrderData( [ 'email' => $email ] ), 'normal' );
	}

	public function test_default_shop_wide_place_order_limit(): void {
		$this->assertSame( 300, QuotaGuard::SUBMIT_SHOP_MAX );
	}

	public function test_shop_wide_place_order_cap_fails_open_and_is_marked(): void {
		add_filter( 'trustorder_hourly_submit_check_limit', static fn(): int => 2 );

		$this->assertFalse( $this->submit( 's:a', 'a@example.com' )->isFallback() );
		$this->assertFalse( $this->submit( 's:b', 'b@example.com' )->isFallback() );
		$third = $this->submit( 's:c', 'c@example.com' );

		// One client spreading requests over many sessions must not hide payment
		// methods from every other buyer: the shop-wide cap only protects the quota.
		$this->assertTrue( $third->failedOpen() );
		$this->assertSame( QuotaGuard::SUBMIT_SHOP_QUOTA, $third->errorCode() );
		$this->assertSame( 2, $this->checks() );
		$this->assertTrue( QuotaGuard::submitShopCapTripped() );
		$this->assertSame( 0, Counter::count( 'submit|s:c', QuotaGuard::WINDOW ), 'a denial consumes no session unit' );
	}

	public function test_shop_wide_cap_keeps_blocking_the_networks_that_spent_it(): void {
		add_filter( 'trustorder_hourly_submit_check_limit', static fn(): int => 2 );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.72';
		$this->submit( 's:a', 'a@example.com' );
		$this->submit( 's:b', 'b@example.com' );

		$same = $this->submit( 's:c', 'c@example.com' );
		$this->assertSame( 'block', $same->verdict() );
		$this->assertSame( QuotaGuard::SUBMIT_SHOP_QUOTA, $same->fallbackCode() );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$other = $this->submit( 's:d', 'd@example.com' );
		$this->assertTrue( $other->failedOpen() );
		$this->assertSame( QuotaGuard::SUBMIT_SHOP_QUOTA, $other->errorCode() );
		$this->assertSame( 2, $this->checks() );
	}

	public function test_shop_wide_cap_does_not_touch_browsing(): void {
		add_filter( 'trustorder_hourly_submit_check_limit', static fn(): int => 0 );
		$checker = new Checker( new Client( self::KEY, 'https://api.example.test/v1/trustorder' ), 'test', 's:a' );

		$this->assertFalse( $checker->decide( IdentitySet::fromOrderData( [ 'email' => 'a@example.com' ] ), 'normal' )->isFallback() );
		$this->assertSame( 1, $this->checks() );
	}

	public function test_a_new_sessions_first_check_passes_a_spent_network_cap(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.70';
		add_filter( 'trustorder_submit_ip_check_limit', static fn(): int => 1 );

		$this->assertFalse( $this->submit( 's:a', 'a1@example.com' )->isFallback() );
		$this->assertFalse( $this->submit( 's:b', 'b1@example.com' )->isFallback(), 'first real check of s:b' );
		$this->assertSame( 2, Counter::count( 'submit-ip|198.51.100.70', QuotaGuard::WINDOW ), 'still counted' );

		$again = $this->submit( 's:b', 'b2@example.com' );
		$this->assertTrue( $again->isFallback(), 's:b had its real check' );
		$this->assertSame( QuotaGuard::IP_QUOTA, $again->fallbackCode() );
		$this->assertSame( 2, $this->checks() );
	}

	public function test_default_first_check_limit_per_network(): void {
		$this->assertSame( 30, QuotaGuard::FIRST_IP_MAX );
	}

	public function test_fresh_sessions_from_one_network_stop_at_the_first_check_cap_without_draining_the_shop(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.73';
		add_filter( 'trustorder_submit_ip_check_limit', static fn(): int => 1 );
		add_filter( 'trustorder_first_check_ip_limit', static fn(): int => 3 );
		add_filter( 'trustorder_hourly_submit_check_limit', static fn(): int => 5 );

		for ( $i = 1; $i <= 10; $i++ ) {
			$d = $this->submit( "s:f{$i}", "f{$i}@example.com" );
			$this->assertSame( $i <= 3 ? null : QuotaGuard::IP_QUOTA, $d->fallbackCode(), "session {$i}" );
		}
		$this->assertSame( 3, $this->checks() );
		$this->assertSame( 3, Counter::count( 'submit-shop', QuotaGuard::SHOP_WINDOW ), 'denied network requests take no shop unit' );
		$this->assertFalse( QuotaGuard::submitShopCapTripped() );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.74';
		$this->assertFalse( $this->submit( 's:other', 'o@example.com' )->isFallback(), 'another network is still checked' );
	}

	public function test_a_session_that_already_spent_a_unit_is_not_first(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.71';
		add_filter( 'trustorder_submit_ip_check_limit', static fn(): int => 1 );
		$this->submit( 's:a', 'a1@example.com' );
		Counter::incr( 'submit|s:b', QuotaGuard::WINDOW );

		$this->assertSame( QuotaGuard::IP_QUOTA, $this->submit( 's:b', 'b1@example.com' )->fallbackCode() );
	}

	public function test_the_first_check_still_respects_the_shop_wide_cap(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.72';
		add_filter( 'trustorder_submit_ip_check_limit', static fn(): int => 1 );
		add_filter( 'trustorder_hourly_submit_check_limit', static fn(): int => 1 );
		$this->submit( 's:a', 'a1@example.com' );

		$this->assertSame( QuotaGuard::SUBMIT_SHOP_QUOTA, $this->submit( 's:b', 'b1@example.com' )->fallbackCode(), 'same network spent it' );
		$this->assertSame( 1, $this->checks() );
	}

	public function test_admin_notice_after_a_shop_wide_place_order_trip(): void {
		add_filter( 'trustorder_hourly_submit_check_limit', static fn(): int => 0 );
		$this->submit( 's:a', 'a@example.com' );
		$screen     = new \WP_Screen();
		$screen->id = 'woocommerce_page_wc-orders';
		$GLOBALS['trustorder_test']['screen'] = $screen;

		ob_start();
		CapNotice::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'trustorder_hourly_submit_check_limit', $html );
		$this->assertStringContainsString( 'notice=' . QuotaGuard::SUBMIT_SHOP_TRANSIENT, $html );
	}
}
