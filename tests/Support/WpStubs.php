<?php
/**
 * In-memory WordPress/WooCommerce polyfills for unit tests.
 *
 * State lives in $GLOBALS['trustorder_test'] and is reset by
 * trustorder_test_reset() (call it from setUp()).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

// phpcs:ignoreFile -- Test-only global polyfills that mirror WordPress core signatures.

/**
 * Reset all stub state.
 */
function trustorder_test_reset(): void {
	$GLOBALS['trustorder_test'] = [
		'options' => [],
		'autoload' => [],
		'filters' => [],
		'actions' => [],
		'logs'    => [],
		'http'       => [],
		'transients' => [],
		'as'         => [],
		'caps'       => [ 'manage_woocommerce' => true ],
		'screen'     => null,
		'user'       => 1,
		'usermeta'   => [],
		'wc'         => null,
		'checkout'   => false,
		'admin'      => false,
		'endpoint'   => '',
	];
}

trustorder_test_reset();

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;
		/** @var mixed */
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = $data;
		}

		public function get_error_data( $code = '' ) {
			return $this->data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message( $code = '' ) {
			if ( '' !== $code ) {
				return $this->errors[ $code ][0] ?? '';
			}
			return $this->message;
		}

		/** @var array<string, list<string>> */
		public $errors = [];

		public function add( $code, $message, $data = '' ) {
			$this->errors[ $code ][] = $message;
		}

		public function remove( $code ) {
			unset( $this->errors[ $code ] );
		}

		public function get_error_codes() {
			return array_keys( $this->errors );
		}
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['trustorder_test']['filters'][ $hook ][ $priority ][] = [ $callback, $accepted_args ];
	return true;
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['trustorder_test']['actions'][ $hook ][ $priority ][] = [ $callback, $accepted_args ];
	return true;
}

function remove_all_actions( $hook, $priority = false ) {
	unset( $GLOBALS['trustorder_test']['actions'][ $hook ] );
	return true;
}

function has_action( $hook, $callback = false ) {
	foreach ( $GLOBALS['trustorder_test']['actions'][ $hook ] ?? [] as $priority => $list ) {
		foreach ( $list as $entry ) {
			if ( $entry[0] === $callback ) {
				return $priority;
			}
		}
	}
	return false;
}

function do_action( $hook, ...$args ) {
	$all = $GLOBALS['trustorder_test']['actions'][ $hook ] ?? [];
	ksort( $all );
	foreach ( $all as $list ) {
		foreach ( $list as $entry ) {
			call_user_func_array( $entry[0], array_slice( $args, 0, (int) $entry[1] ) );
		}
	}
}

function apply_filters( $hook, $value, ...$args ) {
	$all = $GLOBALS['trustorder_test']['filters'][ $hook ] ?? [];
	ksort( $all );
	foreach ( $all as $list ) {
		foreach ( $list as $entry ) {
			$value = call_user_func_array( $entry[0], array_slice( array_merge( [ $value ], $args ), 0, (int) $entry[1] ) );
		}
	}
	return $value;
}

function get_option( $name, $default = false ) {
	if ( isset( $GLOBALS['trustorder_test']['cache']['options']['notoptions'][ $name ] ) ) {
		return $default; // WordPress trusts the cached "does not exist" list.
	}
	return array_key_exists( $name, $GLOBALS['trustorder_test']['options'] ) ? $GLOBALS['trustorder_test']['options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$old = get_option( $name );
	if ( $old === $value ) {
		return false; // WordPress no-ops when the value is unchanged.
	}
	$GLOBALS['trustorder_test']['options'][ $name ]  = $value;
	$GLOBALS['trustorder_test']['autoload'][ $name ] = $autoload;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['trustorder_test']['options'][ $name ] );
	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = null ) {
	if ( array_key_exists( $name, $GLOBALS['trustorder_test']['options'] ) ) {
		return false;
	}
	$GLOBALS['trustorder_test']['options'][ $name ]  = $value;
	$GLOBALS['trustorder_test']['autoload'][ $name ] = $autoload;
	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	$GLOBALS['trustorder_test']['cache_deletes'][] = [ $key, $group ];
	unset( $GLOBALS['trustorder_test']['cache'][ $group ][ $key ] );
	return true;
}

function as_unschedule_action( $hook, $args = [], $group = '' ) {
	foreach ( $GLOBALS['trustorder_test']['as'] as $i => $a ) {
		if ( 'pending' === $a['status'] && $a['hook'] === $hook && $a['args'] === $args && $a['group'] === $group ) {
			$GLOBALS['trustorder_test']['as'][ $i ]['status'] = 'canceled';
			return $i + 1;
		}
	}
	return null;
}

