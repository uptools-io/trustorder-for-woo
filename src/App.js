/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Notices from './components/Notices';
import AppSkeleton from './components/skeleton/AppSkeleton';
import useSettings from './data/useSettings';
import Shell from './shell/Shell';

/**
 * Settings first (skeleton until they arrive), then the shell.
 */
export default function App() {
	const store = useSettings();

	if ( store.isLoading ) {
		return <AppSkeleton />;
	}

	return (
		<>
			{ store.loadFailed ? (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'Could not load the TrustOrder settings. Reload the page.',
						'trustorder-for-woo'
					) }
				</Notice>
			) : (
				<Shell store={ store } />
			) }
			<Notices />
		</>
	);
}
