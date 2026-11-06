/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Section from '../../components/Section';
import OrderBadge from '../OrderBadge';
import { BADGE } from './badges';

/**
 * A sample orders list with the COD check column highlighted: where the
 * badges appear. Sample data only, never real orders.
 */
export default function OrderListMock() {
	const rows = [
		{
			id: '#41207',
			name: __( 'Anna Sample', 'trustorder-for-woo' ),
			status: __( 'Processing', 'trustorder-for-woo' ),
			badge: BADGE.trusted( 92 ),
		},
		{
			id: '#41206',
			name: __( 'Ben Example', 'trustorder-for-woo' ),
			status: __( 'Processing', 'trustorder-for-woo' ),
			badge: BADGE.newCustomer(),
		},
		{
			id: '#41205',
			name: __( 'Clara Test', 'trustorder-for-woo' ),
			status: __( 'Pending payment', 'trustorder-for-woo' ),
			badge: BADGE.risky( 55 ),
		},
		{
			id: '#41204',
			name: __( 'Dan Demo', 'trustorder-for-woo' ),
			status: __( 'Completed', 'trustorder-for-woo' ),
			badge: BADGE.limited( 80 ),
		},
	];

	return (
		<Section
			className="to-help-mock"
			title={ __( 'Where you see it', 'trustorder-for-woo' ) }
			description={ __(
				'The last column of WooCommerce → Orders is COD check. Every order gets a badge about the buyer.',
				'trustorder-for-woo'
			) }
		>
			<div className="to-help-mock__scroll">
				<table className="to-help-mock__table">
					<caption className="screen-reader-text">
						{ __( 'Sample orders list', 'trustorder-for-woo' ) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Order', 'trustorder-for-woo' ) }
							</th>
							<th scope="col">
								{ __( 'Buyer', 'trustorder-for-woo' ) }
							</th>
							<th scope="col">
								{ __( 'Status', 'trustorder-for-woo' ) }
							</th>
							<th scope="col" className="is-hl">
								{ __( 'COD check', 'trustorder-for-woo' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ row.id }>
								<td>{ row.id }</td>
								<td>{ row.name }</td>
								<td>
									<span className="to-help-mock__status">
										{ row.status }
									</span>
								</td>
								<td className="is-hl">
									<OrderBadge { ...row.badge } />
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</Section>
	);
}