function as_unschedule_all_actions( $hook, $args = [], $group = '' ) {
	foreach ( $GLOBALS['trustorder_test']['as'] as $i => $a ) {
		// Like Action Scheduler: an empty hook with a group cancels the whole group.
		$hook_ok = '' === $hook ? '' !== $group : $a['hook'] === $hook;
		if ( 'pending' === $a['status'] && $hook_ok && ( '' === $hook && [] === $args || $a['args'] === $args ) && $a['group'] === $group ) {
			$GLOBALS['trustorder_test']['as'][ $i ]['status'] = 'canceled';
		}
	}
}

function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['trustorder_test']['deactivation'][ basename( (string) $file ) ] = $callback;
}

/**
 * Minimal ActionScheduler_Action stand-in (get_schedule()->get_date()).
 */
final class TrustOrder_Test_AS_Action {
	/** @var int */
	private $time;

	public function __construct( int $time ) {
		$this->time = $time;
	}

	public function get_schedule() {
		$t = $this->time;
		return new class( $t ) {
			/** @var int */
			private $t;

			public function __construct( int $t ) {
				$this->t = $t;
			}

			public function get_date() {
				return new \DateTimeImmutable( '@' . $this->t );
			}
		};
	}
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function wp_remote_request( $url, $args = [] ) {
	$GLOBALS['trustorder_test']['http'][] = [ 'url' => $url, 'args' => $args ];
	$pre = apply_filters( 'pre_http_request', false, $args, $url );
	if ( false !== $pre ) {
		return $pre;
	}
	return new WP_Error( 'http_request_failed', 'No network in unit tests.' );
}

function wp_remote_retrieve_response_code( $response ) {
	if ( is_wp_error( $response ) || ! isset( $response['response']['code'] ) ) {
		return '';
	}
	return (int) $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	if ( is_wp_error( $response ) || ! isset( $response['body'] ) ) {
		return '';
	}
	return (string) $response['body'];
}

/**
 * Captures log lines like WC_Logger would.
 */
class TrustOrder_Test_Logger {
	public function log( $level, $message, $context = [] ) {
		$GLOBALS['trustorder_test']['logs'][] = [ 'level' => $level, 'message' => $message, 'context' => $context ];
	}

