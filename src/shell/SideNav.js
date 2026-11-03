/**
 * WordPress dependencies
 */
import { useInstanceId } from '@wordpress/compose';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon, chevronDown, chevronRight } from '@wordpress/icons';
import { Badge } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import Mark from '../components/Mark';
import { VERSION } from '../data/boot';

/**
 * Full-height left sidebar with hash links (back/forward keep working). On
 * mobile the list collapses behind a "current section" toggle.
 *
 * @param {Object} props
 * @param {Array}  props.pages   { id, label, icon }.
 * @param {string} props.current Active page id.
 * @param {Object} props.meta    Optional node per page id, after the label.
 */
export default function SideNav( { pages, current, meta = {} } ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const navId = useInstanceId( SideNav, 'to-sidenav' );
	const active = pages.find( ( page ) => page.id === current );

	useEffect( () => setIsOpen( false ), [ current ] );

	return (
		<aside className={ `to-sidebar ${ isOpen ? 'is-open' : '' }` }>
			<div className="to-sidebar__head">
				<a
					className="to-sidebar__home"
					href={ `#${ pages[ 0 ].id }` }
					aria-label={ __(
						'TrustOrder overview',
						'trustorder-for-woo'
					) }
				>
					<Mark />
					<strong>TrustOrder</strong>
				</a>
				{ VERSION && (
					<Badge intent="informational">{ `v${ VERSION }` }</Badge>
				) }
			</div>
			<button
				type="button"
				className="to-sidebar__toggle"
				aria-expanded={ isOpen }
				aria-controls={ navId }
				onClick={ () => setIsOpen( ! isOpen ) }
			>
				<Icon icon={ active.icon } size={ 20 } />
				<span>{ active.label }</span>
				<Icon icon={ chevronDown } size={ 20 } />
			</button>
			<nav
				id={ navId }
				className="to-sidenav"
				aria-label={ __( 'TrustOrder sections', 'trustorder-for-woo' ) }
			>
				<ul>
					{ pages.map( ( page ) => {
						const isCurrent = page.id === current;
						return (
							<li key={ page.id }>
								<a
									href={ `#${ page.id }` }
									className="to-sidenav__item"
									aria-current={
										isCurrent ? 'page' : undefined
									}
								>
									<Icon icon={ page.icon } size={ 20 } />
									<span className="to-sidenav__label">
										{ page.label }
									</span>
									{ meta[ page.id ] && (
										<span className="to-sidenav__meta">
											{ meta[ page.id ] }
										</span>
									) }
									{ isCurrent && (
										<Icon
											icon={ chevronRight }
											size={ 18 }
										/>
									) }
								</a>
							</li>
						);
					} ) }
				</ul>
			</nav>
		</aside>
	);
}
