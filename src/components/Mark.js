/**
 * WordPress dependencies
 */
import { SVG, Path } from '@wordpress/primitives';

/**
 * Decorative TrustOrder mark: a shield with a check, on the yellow tile.
 */
export default function Mark() {
	return (
		<span className="to-mark" aria-hidden="true">
			<SVG
				viewBox="0 0 24 24"
				xmlns="http://www.w3.org/2000/svg"
				focusable="false"
			>
				<Path d="M12 2 4 5v6c0 5.1 3.4 9.8 8 11 4.6-1.2 8-5.9 8-11V5l-8-3Zm-1.2 14.2-3.5-3.5 1.4-1.4 2.1 2.1 4.9-4.9 1.4 1.4-6.3 6.3Z" />
			</SVG>
		</span>
	);
}
