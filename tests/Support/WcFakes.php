<?php
/**
 * Minimal WooCommerce fakes for the checkout/order unit tests.
 *
 * State lives in $GLOBALS['trustorder_test'] (see WpStubs.php): `wc` holds
 * the WC() object, `checkout` / `admin` / `endpoint` drive the conditionals.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

// phpcs:ignoreFile -- Test-only fakes that mirror WooCommerce class and function names.

namespace Automattic\WooCommerce\StoreApi\Exceptions {
	if ( ! class_exists( RouteException::class ) ) {
		/**
		 * Store API route exception (same constructor as WC's).
		 */
		class RouteException extends \Exception {
			/** @var string */
			public $error_code;
			/** @var int */
			public $http_status_code;

			public function __construct( $error_code, $message, $http_status_code = 400, $additional_data = [] ) {
				$this->error_code       = $error_code;
				$this->http_status_code = $http_status_code;
				parent::__construct( $message, $http_status_code );
			}

			public function getErrorCode() {
				return $this->error_code;
			}
		}
	}
}

namespace Automattic\WooCommerce\Utilities {
	if ( ! class_exists( OrderUtil::class ) ) {
		class OrderUtil {
			public static function custom_orders_table_usage_is_enabled() {
				return $GLOBALS['trustorder_test']['hpos'] ?? true;
			}
		}
	}
}

namespace {
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	/**
	 * Property bag with get_* / set_* accessors.
	 */
	abstract class TrustOrder_Test_Props {
		/** @var array<string, mixed> */
		public $p = [];

		public function __construct( array $p = [] ) {
			$this->p = $p;
		}

		public function __call( $name, $args ) {
			if ( str_starts_with( $name, 'get_' ) ) {
				return $this->p[ substr( $name, 4 ) ] ?? '';
			}
			if ( str_starts_with( $name, 'set_' ) ) {
				$this->p[ substr( $name, 4 ) ] = $args[0] ?? null;
				return null;
			}
			throw new \BadMethodCallException( $name );
		}
	}

	class WC_Customer extends TrustOrder_Test_Props {}

	class WC_Cart extends TrustOrder_Test_Props {
		public function needs_payment() {
			return (bool) ( $this->p['needs_payment'] ?? true );
		}

		public function needs_shipping() {
			return (bool) ( $this->p['needs_shipping'] ?? true );
		}
	}

	class WC_Session_Handler {
		/** @var string */
		public $customer_id;
		/** @var array<string, mixed> */
		public $data = [];

		public function __construct( $customer_id = 'sess-1' ) {
			$this->customer_id = $customer_id;
		}

		public function get_customer_id() {
			return $this->customer_id;
		}

		public function get( $key, $default = null ) {
			return $this->data[ $key ] ?? $default;
		}
	}

	class WC_Payment_Gateway {
		/** @var string */
		public $id;
		/** @var string */
		public $enabled = 'yes';
		/** @var string */
		public $title = '';

		public function __construct( $id = '', $enabled = 'yes', $title = '' ) {
			$this->id      = $id;
			$this->enabled = $enabled;
			$this->title   = $title;
		}

		public function get_title() {
			return '' !== $this->title ? $this->title : strtoupper( $this->id );
		}
	}

	class WC_Order_Item_Shipping {
		/** @var string */
		private $method_id;

		public function __construct( $method_id ) {
			$this->method_id = $method_id;
		}

		public function get_method_id() {
			return $this->method_id;
		}
	}

	/**
	 * Order without needs_shipping() (WooCommerce < 9.9).
	 */
	class WC_Order extends TrustOrder_Test_Props {
		/** @var array<string, mixed> */
		public $meta = [];
		/** @var list<array{note: string, customer: int}> */
		public $notes = [];
		/** @var int */
		public $saved = 0;
		/** @var list<WC_Order_Item_Shipping> */
		public $shipping = [];

		public function get_id() {
			return (int) ( $this->p['id'] ?? 0 );
		}

		public function get_meta( $key = '', $single = true, $context = 'view' ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( $key, $value, $meta_id = 0 ) {
			$this->meta[ $key ] = $value;
		}

		public function save() {
			++$this->saved;
			return $this->get_id();
		}

		public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ) {
			$this->notes[] = [ 'note' => $note, 'customer' => (int) $is_customer_note ];
			return count( $this->notes );
		}

