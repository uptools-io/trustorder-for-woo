<?php
/**
 * Exception list items from the API, re-validated.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Rest;

/**
 * Keeps only id, kind, action, note and created_at of well-formed items.
 */
final class ExceptionItems {

	/**
	 * Clean list.
	 *
	 * @param array<mixed> $items Raw items.
	 * @return list<array{id: string, kind: string, action: string, note: string, created_at: string}>
	 */
	public static function clean( array $items ): array {
		$out = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item )
				|| ! is_string( $item['id'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{8}$/D', $item['id'] )
				|| ! in_array( $item['kind'] ?? null, IdentityInput::KINDS, true )
				|| ! in_array( $item['action'] ?? null, [ 'allow', 'deny' ], true )
				|| ! is_string( $item['created_at'] ?? null )
			) {
				continue;
			}
			$out[] = [
				'id'         => $item['id'],
				'kind'       => $item['kind'],
				'action'     => $item['action'],
				'note'       => is_string( $item['note'] ?? null ) ? sanitize_text_field( $item['note'] ) : '',
				'created_at' => sanitize_text_field( $item['created_at'] ),
			];
		}

		return $out;
	}
}
