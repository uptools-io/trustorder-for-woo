<?php
/**
 * Order list column and edit-screen metabox (HPOS + legacy).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Order;

use LightweightPlugins\TrustOrder\Admin\CapNotice;
use LightweightPlugins\TrustOrder\Checkout\QuotaGuard;
use LightweightPlugins\TrustOrder\Order\Labels;
use LightweightPlugins\TrustOrder\Order\OrderColumn;
use LightweightPlugins\TrustOrder\Order\OrderMetabox;
use LightweightPlugins\TrustOrder\Settings;
use PHPUnit\Framework\TestCase;

final class OrderAdminTest extends TestCase {

	protected function setUp(): void {
		trustorder_test_reset();
	}

	private function order( ?array $result ): \WC_Order {
		$o = new \WC_Order( [ 'id' => 7 ] );
		if ( null !== $result ) {
			$o->meta['_trustorder_result'] = (string) json_encode( $result );
		}
		$GLOBALS['trustorder_test']['orders'][7] = $o;

		return $o;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function result( string $verdict, string $level ): array {
		return [
			'verdict'    => $verdict,
			'level'      => $level,
			'score'      => 41,
			'reasons'    => [ 'LOW_SCORE', 'NEGATIVE_RECENT', 'UNKNOWN_CODE<script>' ],
			'check_id'   => '01a1a17c-7e54-7032-95b4-ba478164249b',
			'env'        => 'test',
			'checked_at' => '2026-11-03T10:00:00+00:00',
		];
	}

	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	public function test_column_on_both_order_screens(): void {
		Settings::update( [ 'api_key' => 'to_test_0123456789abcdefABCDEF0123456789' ] );
		OrderColumn::register();
		$cols = [ 'cb' => '', 'order_number' => 'Order', 'order_status' => 'Status', 'order_total' => 'Total' ];

		foreach ( [ 'manage_woocommerce_page_wc-orders_columns', 'manage_edit-shop_order_columns' ] as $hook ) {
			$out = apply_filters( $hook, $cols );
			$this->assertSame( [ 'cb', 'order_number', 'order_status', 'order_total', 'trustorder' ], array_keys( $out ), $hook );
		}
	}

	public function test_column_is_titled_cod_check_at_the_end(): void {
		Settings::update( [ 'api_key' => 'to_test_0123456789abcdefABCDEF0123456789' ] );
		$cols = OrderColumn::addColumn( [ 'cb' => '', 'order_status' => 'Status', 'order_total' => 'Total', 'wc_actions' => 'Actions' ] );

		$this->assertSame( [ 'cb', 'order_status', 'order_total', 'wc_actions', OrderColumn::COLUMN ], array_keys( $cols ) );
		$this->assertSame( 'COD check', $cols[ OrderColumn::COLUMN ] );
		$this->assertSame( 'COD check', OrderColumn::addColumn( [ 'cb' => '' ] )[ OrderColumn::COLUMN ] );
	}

	public function test_a_buyer_without_history_is_a_green_new_customer(): void {
		$new = [ 'verdict' => 'allow', 'level' => 'unknown', 'score' => 75, 'reasons' => [ 'NO_HISTORY' ] ];
		$html = Labels::badge( $new );

		$this->assertStringContainsString( 'trustorder-level-new', $html );
		$this->assertStringContainsString( 'New customer', $html );
		$this->assertStringContainsString( 'background:#e7f5e9', $html );
		$this->assertStringNotContainsString( '75', $html );

		// Some history, too few deliveries: still "Unknown".
		$some = [ 'verdict' => 'allow', 'level' => 'unknown', 'score' => 80, 'reasons' => [] ];
		$this->assertStringContainsString( 'trustorder-level-unknown', Labels::badge( $some ) );
		$this->assertStringContainsString( 'Unknown', Labels::badge( $some ) );
	}

	public function test_a_result_without_score_shows_the_level_only(): void {
		$html = Labels::badge( [ 'verdict' => 'allow', 'level' => 'trusted', 'reasons' => [] ] );

		$this->assertStringContainsString( 'trustorder-level-trusted', $html );
		$this->assertStringNotContainsString( 'trustorder-score', $html );
		$this->assertStringContainsString( 'trustorder-score', Labels::badge( [ 'verdict' => 'allow', 'level' => 'trusted', 'score' => 0, 'reasons' => [] ] ) );
	}

	public function test_risky_and_blocked_badges_are_coloured(): void {
		$risky   = Labels::badge( [ 'verdict' => 'flag', 'level' => 'risky', 'score' => 41, 'reasons' => [ 'NEGATIVE_RECENT' ] ] );
		$blocked = Labels::badge( [ 'verdict' => 'block', 'level' => 'blocked', 'score' => 12, 'reasons' => [ 'NEGATIVE_MULTI_ACCOUNT' ] ] );

		$this->assertStringContainsString( 'background:#fcf0e1;color:#8a4600', $risky );
		$this->assertStringContainsString( 'background:#fbe7e7;color:#a1191d', $blocked );
		$this->assertStringContainsString( '12', $blocked );
	}

	public function test_hpos_column_renders_level_badge(): void {
		OrderColumn::register();
		$o = $this->order( self::result( 'flag', 'risky' ) );

		$html = $this->capture( static fn() => do_action( 'manage_woocommerce_page_wc-orders_custom_column', 'trustorder', $o ) );

		$this->assertStringContainsString( 'dashicons', $html );
		$this->assertStringContainsString( 'trustorder-level-risky', $html );
		$this->assertStringContainsString( '41', $html );
	}

	public function test_legacy_column_loads_the_order_by_id(): void {
		OrderColumn::register();
		$this->order( self::result( 'block', 'blocked' ) );

		$html = $this->capture( static fn() => do_action( 'manage_shop_order_posts_custom_column', 'trustorder', 7 ) );

		$this->assertStringContainsString( 'trustorder-level-blocked', $html );
	}

	public function test_other_columns_and_unchecked_orders_render_nothing_noisy(): void {
		OrderColumn::register();
		$o = $this->order( null );

		$this->assertSame( '', $this->capture( static fn() => do_action( 'manage_woocommerce_page_wc-orders_custom_column', 'order_total', $o ) ) );
		$this->assertStringNotContainsString( 'dashicons', $this->capture( static fn() => do_action( 'manage_woocommerce_page_wc-orders_custom_column', 'trustorder', $o ) ) );
	}

	public function test_metabox_on_both_screens(): void {
		OrderMetabox::register();
		do_action( 'add_meta_boxes', 'woocommerce_page_wc-orders', null );

		$screens = array_column( $GLOBALS['trustorder_test']['metaboxes'], 'screen' );
		sort( $screens );
		$this->assertSame( [ 'shop_order', 'woocommerce_page_wc-orders' ], $screens );
	}

	public function test_metabox_renders_reasons_and_check_id_escaped(): void {
		$o    = $this->order( self::result( 'flag', 'risky' ) );
		$hpos = $this->capture( static fn() => OrderMetabox::render( $o ) );
		$post = $this->capture( static fn() => OrderMetabox::render( new \WP_Post( 7 ) ) );

		$this->assertSame( $hpos, $post, 'WP_Post and WC_Order render the same' );
		$this->assertStringContainsString( '01a1a17c-7e54-7032-95b4-ba478164249b', $hpos );
		$this->assertStringContainsString( 'dashicons', $hpos );
		$this->assertStringNotContainsString( '<script>', $hpos );
		$this->assertStringContainsString( 'UNKNOWN_CODE&lt;script&gt;', $hpos );
		$this->assertDoesNotMatchRegularExpression( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $hpos, 'no emoji' );
	}

	public function test_metabox_shows_fail_open_and_unchecked_states(): void {
		$o = $this->order( [ 'verdict' => 'allow', 'failed_open' => true, 'error' => 'TIMEOUT', 'env' => 'test', 'checked_at' => '2026-11-03T10:00:00+00:00' ] );
		$this->assertStringContainsString( 'TIMEOUT', $this->capture( static fn() => OrderMetabox::render( $o ) ) );

		$p = $this->order( null );
		$this->assertNotSame( '', $this->capture( static fn() => OrderMetabox::render( $p ) ) );
	}

	public function test_fallback_result_shows_not_checked_state(): void {
		$o = $this->order( [ 'verdict' => 'block', 'fallback' => 'SUBMIT_QUOTA', 'env' => 'test', 'checked_at' => '2026-11-03T10:00:00+00:00' ] );

		$box = $this->capture( static fn() => OrderMetabox::render( $o ) );
		$this->assertStringContainsString( 'Not checked (limit reached)', $box );
		$this->assertStringContainsString( 'SUBMIT_QUOTA', $box );
		$this->assertStringContainsString( 'Risky payment methods stayed hidden', $box );
		$this->assertStringNotContainsString( 'trustorder-level-blocked', $box );

		OrderColumn::register();
		$cell = $this->capture( static fn() => do_action( 'manage_woocommerce_page_wc-orders_custom_column', 'trustorder', $o ) );
		$this->assertStringContainsString( 'Not checked (limit reached)', $cell );
	}

	public function test_cap_notices_show_after_a_trip_and_dismiss_per_trip(): void {
		$screen     = new \WP_Screen();
		$screen->id = 'woocommerce_page_wc-orders';
		$GLOBALS['trustorder_test']['screen'] = $screen;
		$render = fn(): string => $this->capture( static fn() => CapNotice::render() );

		$this->assertSame( '', $render() );

		set_transient( QuotaGuard::BREAKER_TRANSIENT, 1000, 3600 );
		set_transient( QuotaGuard::SUBMIT_IP_TRANSIENT, 1000, 3600 );
		$html = $render();
		$this->assertStringContainsString( 'in the last hour', $html );
		$this->assertStringNotContainsString( 'until the next hour', $html );
		$this->assertStringContainsString( 'place-order check limit', $html );
		$this->assertSame( 2, substr_count( $html, 'notice-warning' ) );

		$_GET = [ '_wpnonce' => wp_create_nonce( CapNotice::ACTION ), 'notice' => QuotaGuard::BREAKER_TRANSIENT ];
		$this->assertTrue( CapNotice::recordDismissal() );
		$_GET = [];
		$this->assertSame( 1, substr_count( $render(), 'notice-warning' ) );

		set_transient( QuotaGuard::BREAKER_TRANSIENT, 2000, 3600 );
		$this->assertSame( 2, substr_count( $render(), 'notice-warning' ), 'a new trip shows again' );

		$_GET = [ '_wpnonce' => 'forged', 'notice' => QuotaGuard::SUBMIT_IP_TRANSIENT ];
		$this->assertFalse( CapNotice::recordDismissal() );
		$_GET = [];

		$GLOBALS['trustorder_test']['caps'] = [];
		$this->assertSame( '', $render() );
		$GLOBALS['trustorder_test']['caps'] = [ 'manage_woocommerce' => true ];
		$screen->id                         = 'dashboard';
		$this->assertSame( '', $render() );
	}
}
