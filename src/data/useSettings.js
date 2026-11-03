/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { fetchSettings, saveSettings } from './api';
import { requestError } from './errors';

const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );

/**
 * Settings from GET /settings with a local draft. The API key is write-only:
 * the draft holds a newly typed key, the server only ever returns its prefix.
 */
export default function useSettings() {
	const [ view, setView ] = useState( null );
	const [ draft, setDraft ] = useState( {} );
	const [ loadFailed, setLoadFailed ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	useEffect( () => {
		fetchSettings().then( setView, () => setLoadFailed( true ) );
	}, [] );

	const saved = view?.settings || {};
	const settings = { ...saved, ...draft };
	const hasEdits = Object.keys( draft ).some( ( key ) =>
		key === 'api_key'
			? draft.api_key !== ''
			: ! same( draft[ key ], saved[ key ] )
	);

	const update = useCallback(
		( patch ) => setDraft( ( prev ) => ( { ...prev, ...patch } ) ),
		[]
	);

	const discard = useCallback( () => setDraft( {} ), [] );

	const send = async ( data, message, nextDraft ) => {
		setIsSaving( true );
		try {
			const next = await saveSettings( data );
			setView( next );
			setDraft( nextDraft );
			createSuccessNotice( message, { type: 'snackbar' } );
			return true;
		} catch ( error ) {
			createErrorNotice( requestError( error ), { type: 'snackbar' } );
			return false;
		} finally {
			setIsSaving( false );
		}
	};

	const save = () => {
		const data = { ...draft };
		if ( data.api_key === '' ) {
			delete data.api_key;
		}
		return send(
			data,
			__( 'Settings saved.', 'trustorder-for-woo' ),
			() => ( {} )
		);
	};

	// Removing the key keeps the other unsaved edits.
	const removeKey = () =>
		send(
			{ api_key: '' },
			__( 'API key removed.', 'trustorder-for-woo' ),
			( { api_key: dropped, ...rest } ) => rest // eslint-disable-line no-unused-vars
		);

	return {
		settings,
		gateways: view?.gateways || [],
		shippingMethods: view?.shipping_methods || [],
		isLoading: ! view && ! loadFailed,
		loadFailed,
		hasEdits,
		isSaving,
		update,
		discard,
		save,
		removeKey,
	};
}
