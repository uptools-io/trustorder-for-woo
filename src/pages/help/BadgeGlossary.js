/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../../components/Section';
import OrderBadge from '../OrderBadge';
import { BADGE, outcomeLabel, riskyOutcome } from './badges';

/**
 * Every badge with its meaning and what happened to cash on delivery at
 * this shop's strictness.
 *
 * @param {Object} props
 * @param {string} props.strictness lenient|normal|strict.
 */
export default function BadgeGlossary( { strictness } ) {
	const items = [
		{
			badge: BADGE.newCustomer(),
			outcome: 'keep',
			text: __(
				'No shop in the network has data on this buyer yet.',
				'trustorder-for-woo'
			),
		},
		{
			badge: BADGE.limited( 80 ),
			outcome: 'keep',
			text: __(
				'Picked up 1 or 2 parcels and has not refused one recently, but that is not yet enough to be trusted.',
				'trustorder-for-woo'
			),
		},
		{
			badge: BADGE.trusted( 92 ),
			outcome: 'keep',
			text: __(
				'At least 3 picked up cash-on-delivery parcels, with no recent refusal.',
				'trustorder-for-woo'
			),
		},
		{
			badge: BADGE.risky( 55 ),
			outcome: riskyOutcome( strictness ),
			text: __(
				'In the last 12 months the buyer did not pick up at least 1 parcel, or the score is below 60, or the e-mail address is disposable or cannot receive mail.',
				'trustorder-for-woo'
			),
		},
		{
			badge: BADGE.blocked( 30 ),
			outcome: 'hide',
			text: __(
				'Several shops reported parcels this buyer did not pick up in the last 12 months, and the score is below 60.',
				'trustorder-for-woo'
			),
		},
		{
			badge: BADGE.notChecked(),
			outcome: 'all',
			text: __(
				'The service could not be reached at checkout, or the daily check limit ran out. Look at this order yourself.',
				'trustorder-for-woo'
			),
		},
	];

	return (
		<Section
			title={ __( 'What the badges mean', 'trustorder-for-woo' ) }
			description={ __(
				'Next to each badge: what happened to cash on delivery at checkout, with your current strictness.',
				'trustorder-for-woo'
			) }
		>
			<div className="to-help-glossary">
				{ items.map( ( item ) => (
					<div
						key={ item.badge.label }
						className="to-help-glossary__item"
					>
						<div className="to-help-glossary__head">
							<OrderBadge { ...item.badge } />
							<span
								className={ `to-help-outcome is-${ item.outcome }` }
							>
								{ outcomeLabel( item.outcome ) }
							</span>
						</div>
						<p>{ item.text }</p>
					</div>
				) ) }
			</div>
			<p className="to-help__note">
				{ __(
					'A dash means no check ran for the order: it was placed before TrustOrder was set up, or it was created outside the checkout. A badge without a number was imported from an earlier tool.',
					'trustorder-for-woo'
				) }
			</p>
		</Section>
	);
}
