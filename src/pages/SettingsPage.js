/**
 * WordPress dependencies
 */
import { FormToggle } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import CheckList from '../components/CheckList';
import Section from '../components/Section';
import Segmented from '../components/Segmented';
import SettingRow from '../components/SettingRow';
import ApiKeyCard from './ApiKeyCard';

const STRICTNESS = [
	{
		value: 'lenient',
		label: __( 'Lenient', 'trustorder-for-woo' ),
		help: __(
			'Hides cash on delivery only for buyers with clearly bad history.',
			'trustorder-for-woo'
		),
	},
	{
		value: 'normal',
		label: __( 'Normal', 'trustorder-for-woo' ),
		help: __(
			'Recommended balance between lost sales and parcels not picked up.',
			'trustorder-for-woo'
		),
	},
	{
		value: 'strict',
		label: __( 'Strict', 'trustorder-for-woo' ),
		help: __(
			'Hides cash on delivery already at a few warning signs.',
			'trustorder-for-woo'
		),
	},
];

/**
 * Gateway options with a note for disabled ones.
 *
 * @param {Array} gateways { id, title, enabled }.
 * @return {Array} CheckList options.
 */
const gatewayOptions = ( gateways ) =>
	gateways.map( ( g ) => ( {
		id: g.id,
		title: g.title,
		note: g.enabled
			? undefined
			: __( 'Turned off in WooCommerce', 'trustorder-for-woo' ),
	} ) );

/**
 * Beállítások: key, on/off, strictness, gateways, pickup methods.
 *
 * @param {Object} props
 * @param {Object} props.store useSettings() result.
 */
export default function SettingsPage( { store } ) {
	const { settings, update } = store;
	const toggleId = useInstanceId( SettingsPage, 'to-enabled' );
	const strictness = STRICTNESS.find(
		( s ) => s.value === settings.strictness
	);
	// Only installed methods are listed; stored ids of absent ones stay in
	// the value (CheckList only adds or removes the ids it shows).
	const shipping = store.shippingMethods.map( ( m ) => ( {
		id: m.id,
		title: m.title,
	} ) );
	const hidden = settings.hidden_gateways || [];
	// A hidden method can't be a fallback: picking it here drops it there.
	const setHidden = ( value ) =>
		update( {
			hidden_gateways: value,
			fallback_gateways: ( settings.fallback_gateways || [] ).filter(
				( id ) => ! value.includes( id )
			),
		} );

	return (
		<>
			<ApiKeyCard store={ store } />
			<Section
				title={ __( 'Checks at checkout', 'trustorder-for-woo' ) }
				description={
					settings.enabled
						? __(
								'On: risky buyers do not see the payment methods below.',
								'trustorder-for-woo'
							)
						: __(
								'Off: no check runs, every payment method stays available. Completed and not picked up orders are still reported.',
								'trustorder-for-woo'
							)
				}
				actions={
					<span className="to-switch">
						<label
							htmlFor={ toggleId }
							className="screen-reader-text"
						>
							{ __( 'Checks at checkout', 'trustorder-for-woo' ) }
						</label>
						<FormToggle
							id={ toggleId }
							checked={ !! settings.enabled }
							onChange={ ( event ) =>
								update( { enabled: event.target.checked } )
							}
						/>
						<span className="to-switch__state">
							{ settings.enabled
								? __( 'On', 'trustorder-for-woo' )
								: __( 'Off', 'trustorder-for-woo' ) }
						</span>
					</span>
				}
			>
				<SettingRow
					title={ __( 'Strictness', 'trustorder-for-woo' ) }
					help={ strictness?.help }
				>
					<Segmented
						label={ __( 'Strictness', 'trustorder-for-woo' ) }
						options={ STRICTNESS }
						value={ settings.strictness }
						onChange={ ( value ) =>
							update( { strictness: value } )
						}
					/>
				</SettingRow>
			</Section>
			<Section title={ __( 'Payment methods', 'trustorder-for-woo' ) }>
				<SettingRow
					stacked
					title={ __(
						'Hide for risky buyers',
						'trustorder-for-woo'
					) }
					help={ __(
						'Usually cash on delivery. Only enabled payment methods are listed.',
						'trustorder-for-woo'
					) }
				>
					<CheckList
						legend={ __(
							'Hide for risky buyers',
							'trustorder-for-woo'
						) }
						options={ gatewayOptions( store.gateways ) }
						value={ hidden }
						onChange={ setHidden }
					/>
				</SettingRow>
				<SettingRow
					stacked
					title={ __(
						'Fallback payment methods',
						'trustorder-for-woo'
					) }
					help={ __(
						'At least one of these must stay visible, otherwise nothing is hidden. With none selected, any remaining method counts.',
						'trustorder-for-woo'
					) }
				>
					<CheckList
						legend={ __(
							'Fallback payment methods',
							'trustorder-for-woo'
						) }
						options={ gatewayOptions(
							store.gateways.filter(
								( g ) => ! hidden.includes( g.id )
							)
						) }
						value={ settings.fallback_gateways || [] }
						onChange={ ( value ) =>
							update( { fallback_gateways: value } )
						}
					/>
				</SettingRow>
			</Section>
			<Section
				title={ __(
					'Pickup-point and local pickup methods',
					'trustorder-for-woo'
				) }
				description={ __(
					'With these methods there is no delivery address, so no address is checked.',
					'trustorder-for-woo'
				) }
			>
				<CheckList
					legend={ __(
						'Pickup-point and local pickup methods',
						'trustorder-for-woo'
					) }
					options={ shipping }
					value={ settings.pickup_methods || [] }
					onChange={ ( value ) =>
						update( { pickup_methods: value } )
					}
				/>
			</Section>
		</>
	);
}
