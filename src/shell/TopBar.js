/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { displayShortcut } from '@wordpress/keycodes';

const IS_MAC = /Mac|iPhone|iPad/.test( window.navigator.platform || '' );

/**
 * Main column header: page title left; Discard / Save on pages that edit
 * settings.
 *
 * @param {Object}  props
 * @param {string}  props.title Page title.
 * @param {Object}  props.store useSettings() result.
 * @param {boolean} props.saves Whether this page edits settings.
 */
export default function TopBar( { title, store, saves } ) {
	return (
		<header className="to-topbar">
			<h1 className="to-topbar__title" tabIndex={ -1 }>
				{ title }
			</h1>
			{ saves && (
				<div className="to-topbar__actions">
					{ store.hasEdits && (
						<>
							<span className="to-topbar__dirty">
								{ __(
									'Unsaved changes',
									'trustorder-for-woo'
								) }
							</span>
							<Button
								__next40pxDefaultSize
								variant="tertiary"
								onClick={ store.discard }
							>
								{ __( 'Discard', 'trustorder-for-woo' ) }
							</Button>
						</>
					) }
					<Button
						__next40pxDefaultSize
						variant="primary"
						className="to-save"
						isBusy={ store.isSaving }
						disabled={ ! store.hasEdits || store.isSaving }
						accessibleWhenDisabled
						aria-keyshortcuts={ IS_MAC ? 'Meta+S' : 'Control+S' }
						onClick={ () => store.save() }
					>
						<kbd aria-hidden="true">
							{ displayShortcut.primary( 's' ) }
						</kbd>
						{ __( 'Save', 'trustorder-for-woo' ) }
					</Button>
				</div>
			) }
		</header>
	);
}
