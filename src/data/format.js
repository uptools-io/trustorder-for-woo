/**
 * WordPress dependencies
 */
import { dateI18n, getSettings } from '@wordpress/date';

/**
 * The site's locale (e.g. hu_HU) as a BCP 47 tag (hu-HU); undefined falls
 * back to the browser locale only when WordPress gives none or it is invalid.
 *
 * @return {string|undefined} Locale tag.
 */
function siteLocale() {
	const tag = ( getSettings().l10n?.locale || '' ).replace( /_/g, '-' );
	try {
		return tag ? Intl.getCanonicalLocales( tag )[ 0 ] : undefined;
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.warn( 'TrustOrder: invalid site locale', tag, error );
		return undefined;
	}
}

let number;

export const formatNumber = ( value ) => {
	number = number || new Intl.NumberFormat( siteLocale() );
	return number.format( value || 0 );
};

export const percent = ( part, whole ) =>
	whole > 0 ? Math.min( 100, Math.round( ( 100 * part ) / whole ) ) : 0;

/**
 * Site-formatted date and time.
 *
 * @param {string|number} value ISO string or unix seconds.
 * @return {string} Formatted value.
 */
export function formatDate( value ) {
	if ( ! value ) {
		return '';
	}
	const { formats } = getSettings();
	const date = typeof value === 'number' ? new Date( value * 1000 ) : value;
	return dateI18n( `${ formats.date } ${ formats.time }`, date );
}
