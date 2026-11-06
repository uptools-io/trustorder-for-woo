/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../../components/Section';
import OrderBadge from '../OrderBadge';
import { BADGE } from './badges';

/**
 * How a buyer's reputation recovers. Mirrors the service: a refusal counts
 * as recent for 12 months, every case's weight halves yearly, and cases are
 * deleted after 3 years.
 */
export default function Recovery() {
	const steps = [
		{
			tone: 'low',
			when: __( 'Now', 'trustorder-for-woo' ),
			badge: BADGE.risky( 55 ),
			text: __( 'Did not pick up a parcel.', 'trustorder-for-woo' ),
		},
		{
			tone: 'mid',
			when: __( 'Picked up parcels', 'trustorder-for-woo' ),
			big: __( '↑ score', 'trustorder-for-woo' ),
			text: __(
				'Every picked up cash-on-delivery parcel raises the score, at any shop.',
				'trustorder-for-woo'
			),
		},
		{
			tone: 'up',
			when: __( 'After 12 months', 'trustorder-for-woo' ),
			badge: BADGE.trusted( 78 ),
			text: __(
				'With no new refusal and a score above 60, no longer risky. From 3 picked up parcels, trusted.',
				'trustorder-for-woo'
			),
		},
		{
			tone: 'high',
			when: __( 'After 3 years', 'trustorder-for-woo' ),
			big: __( 'deleted', 'trustorder-for-woo' ),
			text: __(
				'The old case is removed from the service completely.',
				'trustorder-for-woo'
			),
		},
	];

	return (
		<Section
			title={ __( 'Reputation can improve', 'trustorder-for-woo' ) }
			description={ __(
				'A parcel not picked up is not a mark forever. If the buyer picks up their parcels after that, their level recovers step by step.',
				'trustorder-for-woo'
			) }
		>
			<ol className="to-help-recovery">
				{ steps.map( ( step ) => (
					<li key={ step.when } className={ `is-${ step.tone }` }>
						<span className="to-help-recovery__when">
							{ step.when }
						</span>
						{ step.badge ? (
							<OrderBadge { ...step.badge } />
						) : (
							<span className="to-help-recovery__big">
								{ step.big }
							</span>
						) }
						<span>{ step.text }</span>
					</li>
				) ) }
			</ol>
			<p className="to-help__note">
				{ __(
					'The weight of old cases halves every year, so recent behaviour always counts more. It works the other way too: a trusted buyer can fall back if they stop picking up their parcels.',
					'trustorder-for-woo'
				) }
			</p>
		</Section>
	);
}
