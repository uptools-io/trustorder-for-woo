/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import OrderBadge from '../OrderBadge';
import { BADGE } from '../help/badges';

/**
 * Which enabled methods a blocked buyer would not see. Mirrors
 * includes/Checkout/HideRule.php: nothing is hidden unless a fallback (or,
 * with none selected, any other method) stays visible.
 *
 * @param {Array} available Enabled gateway ids.
 * @param {Array} hidden    Configured hidden ids.
 * @param {Array} fallback  Configured fallback ids.
 * @return {Array|null} Hidden ids, or null when hiding would leave nothing.
 */
export function previewHidden( available, hidden, fallback ) {
	const hide = available.filter( ( id ) => hidden.includes( id ) );
	if ( ! hide.length ) {
		return [];
	}
	const remaining = available.filter( ( id ) => ! hide.includes( id ) );
	const payable = fallback.length
		? remaining.some( ( id ) => fallback.includes( id ) )
		: remaining.length > 0;

	return payable ? hide : null;
}

/**
 * Live preview: the payment methods a Blocked buyer sees at checkout with
 * the settings on screen (saved or not).
 *
 * @param {Object} props
 * @param {Array}  props.gateways { id, title, enabled }.
 * @param {Array}  props.hidden   Hidden gateway ids.
 * @param {Array}  props.fallback Fallback gateway ids.
 */
export default function CheckoutPreview( { gateways, hidden, fallback } ) {
	const enabled = gateways.filter( ( g ) => g.enabled );
	if ( ! enabled.length ) {
		return null;
	}
	const gone = previewHidden(
		enabled.map( ( g ) => g.id ),
		hidden,
		fallback
	);

	return (
		<div className="to-preview">
			<p className="to-preview__title">
				<span className="to-preview__kicker">
					{ __( 'Preview', 'trustorder-for-woo' ) }
				</span>
				{ __( 'What a buyer sees at checkout:', 'trustorder-for-woo' ) }
				<OrderBadge { ...BADGE.blocked() } />
			</p>
			<ul className="to-preview__list">
				{ enabled.map( ( g ) => {
					const isGone = !! gone && gone.includes( g.id );
					return (
						<li
							key={ g.id }
							className={ isGone ? 'is-gone' : undefined }
						>
							<span
								className="to-preview__radio"
								aria-hidden="true"
							/>
							<span className="to-preview__name">
								{ g.title }
							</span>
							{ isGone && (
								<span className="to-preview__tag">
									{ __( 'hidden', 'trustorder-for-woo' ) }
								</span>
							) }
						</li>
					);
				} ) }
			</ul>
			{ null === gone && (
				<p className="to-preview__warn">
					{ __(
						'Nothing is hidden now: no fallback method would stay visible. Pick a fallback method that is not hidden.',
						'trustorder-for-woo'
					) }
				</p>
			) }
		</div>
	);
}
