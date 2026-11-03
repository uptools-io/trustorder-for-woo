/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './boot';

// apiFetch sends the X-WP-Nonce header itself (core's nonce middleware).
const call = ( path, options = {} ) =>
	apiFetch( { path: `/${ NAMESPACE }${ path }`, ...options } );

export const fetchSettings = () => call( '/settings' );

export const saveSettings = ( data ) =>
	call( '/settings', { method: 'POST', data } );

export const fetchState = () => call( '/connection' );

export const testConnection = () =>
	call( '/connection/test', { method: 'POST' } );

export const fetchExceptions = ( cursor ) =>
	call(
		cursor
			? `/exceptions?cursor=${ encodeURIComponent( cursor ) }`
			: '/exceptions'
	);

export const addException = ( data ) =>
	call( '/exceptions', { method: 'POST', data } );

export const removeException = ( data ) =>
	call( '/exceptions', { method: 'DELETE', data } );

export const fetchBackfill = () => call( '/backfill/status' );

export const startBackfill = () =>
	call( '/backfill/start', { method: 'POST' } );
