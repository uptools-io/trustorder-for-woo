/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Human text for a TrustOrder API / plugin error code (the codes are logged
 * without any personal data, so they are all the admin gets).
 *
 * @param {string|null} code Error code.
 * @return {string} Explanation.
 */
export function errorText( code ) {
	switch ( code ) {
		case 'UNAUTHORIZED':
			return __(
				'The API key was not accepted. Check that you copied the whole key.',
				'trustorder-for-woo'
			);
		case 'FORBIDDEN':
			return __(
				'The account or shop behind this key is suspended or disabled. Contact upTools.',
				'trustorder-for-woo'
			);
		case 'RATE_LIMITED':
			return __(
				'Too many requests in a short time. TrustOrder retries by itself.',
				'trustorder-for-woo'
			);
		case 'QUOTA_EXCEEDED':
			return __(
				'The daily check quota is used up. Until tomorrow every payment method stays available.',
				'trustorder-for-woo'
			);
		case 'TIMEOUT':
			return __(
				'The service did not answer in time. The order went through with every payment method.',
				'trustorder-for-woo'
			);
		case 'NETWORK':
			return __(
				'The service could not be reached from this server (network or DNS error).',
				'trustorder-for-woo'
			);
		case 'BAD_JSON':
		case 'BAD_RESPONSE':
			return __(
				'The service sent an answer the plugin could not read.',
				'trustorder-for-woo'
			);
		case 'INTERNAL':
			return __(
				'Temporary error on the service side.',
				'trustorder-for-woo'
			);
		case 'INVALID_REQUEST':
		case 'PAYLOAD_TOO_LARGE':
			return __(
				'The service refused a request as invalid. Update the plugin if this repeats.',
				'trustorder-for-woo'
			);
		case 'NO_KEY':
			return __( 'No API key is saved.', 'trustorder-for-woo' );
		case 'BAD_API_BASE':
			return __(
				'The trustorder_api_base filter returned an address that is not allowed; the default address was used.',
				'trustorder-for-woo'
			);
		case 'HIDE_WOULD_EMPTY':
			return __(
				'A risky buyer would have had no payment method left, so nothing was hidden. Set a fallback payment method.',
				'trustorder-for-woo'
			);
		case 'COUNTER_UNAVAILABLE':
			return __(
				'The check counter was unavailable (object cache error); checks ran without the local limits.',
				'trustorder-for-woo'
			);
		case 'DNS_UNAVAILABLE':
			return __(
				'The mail server lookup of an e-mail domain failed on this server.',
				'trustorder-for-woo'
			);
		case 'LOCAL_QUOTA':
		case 'IP_QUOTA':
			return __(
				'A local check limit was reached (per session or per visitor network); those buyers were not checked.',
				'trustorder-for-woo'
			);
		case 'NOT_FOUND':
			return __(
				'The service did not find the requested item.',
				'trustorder-for-woo'
			);
		case 'NO_ROUTE_EXCEPTION':
			return __(
				'The block checkout could not refuse an order placed with a hidden payment method (WooCommerce too old); the order went through.',
				'trustorder-for-woo'
			);
		case 'ERROR':
		case 'EXCEPTION':
			return __(
				'An unexpected error happened inside the plugin; the checkout was not affected.',
				'trustorder-for-woo'
			);
		default:
			return httpText( code );
	}
}

/**
 * Text for an `HTTP_<status>` code (an API answer without a known code).
 *
 * @param {string|null} code Error code.
 * @return {string} Explanation.
 */
function httpText( code ) {
	const match = /^HTTP_(\d{3})$/.exec( code || '' );
	if ( match && Number( match[ 1 ] ) >= 500 ) {
		return __(
			'Temporary error on the service side.',
			'trustorder-for-woo'
		);
	}
	if ( match ) {
		return sprintf(
			/* translators: %s: HTTP status code. */
			__(
				'The service refused the request (HTTP %s).',
				'trustorder-for-woo'
			),
			match[ 1 ]
		);
	}
	return __( 'Unknown error.', 'trustorder-for-woo' );
}

/**
 * Message for a failed apiFetch call (WP_Error from our routes).
 *
 * @param {Object} error apiFetch rejection.
 * @return {string} Message.
 */
export function requestError( error ) {
	if ( error?.code === 'trustorder_api_error' ) {
		return errorText( error?.data?.api_code );
	}
	return (
		error?.message ||
		__( 'The request failed. Try again.', 'trustorder-for-woo' )
	);
}
