/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { PAGES } from '../pages';
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
			<SideNav pages={ PAGES } current={ id } meta={ meta } />
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
