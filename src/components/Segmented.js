/**
 * WordPress dependencies
 */
import {
	__experimentalToggleGroupControl as ToggleGroupControl,
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';

/**
 * Soft segmented control (core ToggleGroupControl, restyled in admin.scss).
 *
 * @param {Object}   props
 * @param {string}   props.label     Accessible label.
 * @param {boolean}  props.hideLabel Hide the label visually.
 * @param {Array}    props.options   { value, label }.
 * @param {string}   props.value     Selected value.
 * @param {Function} props.onChange  Receives the new value.
 */
export default function Segmented( {
	label,
	hideLabel = true,
	options,
	value,
	onChange,
} ) {
	return (
		<ToggleGroupControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			isBlock
			label={ label }
			hideLabelFromVision={ hideLabel }
			value={ value }
			onChange={ onChange }
		>
			{ options.map( ( option ) => (
				<ToggleGroupControlOption
					key={ option.value }
					value={ option.value }
					label={ option.label }
				/>
			) ) }
		</ToggleGroupControl>
	);
}
