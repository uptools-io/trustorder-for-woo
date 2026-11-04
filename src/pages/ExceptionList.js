/**
 * WordPress dependencies
 */
import { Button, Notice, SelectControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { DataTable, useTableState } from '@lwplugins/data-table';
import '@lwplugins/data-table/style.css';

/**
 * Internal dependencies
 */
import Section from '../components/Section';
import StatusBadge from '../components/StatusBadge';
import { SkeletonRows } from '../components/skeleton';
import { formatDate } from '../data/format';
import { ACTIONS, KINDS } from './ExceptionForm';

const labelOf = ( list, value ) =>
	list.find( ( item ) => item.value === value )?.label || value;

const COLUMNS = [
	{
		id: 'kind',
		label: __( 'Type', 'trustorder-for-woo' ),
		render: ( row ) => labelOf( KINDS, row.kind ),
	},
	{
		id: 'action',
		label: __( 'Rule', 'trustorder-for-woo' ),
		render: ( row ) => (
			<StatusBadge status={ row.action === 'allow' ? 'ok' : 'critical' }>
				{ labelOf( ACTIONS, row.action ) }
			</StatusBadge>
		),
	},
	{
		id: 'note',
		label: __( 'Note', 'trustorder-for-woo' ),
		render: ( row ) => row.note || '—',
	},
	{
		id: 'created_at',
		label: __( 'Added', 'trustorder-for-woo' ),
		sortable: true,
		render: ( row ) => formatDate( row.created_at ),
	},
	{
		id: 'id',
		label: __( 'ID', 'trustorder-for-woo' ),
		render: ( row ) => <code className="to-id">{ row.id }</code>,
	},
];

const PER_PAGE = [ 20, 50 ];

const FILTERS = [
	{
		field: 'kind',
		label: __( 'Type', 'trustorder-for-woo' ),
		options: KINDS,
	},
	{
		field: 'action',
		label: __( 'Rule', 'trustorder-for-woo' ),
		options: ACTIONS,
	},
];

// The package has no text domain: every UI string comes from here.
const LABELS = {
	search: __( 'Search notes', 'trustorder-for-woo' ),
	filter: __( 'Filter', 'trustorder-for-woo' ),
	clear: __( 'Clear', 'trustorder-for-woo' ),
	clearAll: __( 'Clear all filters', 'trustorder-for-woo' ),
	all: __( 'All', 'trustorder-for-woo' ),
	empty: __( 'No matching exceptions.', 'trustorder-for-woo' ),
	emptyAll: __( 'No exceptions yet.', 'trustorder-for-woo' ),
	perPage: __( 'Rows per page', 'trustorder-for-woo' ),
	previous: __( 'Previous page', 'trustorder-for-woo' ),
	next: __( 'Next page', 'trustorder-for-woo' ),
	entries: ( count ) =>
		sprintf(
			/* translators: %d: number of exceptions. */
			_n( '%d exception', '%d exceptions', count, 'trustorder-for-woo' ),
			count
		),
	results: ( count ) =>
		sprintf(
			/* translators: %d: number of matching exceptions. */
			_n( '%d result', '%d results', count, 'trustorder-for-woo' ),
			count
		),
	page: ( page, total ) =>
		sprintf(
			/* translators: 1: current page, 2: total pages. */
			__( 'Page %1$d of %2$d', 'trustorder-for-woo' ),
			page,
			total
		),
};

/**
 * The shop's exceptions (kind, rule, note, date, short id; never the value).
 *
 * @param {Object} props
 * @param {Object} props.list useExceptions() result.
 */
export default function ExceptionList( { list } ) {
	const table = useTableState( list.items, {
		searchFields: [ 'note' ],
		columns: COLUMNS,
		sort: { field: 'created_at', direction: 'desc' },
		perPage: 20,
	} );

	return (
		<Section
			title={ __( 'Exceptions', 'trustorder-for-woo' ) }
			actions={
				list.items !== null && (
					<SelectControl
						__next40pxDefaultSize={ false }
						__nextHasNoMarginBottom
						size="compact"
						className="to-per-page"
						label={ LABELS.perPage }
						labelPosition="side"
						value={ String( table.perPage ) }
						options={ PER_PAGE.map( ( size ) => ( {
							value: String( size ),
							label: String( size ),
						} ) ) }
						onChange={ ( next ) =>
							table.setPerPage( Number( next ) )
						}
					/>
				)
			}
		>
			{ list.error && (
				<Notice status="error" isDismissible={ false }>
					{ list.error }
				</Notice>
			) }
			{ list.items === null && ! list.error && (
				<SkeletonRows count={ 4 } />
			) }
			{ list.items !== null && (
				<DataTable
					columns={ COLUMNS }
					table={ table }
					filters={ FILTERS }
					labels={ LABELS }
					caption={ __( 'Exceptions', 'trustorder-for-woo' ) }
				/>
			) }
			{ list.next && (
				<div className="to-actions">
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isBusy={ list.isLoading }
						disabled={ list.isLoading }
						accessibleWhenDisabled
						onClick={ list.more }
					>
						{ __( 'Load more', 'trustorder-for-woo' ) }
					</Button>
				</div>
			) }
		</Section>
	);
}
