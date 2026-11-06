/**
 * WordPress dependencies
 */
import { useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { PAGES } from '../pages';
import QuickSearch from '../quick-search';
import Footer from './Footer';
import SideNav from './SideNav';
import TopBar from './TopBar';
import useHashTab from './useHashTab';
import useSaveShortcut from './useSaveShortcut';
import useUnsavedWarning from './useUnsavedWarning';

/**
 * App shell: full-height sidebar | main column (top bar + scrolling content).
 *
 * @param {Object} props
 * @param {Object} props.store useSettings() result.
 */
export default function Shell( { store } ) {
	const id = useHashTab( PAGES.map( ( page ) => page.id ) );
	const page = PAGES.find( ( item ) => item.id === id );
	const { Component } = page;

	useUnsavedWarning( store.hasEdits );
	useSaveShortcut( store.save, store.hasEdits && ! store.isSaving );

	// The quick search indexes Settings and Help (both render from memory).
	// The other pages load from the service on mount, so they are found by
	// name only.
	const searchPages = useCallback(
		() =>
			PAGES.filter( ( item ) =>
				[ 'settings', 'help' ].includes( item.id )
			).map( ( item ) => ( {
				tab: item,
				element: <item.Component store={ store } />,
			} ) ),
		[ store ]
	);

	const meta = {
		settings: store.hasEdits ? (
			<span className="to-sidenav__dot is-dirty">
				<span className="screen-reader-text">
					{ __( 'Unsaved changes', 'trustorder-for-woo' ) }
				</span>
			</span>
		) : null,
	};

	return (
		<div className="to-shell">
			<SideNav
				pages={ PAGES }
				current={ id }
				meta={ meta }
				search={
					<QuickSearch
						tabs={ PAGES }
						pages={ searchPages }
						storageKey="trustorder-quick-search"
					/>
				}
			/>
			<div className="to-main">
				<TopBar
					title={ page.title }
					store={ store }
					saves={ !! page.saves }
				/>
				<main className="to-scroll">
					<div className="to-content">
						<Component store={ store } />
					</div>
				</main>
				<Footer />
			</div>
		</div>
	);
}
