/**
 * WordPress dependencies
 */
import { CheckboxControl } from '@wordpress/components';

/**
 * A set of checkboxes over { id, title, note } options, value = list of ids.
 *
 * @param {Object}   props
 * @param {string}   props.legend   Group name (screen readers).
 * @param {Array}    props.options  { id, title, note }.
 * @param {string[]} props.value    Selected ids.
 * @param {Function} props.onChange Receives the new id list.
 */
export default function CheckList( { legend, options, value, onChange } ) {
	const toggle = ( id, checked ) =>
		onChange(
			checked
				? [ ...value, id ]
				: value.filter( ( current ) => current !== id )
		);

	return (
		<fieldset className="to-checks">
			<legend className="screen-reader-text">{ legend }</legend>
			{ options.map( ( option ) => (
				<CheckboxControl
					key={ option.id }
					__nextHasNoMarginBottom
					label={ option.title }
					help={ option.note }
					checked={ value.includes( option.id ) }
					onChange={ ( checked ) => toggle( option.id, checked ) }
				/>
			) ) }
		</fieldset>
	);
}
