/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	SkeletonBlock,
	SkeletonRegion,
	SkeletonRows,
	SkeletonSection,
	SkeletonText,
	SkeletonTiles,
} from '.';

/**
 * First paint: the same sidebar + top bar + content frame as the app.
 */
export default function AppSkeleton() {
	return (
		<SkeletonRegion
			label={ __( 'Loading TrustOrder…', 'trustorder-for-woo' ) }
			className="to-shell"
		>
			<aside className="to-sidebar">
				<div className="to-sidebar__head">
					<SkeletonBlock width={ 30 } height={ 30 } />
					<SkeletonText width={ 100 } size="lg" />
				</div>
				<span className="lw-skel-stack lw-skel-nav">
					{ Array.from( { length: 4 }, ( _, index ) => (
						<SkeletonText
							key={ index }
							width={ `${ 55 + ( ( index * 13 ) % 35 ) }%` }
						/>
					) ) }
				</span>
			</aside>
			<div className="to-main">
				<div className="to-topbar">
					<SkeletonText width={ 180 } size="lg" />
				</div>
				<div className="to-scroll">
					<div className="to-content">
						<SkeletonSection description={ false }>
							<SkeletonTiles count={ 3 } />
						</SkeletonSection>
						<SkeletonSection description={ false }>
							<SkeletonRows count={ 5 } />
						</SkeletonSection>
					</div>
				</div>
			</div>
		</SkeletonRegion>
	);
}
