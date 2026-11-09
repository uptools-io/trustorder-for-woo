/**
 * WordPress dependencies
 */
import { useEffect, useRef, useState } from '@wordpress/element';

const read = ( ids ) => {
	const hash = window.location.hash.replace( '#', '' );
	return ids.includes( hash ) ? hash : ids[ 0 ];
};

/**
 * Current tab from location.hash, so plain <a href="#upload"> links (header
 * navigation, chips) switch tabs and the browser back button works.
 *
 * After a tab change it moves focus to the page heading and puts the page
 * title into document.title, so screen reader users learn the page changed.
 *
 * @param {string[]}                ids      Tab ids, first is the default.
 * @param {(id: string) => ?string} titleFor Page title of a tab id.
 */
export default function useHashTab( ids, titleFor ) {
	const [ tab, setTab ] = useState( () => read( ids ) );
	const firstRender = useRef( true );
	const baseTitle = useRef( window.document.title );

	useEffect( () => {
		const title = titleFor?.( tab );
		if ( title ) {
			window.document.title = `${ title } ‹ ${ baseTitle.current }`;
		}
		if ( firstRender.current ) {
			firstRender.current = false;
			return;
		}
		window.document
			.querySelector( '.to-topbar__title' )
			?.focus( { preventScroll: true } );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tab ] );

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
