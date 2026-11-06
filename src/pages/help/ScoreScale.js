/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../../components/Section';

/**
 * The 0–100 score with the 60 threshold and the 75 starting point.
 * Thresholds mirror the service (backend reputation engine).
 */
export default function ScoreScale() {
	return (
		<Section
			title={ __( 'The score', 'trustorder-for-woo' ) }
			description={ __(
				'From 0 to 100: the higher, the more reliable. Picked up parcels raise it, and older cases count less and less over time. A new customer starts at 75.',
				'trustorder-for-woo'
			) }
		>
			<div className="to-help-scale" aria-hidden="true">
				<div className="to-help-scale__bar">
					<span className="is-low" />
					<span className="is-high" />
				</div>
				<span className="to-help-scale__threshold">60</span>
				<span className="to-help-scale__start">
					{ __( '75 · start', 'trustorder-for-woo' ) }
				</span>
				<span className="to-help-scale__min">0</span>
				<span className="to-help-scale__max">100</span>
			</div>
			<div className="to-help-scale__text">
				<p>
					{ __(
						'Below 60 the buyer is risky; if several shops reported parcels not picked up, blocked.',
						'trustorder-for-woo'
					) }
				</p>
				<p>
					{ __(
						'Above 60, with no refusal in the last 12 months, the number of picked up parcels decides: 1 or 2 is limited data, from 3 trusted.',
						'trustorder-for-woo'
					) }
				</p>
			</div>
		</Section>
	);
}
