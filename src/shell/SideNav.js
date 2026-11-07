/**
 * WordPress dependencies
 */
import { useInstanceId } from '@wordpress/compose';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	Icon,
	chevronDown,
	chevronRight,
	external,
	help,
} from '@wordpress/icons';
import { Badge } from '@wordpress/ui';

/**
 * Internal dependencies
 */
import Mark from '../components/Mark';
import { VERSION } from '../data/boot';

/**
 * Full-height left sidebar: plugin header, quick search, hash links
 * (back/forward keep working) and the docs link. On mobile the list
 * collapses behind a "current section" toggle.
 *
 * @param {Object}  props
 * @param {Array}   props.pages   { id, label, icon }.
 * @param {string}  props.current Active page id.
 * @param {Object}  props.meta    Optional node per page id, after the label.
 * @param {Element} props.search  Quick search, under the plugin header.
 */
export default function SideNav( { pages, current, meta = {}, search } ) {
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
			{ search && <div className="to-sidebar__search">{ search }</div> }
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
			<div className="to-sidebar__foot">
				<a
					className="to-sidenav__item"
					href={
						/* translators: URL of the WordPress guide on docs.uptools.io; use the page in your language if there is one. */
						__(
							'https://docs.uptools.io/trustorder/wordpress/install/',
							'trustorder-for-woo'
						)
					}
					target="_blank"
					rel="noopener noreferrer"
				>
					<Icon icon={ help } size={ 20 } />
					<span className="to-sidenav__label">
						{ __( 'Documentation', 'trustorder-for-woo' ) }
					</span>
					<Icon icon={ external } size={ 16 } />
					<span className="screen-reader-text">
						{ __( '(opens in a new tab)', 'trustorder-for-woo' ) }
					</span>
				</a>
			</div>
		</aside>
	);
}
