/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Main column footer: who runs the service.
 */
export default function Footer() {
	return (
		<footer className="to-footer">
			<a
				href="https://uptools.io"
				target="_blank"
				rel="noopener noreferrer"
			>
				{ __( 'TrustOrder by upTools', 'trustorder-for-woo' ) }
				<span className="screen-reader-text">
					{ __( '(opens in a new tab)', 'trustorder-for-woo' ) }
				</span>
			</a>
		</footer>
	);
}
