/**
 * WordPress dependencies
 */
import {
	Button,
	TextControl,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHStack as HStack,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { check, Icon, seen, unseen } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import Section from '../components/Section';
import SettingRow from '../components/SettingRow';
import StatusBadge from '../components/StatusBadge';
import { cached } from '../data/pageCache';
import { EnvBadge } from './ConnectionCard';
import { ConsoleButton, KeySteps } from './GetKeyNote';

/**
 * The key field (write-only: never filled from the saved key).
 *
 * @param {Object} props
 * @param {Object} props.store useSettings() result.
 */
function KeyInput( { store } ) {
	const [ visible, setVisible ] = useState( false );

	return (
		<>
			<HStack alignment="bottom" spacing={ 2 } className="to-keyrow">
				<TextControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'API key', 'trustorder-for-woo' ) }
					hideLabelFromVision
					type={ visible ? 'text' : 'password' }
					placeholder="to_live_…"
					autoComplete="off"
					spellCheck={ false }
					value={ store.settings.api_key || '' }
					onChange={ ( value ) => store.update( { api_key: value } ) }
				/>
				<Button
					__next40pxDefaultSize
					variant="secondary"
					icon={ visible ? unseen : seen }
					label={
						visible
							? __( 'Hide key', 'trustorder-for-woo' )
							: __( 'Show key', 'trustorder-for-woo' )
					}
					isPressed={ visible }
					onClick={ () => setVisible( ! visible ) }
				/>
			</HStack>
			<p className="to-keyenvs">
				<span>
					<code>to_live_</code>{ ' ' }
					{ __( 'for the live shop', 'trustorder-for-woo' ) }
				</span>
				<span>
					<code>to_test_</code>{ ' ' }
					{ __(
						'for testing, into a separate database',
						'trustorder-for-woo'
					) }
				</span>
			</p>
		</>
	);
}

/**
 * Write-only API key. Without a key: the steps to a free key in the upTools
 * Console and the field. With a key: its prefix, the connection state from
 * the Overview test (when it ran on this page load) and the key actions.
 *
 * @param {Object} props
 * @param {Object} props.store useSettings() result.
 */
export default function ApiKeyCard( { store } ) {
	const [ replacing, setReplacing ] = useState( false );
	const [ confirming, setConfirming ] = useState( false );
	const { settings } = store;

	if ( settings.key_locked ) {
		return (
			<Section
				className="is-accent"
				title={ __( 'API key', 'trustorder-for-woo' ) }
				badge={ <EnvBadge env={ settings.env } /> }
			>
				<SettingRow
					title={ __(
						'Key from the configuration',
						'trustorder-for-woo'
					) }
					help={ __(
						'The key is set by the TRUSTORDER_API_KEY constant (wp-config.php or .env), so it cannot be changed here.',
						'trustorder-for-woo'
					) }
				>
					<span className="to-keyprefix">
						{ settings.has_key
							? `${ settings.key_prefix }…`
							: __( 'Not a valid key', 'trustorder-for-woo' ) }
					</span>
				</SettingRow>
			</Section>
		);
	}

	if ( ! settings.has_key ) {
		return (
			<Section
				className="is-accent to-keycard"
				title={ __( 'API key', 'trustorder-for-woo' ) }
				badge={
					<StatusBadge status="ok">
						{ __( 'Free', 'trustorder-for-woo' ) }
					</StatusBadge>
				}
				description={ __(
					'TrustOrder connects to the network with the key. Without a key nothing is checked and every payment method stays available.',
					'trustorder-for-woo'
				) }
			>
				<KeySteps />
				<div className="to-keycta">
					<ConsoleButton
						variant="primary"
						label={ __(
							'Create a key in the Console',
							'trustorder-for-woo'
						) }
					/>
					<span className="to-keycta__hint">
						{ __(
							'Opens in a new tab, this page stays open.',
							'trustorder-for-woo'
						) }
					</span>
				</div>
				<SettingRow
					title={ __( 'Your API key', 'trustorder-for-woo' ) }
					help={ __( 'Paste it, then save.', 'trustorder-for-woo' ) }
				>
					<KeyInput store={ store } />
				</SettingRow>
			</Section>
		);
	}

	const test = cached( `test:${ settings.key_prefix }` );
	const showInput = replacing || !! settings.api_key;

	return (
		<Section
			className="is-accent"
			title={ __( 'API key', 'trustorder-for-woo' ) }
		>
			<div className="to-keysaved">
				<div className="to-keysaved__state">
					<span className="to-keysaved__chip">
						<span className="to-keyprefix">{ `${ settings.key_prefix }…` }</span>
						<EnvBadge env={ settings.env } />
					</span>
					{ test?.status === 'ok' && (
						<span className="to-keysaved__ok">
							<Icon icon={ check } size={ 20 } />
							{ __( 'Connection OK', 'trustorder-for-woo' ) }
						</span>
					) }
				</div>
				<div className="to-keysaved__actions">
					<Button
						__next40pxDefaultSize
						variant="secondary"
						aria-expanded={ showInput }
						onClick={ () => setReplacing( ! replacing ) }
					>
						{ __( 'Replace the key', 'trustorder-for-woo' ) }
					</Button>
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isDestructive
						disabled={ store.isSaving }
						accessibleWhenDisabled
						onClick={ () => setConfirming( true ) }
					>
						{ __( 'Remove key', 'trustorder-for-woo' ) }
					</Button>
					<ConsoleButton
						variant="tertiary"
						label={ __(
							'Keys in the Console',
							'trustorder-for-woo'
						) }
					/>
				</div>
			</div>
			{ showInput && (
				<SettingRow
					title={ __( 'New key', 'trustorder-for-woo' ) }
					help={ __(
						'Paste the key from the upTools Console, then save.',
						'trustorder-for-woo'
					) }
				>
					<KeyInput store={ store } />
				</SettingRow>
			) }
			<ConfirmDialog
				isOpen={ confirming }
				confirmButtonText={ __( 'Remove key', 'trustorder-for-woo' ) }
				cancelButtonText={ __( 'Cancel', 'trustorder-for-woo' ) }
				onConfirm={ () => {
					setConfirming( false );
					store.removeKey();
				} }
				onCancel={ () => setConfirming( false ) }
			>
				{ __(
					'Remove the saved API key? Until you add a key again, no buyer is checked and no order outcome is reported.',
					'trustorder-for-woo'
				) }
			</ConfirmDialog>
		</Section>
	);
}
