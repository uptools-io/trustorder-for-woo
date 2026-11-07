/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import Section from '../components/Section';
import StatTile from '../components/StatTile';
import StatusBadge from '../components/StatusBadge';
import { SkeletonTiles } from '../components/skeleton';
import { testConnection } from '../data/api';
import { ConsoleButton } from './GetKeyNote';
import { cached, remember } from '../data/pageCache';
import { requestError } from '../data/errors';
import { formatDate, formatNumber, percent } from '../data/format';

/**
 * Environment badge from the key env.
 *
 * @param {Object}      props
 * @param {string|null} props.env live|test.
 */
export function EnvBadge( { env } ) {
	if ( env === 'test' ) {
		return (
			<StatusBadge status="warning">
				{ __( 'Test mode', 'trustorder-for-woo' ) }
			</StatusBadge>
		);
	}
	return env === 'live' ? (
		<StatusBadge status="ok">
			{ __( 'Live', 'trustorder-for-woo' ) }
		</StatusBadge>
	) : null;
}

/**
 * Connection test (GET /me through the plugin), run on open.
 *
 * @param {Object} props
 * @param {Object} props.state GET /connection data.
 */
export default function ConnectionCard( { state } ) {
	const key = `test:${ state.key_prefix }`;
	const [ result, setResult ] = useState(
		() => cached( key ) || { status: 'idle' }
	);

	const run = useCallback( () => {
		setResult( { status: 'testing' } );
		testConnection().then(
			( data ) => setResult( remember( key, { status: 'ok', ...data } ) ),
			( error ) =>
				setResult(
					remember( key, {
						status: 'error',
						message: requestError( error ),
					} )
				)
		);
	}, [ key ] );

	// Test once per page load and key; "Test again" re-runs it.
	useEffect( () => {
		if ( state.has_key && ! cached( key ) ) {
			run();
		}
	}, [ state.has_key, key, run ] );

	const actions = state.has_key && (
		<Button
			__next40pxDefaultSize
			variant="secondary"
			isBusy={ result.status === 'testing' }
			disabled={ result.status === 'testing' }
			accessibleWhenDisabled
			onClick={ run }
		>
			{ __( 'Test again', 'trustorder-for-woo' ) }
		</Button>
	);

	return (
		<Section
			className="is-accent"
			title={ __( 'Connection', 'trustorder-for-woo' ) }
			badge={ <EnvBadge env={ state.env } /> }
			description={
				result.tested_at &&
				sprintf(
					/* translators: %s: date and time of the test. */
					__( 'Tested: %s', 'trustorder-for-woo' ),
					formatDate( result.tested_at )
				)
			}
			actions={ actions }
		>
			{ ! state.has_key && (
				<Callout>
					<p className="to-callout__text">
						{ __(
							'No API key yet. Until you add one, every payment method stays available. The key is free: create it in the upTools Console, then paste it on the Settings page.',
							'trustorder-for-woo'
						) }
					</p>
					<div className="to-callout__actions">
						<ConsoleButton />
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							href="#settings"
						>
							{ __( 'Go to Settings', 'trustorder-for-woo' ) }
						</Button>
					</div>
				</Callout>
			) }
			{ result.status === 'testing' && <SkeletonTiles count={ 3 } /> }
			{ result.status === 'error' && (
				<Notice status="error" isDismissible={ false }>
					{ result.message }
				</Notice>
			) }
			{ result.status === 'ok' && <MeTiles me={ result.me } /> }
		</Section>
	);
}

/**
 * Shop, account status and today's usage.
 *
 * @param {Object} props
 * @param {Object} props.me Allow-listed /me fields.
 */
function MeTiles( { me } ) {
	const pending = me.account_status === 'pending';

	return (
		<div className="to-tiles">
			<StatTile
				label={ __( 'Shop', 'trustorder-for-woo' ) }
				value={ me.shop_name }
				detail={ me.domain }
			/>
			<StatTile
				label={ __( 'Account', 'trustorder-for-woo' ) }
				value={
					<StatusBadge status={ pending ? 'warning' : 'ok' }>
						{ pending
							? __( 'Awaiting approval', 'trustorder-for-woo' )
							: __( 'Approved', 'trustorder-for-woo' ) }
					</StatusBadge>
				}
				detail={
					pending
						? __(
								'Checks already work; upTools approves the account soon.',
								'trustorder-for-woo'
							)
						: __( 'The account is active.', 'trustorder-for-woo' )
				}
			/>
			<StatTile
				label={ __( 'Checks today', 'trustorder-for-woo' ) }
				value={ formatNumber( me.checks_today ) }
				meter={ percent( me.checks_today, me.daily_check_quota ) }
				detail={ sprintf(
					/* translators: %s: daily check quota. */
					__( 'Daily quota: %s', 'trustorder-for-woo' ),
					formatNumber( me.daily_check_quota )
				) }
			/>
		</div>
	);
}
