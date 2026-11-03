/**
 * WordPress dependencies
 */
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import App from './App';
import './admin.scss';

const root = document.getElementById( 'trustorder-root' );

if ( root ) {
	createRoot( root ).render( <App /> );
}
