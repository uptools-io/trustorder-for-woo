/**
 * Internal dependencies
 */
import StatusBadge from './StatusBadge';

/**
 * Label + explanation | status badge rows.
 *
 * @param {Object} props
 * @param {Array}  props.items { id, label, detail, status, badge }.
 */
export default function StateList( { items } ) {
	return (
		<ul className="to-state">
			{ items.map( ( item ) => (
				<li key={ item.id }>
					<span className="to-state__text">
						<strong>{ item.label }</strong>
						{ item.detail && <span>{ item.detail }</span> }
					</span>
					<StatusBadge status={ item.status }>
						{ item.badge }
					</StatusBadge>
				</li>
			) ) }
		</ul>
	);
}
