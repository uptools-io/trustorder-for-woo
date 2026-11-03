/**
 * WordPress dependencies
 */
import { Button, Notice, ProgressBar } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import Callout from '../components/Callout';
import Section from '../components/Section';
import StatTile from '../components/StatTile';
import StatusBadge from '../components/StatusBadge';
import { SkeletonSection, SkeletonTiles } from '../components/skeleton';
import { fetchBackfill, startBackfill } from '../data/api';
import { requestError } from '../data/errors';
import { formatDate, formatNumber } from '../data/format';
import useRemote from '../data/useRemote';
import { EnvBadge } from './ConnectionCard';

const POLL_MS = 5000;

/**
 * Phase of one env's run.
 *
 * @param {Object} run Backfill::status() shape.
 * @return {string} idle|running|stopped|finished.
 */
export function phase( run ) {
	if ( run.running ) {
		return 'running';
	}
	if ( ! run.started_at ) {
		return 'idle';
	}
	return run.finished_at ? 'finished' : 'stopped';
}

/**
 * Visszatöltés: report past completed / not picked up orders (≤ 3 years).
 */
export default function BackfillPage() {
	const status = useRemote( fetchBackfill );
	const [ starting, setStarting ] = useState( false );
	const { createErrorNotice } = useDispatch( noticesStore );
	const env = status.data?.env;
	const run = env ? status.data[ env ] : null;
	const current = run ? phase( run ) : 'idle';

	useEffect( () => {
		if ( current !== 'running' ) {
			return;
		}
		const timer = setInterval( status.reload, POLL_MS );
		return () => clearInterval( timer );
	}, [ current, status.reload ] );

	if ( ! status.data ) {
		return status.error ? (
			<Notice status="error" isDismissible={ false }>
				{ requestError( status.error ) }
			</Notice>
		) : (
			<SkeletonSection>
				<SkeletonTiles count={ 3 } />
			</SkeletonSection>
		);
	}

	const start = async () => {
		setStarting( true );
		try {
			await startBackfill();
			await status.reload();
		} catch ( error ) {
			createErrorNotice( requestError( error ), { type: 'snackbar' } );
		} finally {
			setStarting( false );
		}
	};

	const badges = {
		idle: (
			<StatusBadge status="idle">
				{ __( 'Not started', 'trustorder-for-woo' ) }
			</StatusBadge>
		),
		running: (
			<StatusBadge status="info">
				{ __( 'Running', 'trustorder-for-woo' ) }
			</StatusBadge>
		),
		stopped: (
			<StatusBadge status="warning">
				{ __( 'Paused', 'trustorder-for-woo' ) }
			</StatusBadge>
		),
		finished: (
			<StatusBadge status="ok">
				{ __( 'Finished', 'trustorder-for-woo' ) }
			</StatusBadge>
		),
	};
	const labels = {
		idle: __( 'Start backfill', 'trustorder-for-woo' ),
		running: __( 'Running…', 'trustorder-for-woo' ),
		stopped: __( 'Resume', 'trustorder-for-woo' ),
		finished: __( 'Run again', 'trustorder-for-woo' ),
	};

	return (
		<Section
			className="is-accent"
			title={ __( 'Past orders', 'trustorder-for-woo' ) }
			badge={ env && <EnvBadge env={ env } /> }
			description={ __(
				'Reports your completed and not picked up orders of the last 3 years, in the background. Orders already reported are skipped, so you can run it again at any time.',
				'trustorder-for-woo'
			) }
			actions={
				env && (
					<Button
						__next40pxDefaultSize
						variant="primary"
						isBusy={ starting }
						disabled={ starting || current === 'running' }
						accessibleWhenDisabled
						onClick={ start }
					>
						{ labels[ current ] }
					</Button>
				)
			}
		>
			{ ! env && (
				<Callout>
					{ __(
						'Save an API key first; the backfill runs for the environment of the key.',
						'trustorder-for-woo'
					) }{ ' ' }
					<a href="#settings">
						{ __( 'Go to Settings', 'trustorder-for-woo' ) }
					</a>
				</Callout>
			) }
			{ run && (
				<div className="to-progress">
					{ badges[ current ] }
					{ current === 'running' && (
						<ProgressBar
							className="to-progress__bar"
							aria-label={ __(
								'Backfill in progress',
								'trustorder-for-woo'
							) }
						/>
					) }
					{ current === 'stopped' && (
						<Callout tone="warning">
							{ __(
								'The run paused after repeated errors (for example the service was unavailable). Resume continues where it stopped.',
								'trustorder-for-woo'
							) }
						</Callout>
					) }
				</div>
			) }
			{ run && current !== 'idle' && (
				<div className="to-tiles">
					<StatTile
						label={ __( 'Processed', 'trustorder-for-woo' ) }
						value={ formatNumber( run.processed ) }
						detail={ sprintf(
							/* translators: %s: date and time. */ __(
								'Started: %s',
								'trustorder-for-woo'
							),
							formatDate( run.started_at )
						) }
					/>
					<StatTile
						label={ __( 'Reported', 'trustorder-for-woo' ) }
						value={ formatNumber( run.sent ) }
						detail={
							run.finished_at
								? sprintf(
										/* translators: %s: date and time. */ __(
											'Finished: %s',
											'trustorder-for-woo'
										),
										formatDate( run.finished_at )
									)
								: __(
										'Orders sent to TrustOrder',
										'trustorder-for-woo'
									)
						}
					/>
					<StatTile
						label={ __( 'Failed', 'trustorder-for-woo' ) }
						value={ formatNumber( run.failed ) }
						detail={ __(
							'Refused by the service, not retried',
							'trustorder-for-woo'
						) }
					/>
				</div>
			) }
		</Section>
	);
}
