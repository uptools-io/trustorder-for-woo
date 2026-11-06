/**
 * WordPress dependencies
 */
import { __, _x, sprintf } from '@wordpress/i18n';
import { Icon, lock } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import Section from '../components/Section';
import BadgeGlossary from './help/BadgeGlossary';
import OrderListMock from './help/OrderListMock';
import Recovery from './help/Recovery';
import ScoreScale from './help/ScoreScale';
import { StrictnessRules } from './StrictnessRules';

/**
 * Reason labels shown on the order page; they match Labels::reason().
 */
function reasons() {
	return [
		[
			__( 'First-time customer', 'trustorder-for-woo' ),
			__(
				'No order data on this buyer in the network.',
				'trustorder-for-woo'
			),
		],
		[
			__( 'Recently refused an order', 'trustorder-for-woo' ),
			__(
				'A shop reported a parcel not picked up in the last 12 months.',
				'trustorder-for-woo'
			),
		],
		[
			__( 'Refused orders at several shops', 'trustorder-for-woo' ),
			__(
				'More than one shop reported parcels not picked up.',
				'trustorder-for-woo'
			),
		],
		[
			__( 'Low score', 'trustorder-for-woo' ),
			__( 'The score is below 60.', 'trustorder-for-woo' ),
		],
		[
			__( 'Disposable e-mail address', 'trustorder-for-woo' ),
			__(
				'The address belongs to a throwaway e-mail service.',
				'trustorder-for-woo'
			),
		],
		[
			__( 'E-mail domain cannot receive mail', 'trustorder-for-woo' ),
			__(
				'The e-mail domain has no mail server, so the address is probably mistyped or made up.',
				'trustorder-for-woo'
			),
		],
		[
			__( 'Allowed by your exception list', 'trustorder-for-woo' ),
			__(
				'You allowed this buyer on the Exceptions page, so cash on delivery stays.',
				'trustorder-for-woo'
			),
		],
		[
			__( 'Denied by your exception list', 'trustorder-for-woo' ),
			__(
				'You denied this buyer on the Exceptions page, so cash on delivery is hidden.',
				'trustorder-for-woo'
			),
		],
	];
}

function steps() {
	return [
		[
			__( 'At checkout', 'trustorder-for-woo' ),
			__(
				'TrustOrder looks up the buyer’s e-mail address, phone number and address in the shared network of shops.',
				'trustorder-for-woo'
			),
		],
		[
			__( 'Decision', 'trustorder-for-woo' ),
			__(
				'Based on the level and your strictness, cash on delivery stays, is hidden, or stays and the order gets a note.',
				'trustorder-for-woo'
			),
		],
		[
			__( 'On the order', 'trustorder-for-woo' ),
			__(
				'You see the result in the orders list and in the TrustOrder box on the order page.',
				'trustorder-for-woo'
			),
		],
		[
			__( 'After delivery', 'trustorder-for-woo' ),
			__(
				'The status you set is feedback to the network: every buyer’s reputation is built from it.',
				'trustorder-for-woo'
			),
		],
	];
}

const strictnessName = ( value ) => {
	if ( 'strict' === value ) {
		return __( 'Strict', 'trustorder-for-woo' );
	}
	if ( 'lenient' === value ) {
		return __( 'Lenient', 'trustorder-for-woo' );
	}
	return __( 'Normal', 'trustorder-for-woo' );
};

/**
 * Help: what the badges mean and what to do with the orders.
 *
 * @param {Object} props
 * @param {Object} props.store useSettings() result.
 */
export default function HelpPage( { store } ) {
	const strictness = store?.settings?.strictness || 'normal';

	return (
		<div className="to-help">
			<p className="to-help__lead">
				{ __(
					'What the badges on the orders mean, and what you need to do so that TrustOrder works well.',
					'trustorder-for-woo'
				) }
			</p>

			<OrderListMock />
			<BadgeGlossary strictness={ strictness } />
			<ScoreScale />
			<Recovery />

			<Section title={ __( 'How it works', 'trustorder-for-woo' ) }>
				<ol className="to-help-steps">
					{ steps().map( ( [ title, text ], i ) => (
						<li key={ title }>
							<span
								className="to-help-steps__num"
								aria-hidden="true"
							>
								{ i + 1 }
							</span>
							<strong>{ title }</strong>
							<span className="to-help__muted">{ text }</span>
						</li>
					) ) }
				</ol>
			</Section>

			<Section
				title={ __( 'What you need to do', 'trustorder-for-woo' ) }
				description={ __(
					'The report goes out from the order status. If the status is right, the network is right.',
					'trustorder-for-woo'
				) }
			>
				<div className="to-help-todo">
					<div>
						<span>
							{ __(
								'The buyer picked up the parcel.',
								'trustorder-for-woo'
							) }
						</span>
						<span className="to-help-outcome is-keep">
							{ __( 'Completed', 'trustorder-for-woo' ) }
						</span>
					</div>
					<div>
						<span>
							{ __(
								'The parcel came back. For several orders at once, use the bulk action in the orders list.',
								'trustorder-for-woo'
							) }
						</span>
						<span className="to-help-outcome is-hide">
							{ _x(
								'Not picked up',
								'Order status',
								'trustorder-for-woo'
							) }
						</span>
					</div>
					<div>
						<span>
							{ __(
								'Made a mistake? Set it back, and TrustOrder withdraws its report. A refund after Completed withdraws it too.',
								'trustorder-for-woo'
							) }
						</span>
						<span className="to-help-outcome is-all">
							{ __( 'Processing', 'trustorder-for-woo' ) }
						</span>
					</div>
					<div>
						<span>
							{ __(
								'You know a buyer personally: allow or deny them.',
								'trustorder-for-woo'
							) }
						</span>
						<a
							className="to-help-outcome is-all"
							href="#exceptions"
						>
							{ __( 'Exceptions', 'trustorder-for-woo' ) }
						</a>
					</div>
				</div>
			</Section>

			<Section
				title={ sprintf(
					/* translators: %s: strictness name (Lenient, Normal, Strict). */
					__( 'Your strictness: %s', 'trustorder-for-woo' ),
					strictnessName( strictness )
				) }
				description={ __(
					'You can change it under Settings.',
					'trustorder-for-woo'
				) }
			>
				<StrictnessRules strictness={ strictness } />
			</Section>

			<Section
				title={ __(
					'Reasons on the order page',
					'trustorder-for-woo'
				) }
				description={ __(
					'The TrustOrder box on the order page lists why the buyer got their level.',
					'trustorder-for-woo'
				) }
			>
				<dl className="to-help-reasons">
					{ reasons().map( ( [ name, text ] ) => (
						<div key={ name }>
							<dt>{ name }</dt>
							<dd>{ text }</dd>
						</div>
					) ) }
				</dl>
			</Section>

			<section className="to-help-privacy">
				<Icon icon={ lock } size={ 28 } />
				<div>
					<h3>{ __( 'Privacy', 'trustorder-for-woo' ) }</h3>
					<p>
						{ __(
							'Names, e-mail addresses, phone numbers and addresses never leave your shop. TrustOrder sends only one-way codes made from them, which cannot be turned back into data. If “Share country and postcode” is on in Settings, the country and postcode of new orders are sent too, without any customer data.',
							'trustorder-for-woo'
						) }
					</p>
				</div>
			</section>
		</div>
	);
}