	public function error( $message, $context = [] ) {
		$this->log( 'error', $message, $context );
	}
}

function wc_get_logger() {
	return new TrustOrder_Test_Logger();
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

function get_transient( $name ) {
	return $GLOBALS['trustorder_test']['transients'][ $name ]['value'] ?? false;
}

function set_transient( $name, $value, $expiration = 0 ) {
	if ( ! empty( $GLOBALS['trustorder_test']['transient_fail'] ) && str_starts_with( (string) $name, 'trustorder_q_' ) ) {
		return false; // The fallback store is down too.
	}
	if ( ! empty( $GLOBALS['trustorder_test']['transient_same_write'] ) ) {
		// A concurrent request already stored this value: WP reports the unchanged write as false.
		$GLOBALS['trustorder_test']['transients'][ $name ] = [ 'value' => $value, 'ttl' => (int) $expiration ];
		return false;
	}
	$GLOBALS['trustorder_test']['transients'][ $name ] = [ 'value' => $value, 'ttl' => (int) $expiration ];
	return true;
}

function delete_transient( $name ) {
	unset( $GLOBALS['trustorder_test']['transients'][ $name ] );
	return true;
}

function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
	foreach ( $GLOBALS['trustorder_test']['as'] as $a ) {
		$live = in_array( $a['status'] ?? 'pending', [ 'pending', 'in-progress' ], true );
		if ( $live && $a['hook'] === $hook && ( null === $args || $a['args'] === $args ) && ( '' === $group || $a['group'] === $group ) ) {
			return true;
		}
	}
	return false;
}

function as_schedule_single_action( $timestamp, $hook, $args = [], $group = '', $unique = false, $priority = 10 ) {
	$GLOBALS['trustorder_test']['as'][] = [ 'time' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group, 'unique' => $unique, 'status' => 'pending', 'options_at' => $GLOBALS['trustorder_test']['options'] ];
	return count( $GLOBALS['trustorder_test']['as'] );
}

function as_get_scheduled_actions( $args = [], $return_format = 'OBJECT' ) {
	$ids = [];
	foreach ( $GLOBALS['trustorder_test']['as'] as $i => $a ) {
		if ( isset( $args['hook'] ) && $a['hook'] !== $args['hook'] ) {
			continue;
		}
		if ( isset( $args['args'] ) && $a['args'] !== $args['args'] ) {
			continue;
		}
		if ( isset( $args['group'] ) && $a['group'] !== $args['group'] ) {
			continue;
		}
		if ( isset( $args['status'] ) && ( $a['status'] ?? 'pending' ) !== $args['status'] ) {
			continue;
		}
		$ids[ $i + 1 ] = 'ids' === $return_format ? $i + 1 : new TrustOrder_Test_AS_Action( (int) $a['time'] );
	}
	return 'ids' === $return_format ? array_values( $ids ) : $ids;
}

function _x( $text, $context, $domain = 'default' ) {
	return $text;
}

function _n_noop( $singular, $plural, $domain = null ) {
	return [ 'singular' => $singular, 'plural' => $plural, 'domain' => $domain ];
}

function current_user_can( $cap, ...$args ) {
	return ! empty( $GLOBALS['trustorder_test']['caps'][ $cap ] );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

if ( ! class_exists( 'WP_Screen' ) ) {
	/**
	 * Minimal WP_Screen.
	 */
	class WP_Screen {
		/** @var string */
		public $id = '';
	}
}

function get_current_screen() {
	return $GLOBALS['trustorder_test']['screen'];
}

function wc_get_screen_ids() {
	return [ 'woocommerce_page_wc-settings', 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' ];
}

function get_current_user_id() {
	return (int) $GLOBALS['trustorder_test']['user'];
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	return $GLOBALS['trustorder_test']['usermeta'][ $user_id ][ $key ] ?? ( $single ? '' : [] );
}

function delete_metadata( $meta_type, $object_id, $meta_key, $meta_value = '', $delete_all = false ) {
	$GLOBALS['trustorder_test']['deleted_meta'][] = [ $meta_type, $object_id, $meta_key, $delete_all ];
	if ( 'user' === $meta_type && $delete_all ) {
		foreach ( $GLOBALS['trustorder_test']['usermeta'] as $uid => $meta ) {
			unset( $GLOBALS['trustorder_test']['usermeta'][ $uid ][ $meta_key ] );
		}
	}
	return true;
}

function update_user_meta( $user_id, $key, $value, $prev = '' ) {
	$GLOBALS['trustorder_test']['usermeta'][ $user_id ][ $key ] = $value;
	return true;
}

function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . $action;
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return 'nonce-' . $action === $nonce ? 1 : false;
}

function wp_nonce_url( $url, $action = -1, $name = '_wpnonce' ) {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $name . '=' . wp_create_nonce( $action );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function sanitize_text_field( $str ) {
	return trim( strip_tags( (string) $str ) );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function wp_using_ext_object_cache( $using = null ) {
	return ! empty( $GLOBALS['trustorder_test']['ext_cache'] );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	$GLOBALS['trustorder_test']['cache'][ $group ][ $key ] = $data;
	return true;
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	$found = isset( $GLOBALS['trustorder_test']['cache'][ $group ][ $key ] );
	return $found ? $GLOBALS['trustorder_test']['cache'][ $group ][ $key ] : false;
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	$GLOBALS['trustorder_test']['cache_ops'][] = [ 'add', $key, $expire ];
	if ( isset( $GLOBALS['trustorder_test']['cache'][ $group ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['trustorder_test']['cache'][ $group ][ $key ] = $data;
	return true;
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	$GLOBALS['trustorder_test']['cache_ops'][] = [ 'incr', $key, 0 ];
	if ( ! empty( $GLOBALS['trustorder_test']['cache_incr_fail'] ) ) {
		return false; // A Redis drop-in that swallowed an exception.
	}
	if ( ! isset( $GLOBALS['trustorder_test']['cache'][ $group ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['trustorder_test']['cache'][ $group ][ $key ] += $offset;
	return $GLOBALS['trustorder_test']['cache'][ $group ][ $key ];
}

/**
 * Test helper: drop every callback of a filter.
 */
function remove_all_filters_for_test( $hook ) {
	unset( $GLOBALS['trustorder_test']['filters'][ $hook ] );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	$GLOBALS['trustorder_test']['cache_ops'][] = [ 'decr', $key, 0 ];
	if ( ! isset( $GLOBALS['trustorder_test']['cache'][ $group ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['trustorder_test']['cache'][ $group ][ $key ] = max( 0, $GLOBALS['trustorder_test']['cache'][ $group ][ $key ] - $offset );
	return $GLOBALS['trustorder_test']['cache'][ $group ][ $key ];
}

function has_filter( $hook, $callback = false ) {
	return ! empty( $GLOBALS['trustorder_test']['filters'][ $hook ] );
}
