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

/**
 * What the levels mean, with the service's thresholds.
 */
export function LevelGuide() {
	const levels = [
		{
			kind: HIDE,
			name: __( 'Blocked', 'trustorder-for-woo' ),
			text: __(
				'In the last 12 months at least 2 established shops (or 4 new shops) reported that the buyer did not pick up the parcel, and the score is below 60.',
				'trustorder-for-woo'
			),
		},
		{
			kind: FLAG,
			name: __( 'Risky', 'trustorder-for-woo' ),
			text: __(
				'In the last 12 months the buyer did not pick up at least 1 parcel, or the score is below 60, or the e-mail address is disposable or cannot receive mail.',
				'trustorder-for-woo'
			),
		},
		{
			kind: KEEP,
			name: __( 'Trusted', 'trustorder-for-woo' ),
			text: __(
				'At least 3 picked up cash-on-delivery parcels, with no recent refusal.',
				'trustorder-for-woo'
			),
		},
		{
			kind: KEEP,
			name: __( 'New customer', 'trustorder-for-woo' ),
			text: __(
				'No history yet: starts at 75 points, cash on delivery always stays.',
				'trustorder-for-woo'
			),
		},
	];

	return (
		<div className="to-levels">
			<dl className="to-levels__list">
				{ levels.map( ( l ) => (
					<div key={ l.name } className="to-levels__row">
						<dt>
							<span className={ `to-pill is-${ l.kind }` }>
								{ l.name }
							</span>
						</dt>
						<dd>{ l.text }</dd>
					</div>
				) ) }
			</dl>
			<p className="to-levels__note">
				{ __(
					'Score 0–100. One parcel not picked up at one new shop gives about 67 points (Risky). Picked up parcels raise it, and older cases count less and less over time.',
					'trustorder-for-woo'
				) }
			</p>
		</div>
	);
}
