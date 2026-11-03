/**
 * WordPress dependencies
 */
import { ProgressBar } from '@wordpress/components';

/**
 * Key/value tile (label, big value, detail line, optional meter).
 *
 * @param {Object}  props
 * @param {string}  props.label  Small caps label.
 * @param {Element} props.value  Big value.
 * @param {Element} props.detail Detail line.
 * @param {number}  props.meter  Optional 0-100 progress.
 */
export default function StatTile( { label, value, detail, meter } ) {
	return (
		<div className="to-tile">
			<span className="to-tile__label">{ label }</span>
			<span className="to-tile__value">{ value }</span>
			{ typeof meter === 'number' && (
				<ProgressBar className="to-tile__meter" value={ meter } />
			) }
			{ detail && <span className="to-tile__detail">{ detail }</span> }
		</div>
	);
}
