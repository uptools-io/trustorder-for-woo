<?php
/**
 * Admin notices: visitors seen with a private / local address, and the
 * service's daily check quota.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Checkout;

use LightweightPlugins\TrustOrder\Admin\CapNotice;
use LightweightPlugins\TrustOrder\Admin\ProxyNotice;
use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Checkout\Checker;
use LightweightPlugins\TrustOrder\Checkout\QuotaGuard;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;

final class LimitNoticeTest extends CheckoutTestCase {

	protected function setUp(): void {
		parent::setUp();
		$screen     = new \WP_Screen();
		$screen->id = 'woocommerce_page_wc-orders';
		$GLOBALS['trustorder_test']['screen'] = $screen;
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	private function check( bool $submit = false, string $email = 'a@example.com' ): void {
		$checker = new Checker( new Client( self::KEY, 'https://api.example.test/v1/trustorder' ), 'test', 's:a' );
		$ids     = IdentitySet::fromOrderData( [ 'email' => $email ] );
		$submit ? $checker->decideForSubmit( $ids, 'normal' ) : $checker->decide( $ids, 'normal' );
	}

	private function render( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	public function test_a_local_visitor_address_is_recorded_and_noticed_without_dismiss(): void {
		$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
		$this->check();

		$this->assertNotFalse( get_transient( QuotaGuard::LOCAL_CLIENT_TRANSIENT ) );
		$this->assertSame( DAY_IN_SECONDS, $GLOBALS['trustorder_test']['transients'][ QuotaGuard::LOCAL_CLIENT_TRANSIENT ]['ttl'] );

		$html = $this->render( static fn() => ProxyNotice::render() );
		$this->assertStringContainsString( 'trustorder_client_ip', $html );
		$this->assertStringNotContainsString( 'admin-post.php', $html, 'not dismissible' );
	}

	public function test_place_order_records_it_too_and_only_once(): void {
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$this->check( true );
		$GLOBALS['trustorder_test']['transients'][ QuotaGuard::LOCAL_CLIENT_TRANSIENT ]['value'] = 123;
		$this->check( true, 'b@example.com' );

		$this->assertSame( 123, get_transient( QuotaGuard::LOCAL_CLIENT_TRANSIENT ) );
	}

	public function test_a_public_address_or_the_client_ip_filter_records_nothing(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->check();
		$this->assertFalse( get_transient( QuotaGuard::LOCAL_CLIENT_TRANSIENT ) );

		$_SERVER['REMOTE_ADDR'] = '10.0.0.2';
		add_filter( 'trustorder_client_ip', static fn( $ip ) => $ip );
		$this->check( false, 'b@example.com' );
		$this->assertFalse( get_transient( QuotaGuard::LOCAL_CLIENT_TRANSIENT ) );
	}

	public function test_the_notice_hides_once_the_filter_is_set_or_without_capability(): void {
		set_transient( QuotaGuard::LOCAL_CLIENT_TRANSIENT, time(), DAY_IN_SECONDS );
		$GLOBALS['trustorder_test']['caps'] = [];
		$this->assertSame( '', $this->render( static fn() => ProxyNotice::render() ) );

		$GLOBALS['trustorder_test']['caps'] = [ 'manage_woocommerce' => true ];
		add_filter( 'trustorder_client_ip', static fn( $ip ) => $ip );
		$this->assertSame( '', $this->render( static fn() => ProxyNotice::render() ) );
	}

	public function test_daily_quota_answer_is_noticed_until_the_next_utc_day(): void {
		$this->answers = [ $this->http( 429, '{"code":"QUOTA_EXCEEDED","title":"daily check quota exceeded"}' ) ];
		$this->check();

		$t = $GLOBALS['trustorder_test']['transients'][ Checker::DAILY_QUOTA_TRANSIENT ] ?? null;
		$this->assertNotNull( $t );
		$this->assertGreaterThan( 0, $t['ttl'] );
		$this->assertLessThanOrEqual( DAY_IN_SECONDS, $t['ttl'] );
		$this->assertLessThanOrEqual( 2, ( time() + $t['ttl'] ) % DAY_IN_SECONDS, 'expires at UTC midnight' );

		$html = $this->render( static fn() => CapNotice::render() );
		$this->assertStringContainsString( 'daily check limit', $html );
		$this->assertStringContainsString( 'notice=' . Checker::DAILY_QUOTA_TRANSIENT, $html, 'dismissible like the cap notices' );
	}

	public function test_other_answers_do_not_set_the_daily_quota_flag(): void {
		$this->answers = [ $this->http( 429, '{"code":"RATE_LIMITED"}' ) ];
		$this->check();

		$this->assertFalse( get_transient( Checker::DAILY_QUOTA_TRANSIENT ) );
	}
}
