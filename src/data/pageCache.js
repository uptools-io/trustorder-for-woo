/**
 * Results kept for the life of the page, so switching sections doesn't
 * reload (and re-skeleton) what was already shown.
 */
const cache = new Map();

export const cached = ( key ) => cache.get( key );

export const remember = ( key, value ) => {
	cache.set( key, value );
	return value;
};