		public function get_shipping_methods() {
			return $this->shipping;
		}

		public function save_meta_data() {
			++$this->meta_saves;
		}

		/** @var int */
		public $meta_saves = 0;
	}

	/**
	 * In-memory wc_get_orders(): status, type, date_created '>=ts', id cursor
	 * (HPOS field_query or the legacy posts_where filter), ID ASC, limit.
	 */
	function wc_get_orders( $args ) {
		$GLOBALS['trustorder_test']['order_queries'][] = $args;
		$after = 0;
		if ( isset( $args['field_query'][0] ) && 'id' === $args['field_query'][0]['field'] && '>' === $args['field_query'][0]['compare'] ) {
			$after = (int) $args['field_query'][0]['value'];
		} else {
			$where = (string) apply_filters( 'posts_where', '', new TrustOrder_Test_WP_Query( $args ) );
			if ( preg_match( '/ID > (\d+)/', $where, $m ) ) {
				$after = (int) $m[1];
			}
		}
		$since = isset( $args['date_created'] ) ? (int) substr( (string) $args['date_created'], 2 ) : 0;
		$out   = [];
		$all   = $GLOBALS['trustorder_test']['orders'] ?? [];
		ksort( $all );
		foreach ( $all as $id => $o ) {
			$created = (int) ( $o->p['created_ts'] ?? time() );
			if ( $id > $after && in_array( 'wc-' . $o->get_status(), (array) $args['status'], true ) && $created >= $since ) {
				$out[] = $o;
			}
		}
		if ( ! empty( $args['paginate'] ) ) {
			return (object) [ 'orders' => [], 'total' => count( $out ) ];
		}
		return array_slice( $out, 0, (int) ( $args['limit'] ?? 10 ) );
	}

	class TrustOrder_Test_WP_Query {
		/** @var array<string, mixed> */
		public $query_vars;

		public function __construct( array $vars ) {
			$this->query_vars = $vars;
		}

		public function get( $key, $default = '' ) {
			return $this->query_vars[ $key ] ?? $default;
		}
	}

	/**
	 * Order with needs_shipping() (WooCommerce >= 9.9).
	 */
	class TrustOrder_Test_Order_99 extends WC_Order {
		public function needs_shipping() {
			return (bool) ( $this->p['needs_shipping'] ?? false );
		}
	}

	class WP_REST_Request implements \ArrayAccess {
		/** @var array<string, mixed> */
		private $params;
		/** @var string */
		private $route;
		/** @var string */
		private $method;

		public function __construct( $method = 'POST', $route = '', $params = [] ) {
			$this->method = $method;
			$this->route  = $route;
			// Core's third argument is route attributes, not params; tests can opt into that.
			$this->params = empty( $GLOBALS['trustorder_test']['strict_request'] ) ? $params : [];
		}

		public function get_route() {
			return $this->route;
		}

		public function get_method() {
			return $this->method;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}

		public function get_params() {
			return $this->params;
		}

		public function set_param( $key, $value ) {
			$this->params[ $key ] = $value;
		}

		public function has_param( $key ) {
			return array_key_exists( $key, $this->params );
		}

		public function offsetExists( $offset ): bool {
			return isset( $this->params[ $offset ] );
		}

		#[\ReturnTypeWillChange]
		public function offsetGet( $offset ) {
			return $this->params[ $offset ] ?? null;
		}

		public function offsetSet( $offset, $value ): void {
			$this->params[ $offset ] = $value;
		}

		public function offsetUnset( $offset ): void {
			unset( $this->params[ $offset ] );
		}
	}

	/**
	 * WC_Payment_Gateways stand-in: runs the real filter over a fixed list.
	 */
	class TrustOrder_Test_Gateways {
		/** @var list<string> */
		public $ids;
		/** @var int */
		public $calls = 0;

		public function __construct( array $ids ) {
			$this->ids = $ids;
		}

		/** @var array<string, WC_Payment_Gateway> All gateways (admin list), id => gateway. */
		public $all = [];

		public function payment_gateways() {
			return $this->all;
		}

		public function get_available_payment_gateways() {
			++$this->calls;
			$list = [];
			foreach ( $this->ids as $id ) {
				$list[ $id ] = new WC_Payment_Gateway( $id );
			}
			return apply_filters( 'woocommerce_available_payment_gateways', $list );
		}
	}

