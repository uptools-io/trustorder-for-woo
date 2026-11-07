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
import SettingRow from '../components/SettingRow';
import ApiKeyCard from './ApiKeyCard';
import CheckoutPreview from './settings/CheckoutPreview';
import StrictnessCards from './settings/StrictnessCards';

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
 * Card-header on/off switch with a screen-reader label and a visible state.
 *
 * @param {Object}   props
 * @param {string}   props.label    Accessible label.
 * @param {boolean}  props.checked  Current value.
 * @param {Function} props.onChange Called with the new boolean.
 */
function HeaderToggle( { label, checked, onChange } ) {
	const id = useInstanceId( HeaderToggle, 'to-toggle' );

	return (
		<span className="to-switch">
			<label htmlFor={ id } className="screen-reader-text">
				{ label }
			</label>
			<FormToggle
				id={ id }
				checked={ checked }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
			<span className="to-switch__state">
				{ checked
					? __( 'On', 'trustorder-for-woo' )
					: __( 'Off', 'trustorder-for-woo' ) }
			</span>
		</span>
	);
}

/**
 * Beállítások: key, on/off, strictness, gateways, pickup methods.
 *
 * @param {Object} props
 * @param {Object} props.store useSettings() result.
 */
export default function SettingsPage( { store } ) {
	const { settings, update } = store;
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
					<HeaderToggle
						label={ __(
							'Checks at checkout',
							'trustorder-for-woo'
						) }
						checked={ !! settings.enabled }
						onChange={ ( value ) => update( { enabled: value } ) }
					/>
				}
			/>
			<Section
				title={ __( 'Strictness', 'trustorder-for-woo' ) }
				description={ __(
					'What happens to cash on delivery at each level. Trusted, new and limited-data buyers always keep it.',
					'trustorder-for-woo'
				) }
			>
				<StrictnessCards
					value={ settings.strictness }
					onChange={ ( value ) => update( { strictness: value } ) }
				/>
				<p className="to-help__note">
					<a href="#help">
						{ __(
							'What do the levels mean? See Help',
							'trustorder-for-woo'
						) }
					</a>
				</p>
			</Section>
			<Section
				title={ __( 'Payment methods', 'trustorder-for-woo' ) }
				className="to-pay"
			>
				<div className="to-pay__cols">
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
				</div>
				<CheckoutPreview
					gateways={ store.gateways }
					hidden={ hidden }
					fallback={ settings.fallback_gateways || [] }
				/>
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
			<Section
				title={ __( 'Silent mode', 'trustorder-for-woo' ) }
				description={ __(
					'Checks at checkout and the reports keep working, but the orders show nothing from TrustOrder: no column in the orders list, no box and no notes on the order page.',
					'trustorder-for-woo'
				) }
				actions={
					<HeaderToggle
						label={ __( 'Silent mode', 'trustorder-for-woo' ) }
						checked={ !! settings.silent }
						onChange={ ( value ) => update( { silent: value } ) }
					/>
				}
			/>
			<Section
				title={ __(
					'Share country and postcode',
					'trustorder-for-woo'
				) }
				description={ __(
					'Sends only the country and postcode of every new order, without any customer data, for anonymous order statistics.',
					'trustorder-for-woo'
				) }
				actions={
					<HeaderToggle
						label={ __(
							'Share country and postcode',
							'trustorder-for-woo'
						) }
						checked={ !! settings.share_geo }
						onChange={ ( value ) => update( { share_geo: value } ) }
					/>
				}
			/>
		</>
	);
}
