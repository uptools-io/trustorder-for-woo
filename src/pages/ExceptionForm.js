/**
 * WordPress dependencies
 */
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import Section from '../components/Section';
import Segmented from '../components/Segmented';
import SettingRow from '../components/SettingRow';
import { addException, removeException } from '../data/api';
import { COUNTRIES, SHOP_COUNTRY } from '../data/boot';
import { requestError } from '../data/errors';

export const KINDS = [
	{ value: 'email', label: __( 'E-mail', 'trustorder-for-woo' ) },
	{ value: 'phone', label: __( 'Phone', 'trustorder-for-woo' ) },
	{ value: 'address', label: __( 'Address', 'trustorder-for-woo' ) },
];

export const ACTIONS = [
	{ value: 'allow', label: __( 'Always allow', 'trustorder-for-woo' ) },
	{ value: 'deny', label: __( 'Always deny', 'trustorder-for-woo' ) },
];

const EMPTY = {
	value: '',
	country: SHOP_COUNTRY,
	postcode: '',
	address_1: '',
	address_2: '',
	note: '',
};

const countryOptions = Object.entries( COUNTRIES ).map(
	( [ value, label ] ) => ( {
		value,
		label,
	} )
);

/**
 * Identity fields of one kind.
 *
 * @param {Object}   props
 * @param {string}   props.kind email|phone|address.
 * @param {Object}   props.form Field values.
 * @param {Function} props.set  Receives a field patch.
 */
function IdentityFields( { kind, form, set } ) {
	const country = countryOptions.length > 0 && (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Country', 'trustorder-for-woo' ) }
			value={ form.country }
			options={ countryOptions }
			onChange={ ( value ) => set( { country: value } ) }
		/>
	);
	if ( kind === 'address' ) {
		return (
			<div className="to-fields">
				{ country }
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Postcode', 'trustorder-for-woo' ) }
					value={ form.postcode }
					onChange={ ( value ) => set( { postcode: value } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Street address', 'trustorder-for-woo' ) }
					value={ form.address_1 }
					onChange={ ( value ) => set( { address_1: value } ) }
				/>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'Apartment, floor (optional)',
						'trustorder-for-woo'
					) }
					value={ form.address_2 }
					onChange={ ( value ) => set( { address_2: value } ) }
				/>
			</div>
		);
	}
	return (
		<div className="to-fields">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={
					kind === 'email'
						? __( 'E-mail address', 'trustorder-for-woo' )
						: __( 'Phone number', 'trustorder-for-woo' )
				}
				type={ kind === 'email' ? 'email' : 'tel' }
				autoComplete="off"
				value={ form.value }
				onChange={ ( value ) => set( { value } ) }
			/>
			{ kind === 'phone' && country }
		</div>
	);
}

/**
 * Add or remove one exception. The value goes to the plugin, which hashes it
 * and sends only the hash; nothing is stored in the shop.
 *
 * @param {Object}   props
 * @param {Function} props.onChanged Called after a successful change.
 */
export default function ExceptionForm( { onChanged } ) {
	const [ kind, setKind ] = useState( 'email' );
	const [ action, setAction ] = useState( 'deny' );
	const [ form, setForm ] = useState( EMPTY );
	const [ busy, setBusy ] = useState( '' );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const set = ( patch ) => setForm( ( prev ) => ( { ...prev, ...patch } ) );

	const identity = () =>
		kind === 'address'
			? {
					kind,
					country: form.country,
					postcode: form.postcode,
					address_1: form.address_1,
					address_2: form.address_2,
				}
			: { kind, value: form.value, country: form.country };

	const submit = async ( which ) => {
		setBusy( which );
		try {
			if ( which === 'add' ) {
				await addException( {
					...identity(),
					action,
					note: form.note,
				} );
				createSuccessNotice(
					__( 'Exception saved.', 'trustorder-for-woo' ),
					{ type: 'snackbar' }
				);
			} else {
				await removeException( identity() );
				createSuccessNotice(
					__( 'Exception removed.', 'trustorder-for-woo' ),
					{ type: 'snackbar' }
				);
			}
			setForm( { ...EMPTY, country: form.country } );
			onChanged();
		} catch ( error ) {
			createErrorNotice( requestError( error ), { type: 'snackbar' } );
		} finally {
			setBusy( '' );
		}
	};

	return (
		<Section
			className="is-accent"
			title={ __( 'Add or remove an exception', 'trustorder-for-woo' ) }
			description={ __(
				'An exception overrides the shared reputation for this shop only.',
				'trustorder-for-woo'
			) }
		>
			<SettingRow title={ __( 'What to match', 'trustorder-for-woo' ) }>
				<Segmented
					label={ __( 'What to match', 'trustorder-for-woo' ) }
					options={ KINDS }
					value={ kind }
					onChange={ setKind }
				/>
			</SettingRow>
			<SettingRow stacked title={ __( 'Value', 'trustorder-for-woo' ) }>
				<IdentityFields kind={ kind } form={ form } set={ set } />
				<Callout>
					{ __(
						'The value is turned into a one-way hash on this server and only the hash is sent. The value itself is not saved anywhere, so the list below cannot show it: to remove an exception, type the same value again.',
						'trustorder-for-woo'
					) }
				</Callout>
			</SettingRow>
			<SettingRow title={ __( 'Rule', 'trustorder-for-woo' ) }>
				<Segmented
					label={ __( 'Rule', 'trustorder-for-woo' ) }
					options={ ACTIONS }
					value={ action }
					onChange={ setAction }
				/>
			</SettingRow>
			<SettingRow
				title={ __( 'Note (optional)', 'trustorder-for-woo' ) }
				help={ __(
					'Stored on the TrustOrder service and visible only to your shop, up to 500 characters. Do not write personal data here (no name, e-mail, phone or address).',
					'trustorder-for-woo'
				) }
			>
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Note', 'trustorder-for-woo' ) }
					hideLabelFromVision
					maxLength={ 500 }
					value={ form.note }
					onChange={ ( value ) => set( { note: value } ) }
				/>
			</SettingRow>
			<div className="to-actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					isBusy={ busy === 'add' }
					disabled={ !! busy }
					accessibleWhenDisabled
					onClick={ () => submit( 'add' ) }
				>
					{ __( 'Save exception', 'trustorder-for-woo' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					isDestructive
					isBusy={ busy === 'remove' }
					disabled={ !! busy }
					accessibleWhenDisabled
					onClick={ () => submit( 'remove' ) }
				>
					{ __(
						'Remove exception for this value',
						'trustorder-for-woo'
					) }
				</Button>
			</div>
		</Section>
	);
}
