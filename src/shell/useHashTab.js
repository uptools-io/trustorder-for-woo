/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';

const read = ( ids ) => {
	const hash = window.location.hash.replace( '#', '' );
	return ids.includes( hash ) ? hash : ids[ 0 ];
};

/**
 * Current tab from location.hash, so plain <a href="#upload"> links (header
 * navigation, chips) switch tabs and the browser back button works.
 *
 * @param {string[]} ids Tab ids, first is the default.
 */
export default function useHashTab( ids ) {
	const [ tab, setTab ] = useState( () => read( ids ) );

	useEffect( () => {
		const onChange = () => {
			setTab( read( ids ) );
			window.scrollTo( { top: 0 } );
		};
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return tab;
}
