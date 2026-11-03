/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { fetchExceptions } from '../data/api';
import { requestError } from '../data/errors';
import ExceptionForm from './ExceptionForm';
import ExceptionList from './ExceptionList';

/**
 * Cursor-paged exception list.
 */
function useExceptions() {
	const [ state, setState ] = useState( {
		items: null,
		next: null,
		error: '',
		isLoading: true,
	} );

	const load = useCallback( ( cursor ) => {
		setState( ( prev ) => ( { ...prev, isLoading: true, error: '' } ) );
		fetchExceptions( cursor ).then(
			( data ) =>
				setState( ( prev ) => ( {
					items: cursor
						? [ ...( prev.items || [] ), ...data.items ]
						: data.items,
					next: data.next_cursor,
					error: '',
					isLoading: false,
				} ) ),
			( error ) =>
				setState( ( prev ) => ( {
					...prev,
					error: requestError( error ),
					isLoading: false,
				} ) )
		);
	}, [] );

	useEffect( () => load( null ), [ load ] );

	return {
		...state,
		reload: () => load( null ),
		more: () => load( state.next ),
	};
}

/**
 * Kivételek: add/remove form + the list.
 */
export default function ExceptionsPage() {
	const list = useExceptions();

	return (
		<>
			<ExceptionForm onChanged={ list.reload } />
			<ExceptionList list={ list } />
		</>
	);
}