	/**
	 * WC_Shipping stand-in for the admin method list.
	 */
	class TrustOrder_Test_Shipping {
		/** @var array<string, object> */
		public $methods = [];

		public function get_shipping_methods() {
			return $this->methods;
		}
	}

	class WP_Post {
		/** @var int */
		public $ID;

		public function __construct( $id ) {
			$this->ID = (int) $id;
		}
	}

	/**
	 * The WC() singleton stand-in.
	 */
	class TrustOrder_Test_WC {
		/** @var WC_Cart|null */
		public $cart;
		/** @var WC_Customer|null */
		public $customer;
		/** @var WC_Session_Handler|null */
		public $session;
		/** @var bool */
		public $store_api = false;
		/** @var TrustOrder_Test_Gateways|null */
		public $payment_gateways;

		/** @var TrustOrder_Test_Shipping|null */
		public $shipping_fake;

		public function is_store_api_request() {
			return $this->store_api;
		}

		public function payment_gateways() {
			return $this->payment_gateways;
		}

		public function shipping() {
			return $this->shipping_fake;
		}
	}

	/**
	 * Install a default WC(): payable cart needing shipping, a customer, a session.
	 *
	 * @param array<string, mixed> $customer Customer props.
	 */
	function trustorder_test_wc( array $customer = [], bool $with_session = true ): TrustOrder_Test_WC {
		$wc           = new TrustOrder_Test_WC();
		$wc->cart     = new WC_Cart();
		$wc->customer = new WC_Customer( $customer );
		$wc->session  = $with_session ? new WC_Session_Handler() : null;
		$wc->payment_gateways = new TrustOrder_Test_Gateways( [ 'bacs', 'cod' ] );

		$GLOBALS['trustorder_test']['wc'] = $wc;

		return $wc;
	}

	function WC() {
		return $GLOBALS['trustorder_test']['wc'];
	}

	function is_checkout() {
		return (bool) $GLOBALS['trustorder_test']['checkout'];
	}

	function is_admin() {
		return (bool) $GLOBALS['trustorder_test']['admin'];
	}

	function is_checkout_pay_page( $use_query_params = false ) {
		return 'order-pay' === $GLOBALS['trustorder_test']['endpoint'];
	}

	function is_wc_endpoint_url( $endpoint = false ) {
		return false !== $endpoint && $GLOBALS['trustorder_test']['endpoint'] === $endpoint;
	}

	function wc_clean( $var ) {
		if ( is_array( $var ) ) {
			return array_map( 'wc_clean', $var );
		}
		return is_scalar( $var ) ? sanitize_text_field( $var ) : $var;
	}

	function sanitize_email( $email ) {
		$email = trim( (string) $email );
		return preg_replace( '/[^a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~.@-]/', '', $email );
	}

	function wc_get_chosen_shipping_method_ids() {
		$wc = WC();
		if ( ! $wc || ! $wc->session ) {
			return [];
		}
		$ids = [];
		foreach ( (array) $wc->session->get( 'chosen_shipping_methods', [] ) as $rate ) {
			$ids[] = current( explode( ':', (string) $rate ) );
		}
		return $ids;
	}

	function wc_ship_to_billing_address_only() {
		return ! empty( $GLOBALS['trustorder_test']['ship_to_billing_only'] );
	}

	function wc_get_order( $id ) {
		return $GLOBALS['trustorder_test']['orders'][ $id ] ?? false;
	}

	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( $text );
	}

	function _n( $single, $plural, $number, $domain = 'default' ) {
		return 1 === (int) $number ? $single : $plural;
	}

	function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $args = null ) {
		$GLOBALS['trustorder_test']['metaboxes'][] = [ 'id' => $id, 'screen' => $screen, 'context' => $context, 'callback' => $callback ];
	}

	function get_query_var( $var, $default = '' ) {
		return $GLOBALS['trustorder_test']['query_vars'][ $var ] ?? $default;
	}

	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}

	function wc_add_notice( $message, $notice_type = 'success', $data = [] ) {
		$GLOBALS['trustorder_test']['notices'][] = [ 'type' => $notice_type, 'message' => $message ];
	}

	function wc_get_page_screen_id( $for ) {
		return 'woocommerce_page_wc-orders';
	}
}
