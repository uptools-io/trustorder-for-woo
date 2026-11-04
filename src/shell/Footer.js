/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
// The brand file as is (inlined as a data URI by the build, never recolored).
import logoUrl from '../components/uptools-logo.svg';

/**
 * Main column footer: the upTools logo linking to uptools.io. Same height as
 * the sidebar footer, so the two line up.
 */
export default function Footer() {
	return (
		<footer className="to-footer">
			<a
				href="https://uptools.io"
				target="_blank"
				rel="noopener noreferrer"
			>
				<img
					className="to-footer__logo"
					src={ logoUrl }
					alt=""
					width="110"
				/>
				<span className="screen-reader-text">
					{ __(
						'upTools (opens in a new tab)',
						'trustorder-for-woo'
					) }
				</span>
			</a>
		</footer>
	);
}
