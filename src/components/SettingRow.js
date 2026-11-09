/**
 * WordPress dependencies
 */
import { useInstanceId } from '@wordpress/compose';

/**
 * Two-column settings row: title + help on the left, the control on the right.
 * Controls inside should hide their own label from sight (hideLabelFromVision)
 * or be tied to the title through `htmlFor`, so each field has one accessible
 * name and one visible title.
 *
 * @param {Object}           props
 * @param {string}           props.title    Visible title.
 * @param {Element}          props.help     Description under the title.
 * @param {string}           props.htmlFor  Id of the control the title labels.
 * @param {boolean}          props.stacked  Control goes under the text (wide controls).
 * @param {Element|Function} props.children The control(s). A function gets
 *                                          `{ describedBy }`, the id of the help
 *                                          text (undefined without help), to tie it
 *                                          to a control with aria-describedby.
 */
export default function SettingRow( {
	title,
	help,
	htmlFor,
	stacked = false,
	children,
} ) {
	const helpId = useInstanceId( SettingRow, 'to-row-help' );
	const describedBy = help ? helpId : undefined;
	const Title = htmlFor ? 'label' : 'span';

	return (
		<div className={ `to-row ${ stacked ? 'is-stacked' : '' }` }>
			<div className="to-row__text">
				<Title className="to-row__title" htmlFor={ htmlFor }>
					{ title }
				</Title>
				{ help && (
					<p className="to-row__help" id={ describedBy }>
						{ help }
					</p>
				) }
			</div>
			<div className="to-row__control">
				{ 'function' === typeof children
					? children( { describedBy } )
					: children }
			</div>
		</div>
	);
}
