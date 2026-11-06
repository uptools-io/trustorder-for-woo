/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Badge kinds as the orders list draws them (includes/Order/Labels.php).
 * Each returns the props of <OrderBadge>.
 */
export const BADGE = {
	newCustomer: () => ( {
		kind: 'new',
		icon: 'admin-users',
		label: __( 'New customer', 'trustorder-for-woo' ),
	} ),
	limited: ( score ) => ( {
		kind: 'unknown',
		icon: 'marker',
		label: __( 'Limited data', 'trustorder-for-woo' ),
		score,
	} ),
	trusted: ( score ) => ( {
		kind: 'trusted',
		icon: 'yes-alt',
		label: __( 'Trusted', 'trustorder-for-woo' ),
		score,
	} ),
	risky: ( score ) => ( {
		kind: 'risky',
		icon: 'flag',
		label: __( 'Risky', 'trustorder-for-woo' ),
		score,
	} ),
	blocked: ( score ) => ( {
		kind: 'blocked',
		icon: 'dismiss',
		label: __( 'Blocked', 'trustorder-for-woo' ),
		score,
	} ),
	notChecked: () => ( {
		kind: 'none',
		icon: 'warning',
		label: __( 'Not checked', 'trustorder-for-woo' ),
	} ),
};

/**
 * What happens to cash on delivery for a risky buyer at a strictness.
 * Mirrors the service rule (see StrictnessRules.js).
 *
 * @param {string} strictness lenient|normal|strict.
 * @return {string} keep|flag|hide.
 */
export function riskyOutcome( strictness ) {
	if ( 'strict' === strictness ) {
		return 'hide';
	}
	return 'lenient' === strictness ? 'keep' : 'flag';
}

/**
 * Outcome pill label.
 *
 * @param {string} outcome keep|flag|hide|all.
 * @return {string} Label.
 */
export function outcomeLabel( outcome ) {
	switch ( outcome ) {
		case 'hide':
			return __( 'Cash on delivery hidden', 'trustorder-for-woo' );
		case 'flag':
			return __( 'Stays, order flagged', 'trustorder-for-woo' );
		case 'all':
			return __( 'Every method stays', 'trustorder-for-woo' );
		default:
			return __( 'Cash on delivery stays', 'trustorder-for-woo' );
	}
}
