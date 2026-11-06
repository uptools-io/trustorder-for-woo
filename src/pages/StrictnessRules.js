/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/*
 * What each strictness does per buyer level. Mirrors the service rule
 * (backend reputation verdictFor): blocked → block; risky → block when
 * strict, flag when normal, allow when lenient. Only "block" hides payment
 * methods (HideRule); "flag" adds an order note.
 */
const HIDE = 'hide';
const KEEP = 'keep';
const FLAG = 'flag';

const OUTCOMES = {
	lenient: [ HIDE, KEEP, KEEP ],
	normal: [ HIDE, FLAG, KEEP ],
	strict: [ HIDE, HIDE, KEEP ],
};

const outcomeLabel = ( outcome ) => {
	if ( HIDE === outcome ) {
		return __( 'Cash on delivery hidden', 'trustorder-for-woo' );
	}
	if ( FLAG === outcome ) {
		return __( 'Stays, order flagged', 'trustorder-for-woo' );
	}
	return __( 'Cash on delivery stays', 'trustorder-for-woo' );
};

/**
 * Numbered list: what happens to each buyer level at this strictness.
 *
 * @param {Object} props
 * @param {string} props.strictness lenient|normal|strict.
 */
export function StrictnessRules( { strictness } ) {
	const outcomes = OUTCOMES[ strictness ] || OUTCOMES.normal;
	const buyers = [
		__( 'Blocked buyer', 'trustorder-for-woo' ),
		__( 'Risky buyer', 'trustorder-for-woo' ),
		__( 'Trusted and new customers', 'trustorder-for-woo' ),
	];

	return (
		<ol className="to-rules">
			{ buyers.map( ( buyer, i ) => (
				<li key={ buyer } className="to-rules__item">
					<span className="to-rules__num" aria-hidden="true">
						{ i + 1 }
					</span>
					<span className="to-rules__buyer">{ buyer }</span>
					<span className={ `to-pill is-${ outcomes[ i ] }` }>
						{ outcomeLabel( outcomes[ i ] ) }
					</span>
				</li>
			) ) }
		</ol>
	);
}
