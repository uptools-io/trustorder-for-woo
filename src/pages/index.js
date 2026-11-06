/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { backup, cog, help, home, shield } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import BackfillPage from './BackfillPage';
import ExceptionsPage from './ExceptionsPage';
import HelpPage from './HelpPage';
import OverviewPage from './OverviewPage';
import SettingsPage from './SettingsPage';

export const PAGES = [
	{
		id: 'overview',
		label: __( 'Overview', 'trustorder-for-woo' ),
		title: __( 'Overview', 'trustorder-for-woo' ),
		icon: home,
		Component: OverviewPage,
	},
	{
		id: 'settings',
		label: __( 'Settings', 'trustorder-for-woo' ),
		title: __( 'Settings', 'trustorder-for-woo' ),
		icon: cog,
		Component: SettingsPage,
		saves: true,
	},
	{
		id: 'exceptions',
		label: __( 'Exceptions', 'trustorder-for-woo' ),
		title: __( 'Shop exceptions', 'trustorder-for-woo' ),
		icon: shield,
		Component: ExceptionsPage,
	},
	{
		id: 'backfill',
		label: __( 'Backfill', 'trustorder-for-woo' ),
		title: __( 'Backfill past orders', 'trustorder-for-woo' ),
		icon: backup,
		Component: BackfillPage,
	},
	{
		id: 'help',
		label: __( 'Help', 'trustorder-for-woo' ),
		title: __( 'Help', 'trustorder-for-woo' ),
		icon: help,
		Component: HelpPage,
	},
];
