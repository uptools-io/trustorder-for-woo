/**
 * WordPress dependencies
 */
import { Button, VisuallyHidden } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { external } from '@wordpress/icons';

/** Where a shop enables TrustOrder and creates its API keys. */
export const CONSOLE_URL = 'https://console.uptools.io/';

/**
 * Button that opens the upTools Console in a new tab.
 *
 * @param {Object} props
 * @param {string} props.variant Button variant (default secondary).
 * @param {string} props.label   Visible text (default "Open the upTools Console").
 */
export function ConsoleButton( { variant = 'secondary', label } ) {
	return (
		<Button
			__next40pxDefaultSize
			variant={ variant }
			href={ CONSOLE_URL }
			target="_blank"
			rel="noopener noreferrer"
			icon={ external }
			iconPosition="right"
		>
			{ label || __( 'Open the upTools Console', 'trustorder-for-woo' ) }
			<VisuallyHidden as="span">
				{ __( '(opens in a new tab)', 'trustorder-for-woo' ) }
			</VisuallyHidden>
		</Button>
	);
}

/**
 * The three steps to a free key, with small replicas of the tab and the
 * button the Console shows (labels as the Console names them).
 */
export function KeySteps() {
	return (
		<ol className="to-keysteps">
			<li className="to-keysteps__step">
				<span className="to-keysteps__num" aria-hidden="true">
					1
				</span>
				<p className="to-keysteps__title">
					{ __(
						'Enable TrustOrder in the upTools Console',
						'trustorder-for-woo'
					) }
				</p>
				<p className="to-keysteps__desc">
					{ __(
						'Products → TrustOrder → Enable. No account yet? You can sign up there.',
						'trustorder-for-woo'
					) }
				</p>
			</li>
			<li className="to-keysteps__step">
				<span className="to-keysteps__num" aria-hidden="true">
					2
				</span>
				<p className="to-keysteps__title">
					{ __(
						'Add your webshop and create a key',
						'trustorder-for-woo'
					) }
				</p>
				<span className="to-keysteps__tab">
					{ __( 'Webshops and API keys', 'trustorder-for-woo' ) }
				</span>
				<span className="to-keysteps__btn">
					{ __( '+ New key', 'trustorder-for-woo' ) }
				</span>
			</li>
			<li className="to-keysteps__step is-here">
				<span className="to-keysteps__num" aria-hidden="true">
					3
				</span>
				<p className="to-keysteps__title">
					{ __( 'Paste it here and save', 'trustorder-for-woo' ) }
				</p>
				<p className="to-keysteps__desc">
					{ __(
						'The Console shows the key only once, so copy it right away.',
						'trustorder-for-woo'
					) }
				</p>
			</li>
		</ol>
	);
}
