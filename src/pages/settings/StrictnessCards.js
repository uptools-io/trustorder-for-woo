/**
 * WordPress dependencies
 */
import { useInstanceId } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import OrderBadge from '../OrderBadge';
import { BADGE, riskyOutcome } from '../help/badges';

const short = ( outcome ) => {
	if ( 'hide' === outcome ) {
		return __( 'Hidden', 'trustorder-for-woo' );
	}
	if ( 'flag' === outcome ) {
		return __( 'Stays, flagged', 'trustorder-for-woo' );
	}
	return __( 'Stays', 'trustorder-for-woo' );
};

/**
 * Strictness as three radio cards, each showing what happens to cash on
 * delivery for a Blocked and a Risky buyer (mirrors the service rule).
 *
 * @param {Object}   props
 * @param {string}   props.value    lenient|normal|strict.
 * @param {Function} props.onChange Called with the new value.
 */
export default function StrictnessCards( { value, onChange } ) {
	const name = useInstanceId( StrictnessCards, 'to-strictness' );
	const modes = [
		{
			value: 'lenient',
			label: __( 'Lenient', 'trustorder-for-woo' ),
			help: __(
				'Hides cash on delivery only for Blocked buyers.',
				'trustorder-for-woo'
			),
		},
		{
			value: 'normal',
			label: __( 'Normal', 'trustorder-for-woo' ),
			recommended: true,
			help: __(
				'Hidden for Blocked buyers, the Risky order gets a note.',
				'trustorder-for-woo'
			),
		},
		{
			value: 'strict',
			label: __( 'Strict', 'trustorder-for-woo' ),
			help: __(
				'Hides cash on delivery for Risky buyers too.',
				'trustorder-for-woo'
			),
		},
	];

	return (
		<fieldset className="to-modes">
			<legend className="screen-reader-text">
				{ __( 'Strictness', 'trustorder-for-woo' ) }
			</legend>
			{ modes.map( ( mode ) => {
				const risky = riskyOutcome( mode.value );
				const checked = value === mode.value;
				return (
					<label
						key={ mode.value }
						htmlFor={ `${ name }-${ mode.value }` }
						className={ `to-mode${ checked ? ' is-on' : '' }` }
					>
						<input
							id={ `${ name }-${ mode.value }` }
							type="radio"
							className="to-mode__input"
							name={ name }
							value={ mode.value }
							checked={ checked }
							onChange={ () => onChange( mode.value ) }
						/>
						<span className="to-mode__head">
							<span className="to-mode__name">
								{ mode.label }
							</span>
							{ mode.recommended && (
								<span className="to-mode__rec">
									{ __(
										'Recommended',
										'trustorder-for-woo'
									) }
								</span>
							) }
						</span>
						<span className="to-mode__help">{ mode.help }</span>
						<span className="to-mode__row">
							<OrderBadge { ...BADGE.blocked() } />
							<span className="to-help-outcome is-hide">
								{ short( 'hide' ) }
							</span>
						</span>
						<span className="to-mode__row">
							<OrderBadge { ...BADGE.risky() } />
							<span className={ `to-help-outcome is-${ risky }` }>
								{ short( risky ) }
							</span>
						</span>
					</label>
				);
			} ) }
		</fieldset>
	);
}
