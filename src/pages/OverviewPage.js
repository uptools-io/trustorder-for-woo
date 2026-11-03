/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../components/Section';
import StateList from '../components/StateList';
import { SkeletonRows, SkeletonSection } from '../components/skeleton';
import { fetchState } from '../data/api';
import { errorText } from '../data/errors';
import { formatDate } from '../data/format';
import { cached, remember } from '../data/pageCache';
import useRemote from '../data/useRemote';
import ConnectionCard from './ConnectionCard';

/**
 * Label for a true / false / null (unknown) flag.
 *
 * @param {boolean|null} flag Flag.
 * @param {string}       yes  Text when true.
 * @param {string}       no   Text when false.
 * @return {string} Label.
 */
const pick = ( flag, yes, no ) => {
	if ( flag === null || flag === undefined ) {
		return __( 'Unknown', 'trustorder-for-woo' );
	}
	return flag ? yes : no;
};

/**
 * Status rows from GET /connection.
 *
 * @param {Object} s State.
 * @return {Array} StateList items.
 */
function stateItems( s ) {
	const yesNo = ( ok, yes, no ) => ( ok ? yes : no );
	return [
		{
			id: 'enabled',
			label: __( 'Checks at checkout', 'trustorder-for-woo' ),
			detail: yesNo(
				s.enabled,
				__(
					'Risky buyers do not see the hidden payment methods.',
					'trustorder-for-woo'
				),
				__(
					'Turned off in Settings: every payment method stays available.',
					'trustorder-for-woo'
				)
			),
			status: s.enabled ? 'ok' : 'idle',
			badge: yesNo(
				s.enabled,
				__( 'On', 'trustorder-for-woo' ),
				__( 'Off', 'trustorder-for-woo' )
			),
		},
		{
			id: 'intl',
			label: __( 'Accented characters (PHP intl)', 'trustorder-for-woo' ),
			detail: yesNo(
				s.intl,
				__(
					'E-mails and addresses with any characters are checked.',
					'trustorder-for-woo'
				),
				__(
					'intl or mbstring is missing: e-mails and addresses with accented characters are not checked. Ask your host to enable them.',
					'trustorder-for-woo'
				)
			),
			status: s.intl ? 'ok' : 'warning',
			badge: yesNo(
				s.intl,
				__( 'Available', 'trustorder-for-woo' ),
				__( 'Missing', 'trustorder-for-woo' )
			),
		},
		{
			id: 'hpos',
			label: __( 'Order storage', 'trustorder-for-woo' ),
			detail: __(
				'TrustOrder works with both order storage modes.',
				'trustorder-for-woo'
			),
			status: 'info',
			badge: pick(
				s.hpos,
				__( 'High-performance order tables', 'trustorder-for-woo' ),
				__( 'WordPress posts', 'trustorder-for-woo' )
			),
		},
		{
			id: 'blocks',
			label: __( 'Checkout page', 'trustorder-for-woo' ),
			detail: __(
				'Both the block and the classic checkout are supported.',
				'trustorder-for-woo'
			),
			status: 'info',
			badge: pick(
				s.blocks_checkout,
				__( 'Checkout block', 'trustorder-for-woo' ),
				__( 'Classic checkout', 'trustorder-for-woo' )
			),
		},
		{
			id: 'breaker',
			label: __( 'Hourly check limit', 'trustorder-for-woo' ),
			detail: yesNo(
				s.breaker,
				__(
					'Reached in the last hour: buyers not checked yet kept every payment method.',
					'trustorder-for-woo'
				),
				__( 'Not reached in the last hour.', 'trustorder-for-woo' )
			),
			status: s.breaker ? 'warning' : 'ok',
			badge: yesNo(
				s.breaker,
				__( 'Reached', 'trustorder-for-woo' ),
				__( 'OK', 'trustorder-for-woo' )
			),
		},
		{
			id: 'submit_cap',
			label: __( 'Place-order limit per network', 'trustorder-for-woo' ),
			detail: yesNo(
				s.submit_cap,
				__(
					'Reached in the last hour: for those orders the risky payment methods stayed hidden.',
					'trustorder-for-woo'
				),
				__( 'Not reached in the last hour.', 'trustorder-for-woo' )
			),
			status: s.submit_cap ? 'warning' : 'ok',
			badge: yesNo(
				s.submit_cap,
				__( 'Reached', 'trustorder-for-woo' ),
				__( 'OK', 'trustorder-for-woo' )
			),
		},
	];
}

const fetchStateCached = () =>
	fetchState().then( ( value ) => remember( 'state', value ) );

/**
 * Áttekintés: connection test, last error, environment.
 */
export default function OverviewPage() {
	// The local state is cheap: refresh it, but paint the cached copy first.
	const state = useRemote( fetchStateCached );
	const data = state.data || cached( 'state' );

	if ( state.error && ! data ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ __(
					'Could not load the TrustOrder status.',
					'trustorder-for-woo'
				) }
			</Notice>
		);
	}
	if ( ! data ) {
		return (
			<>
				<SkeletonSection description={ false } />
				<SkeletonSection description={ false }>
					<SkeletonRows count={ 6 } />
				</SkeletonSection>
			</>
		);
	}

	const s = data;
	const last = s.last_error;

	return (
		<>
			<ConnectionCard state={ s } />
			<Section
				title={ __( 'Last error', 'trustorder-for-woo' ) }
				description={
					last &&
					sprintf(
						/* translators: %s: date and time. */
						__( 'Logged: %s', 'trustorder-for-woo' ),
						formatDate( last.at )
					)
				}
			>
				{ last ? (
					<p className="to-lasterror">
						{ errorText( last.code || last.class ) }{ ' ' }
						<code>
							{ [ last.class, last.code, last.status ]
								.filter( Boolean )
								.join( ' / ' ) }
						</code>
					</p>
				) : (
					<p>{ __( 'No error so far.', 'trustorder-for-woo' ) }</p>
				) }
			</Section>
			<Section title={ __( 'Status', 'trustorder-for-woo' ) }>
				<StateList items={ stateItems( s ) } />
			</Section>
		</>
	);
}
