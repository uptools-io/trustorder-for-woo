<?php
/**
 * Suggested privacy policy text.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Admin;

/**
 * Adds a section to Settings → Privacy → Policy guide. English source text;
 * the Hungarian version comes from the plugin translation.
 */
final class Privacy {

	/**
	 * Hook in.
	 */
	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'add' ] );
	}

	/**
	 * `admin_init` callback.
	 */
	public static function add(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'TrustOrder for WooCommerce', 'trustorder-for-woo' ), wp_kses_post( self::content() ) );
		}
	}

	/**
	 * Policy text (HTML paragraphs).
	 */
	public static function content(): string {
		$paragraphs = [
			__( 'To decide whether cash on delivery can be offered, the webshop uses the TrustOrder service of upTools Kft. (uptools.io), shared by the participating webshops.', 'trustorder-for-woo' ),
			__( 'When the checkout is filled in, the webshop sends one-way hashes of the buyer\'s e-mail address, phone number and shipping address, hashes of the e-mail domain, and whether the e-mail domain can receive mail. Names and the readable values are never sent.', 'trustorder-for-woo' ),
			__( 'When an order is completed or marked as not picked up, the webshop sends the outcome (picked up or not picked up), its date, the webshop\'s internal order number (order ID) and the same hashes. When the webshop starts the one-time back-loading, it may also report the completed and not picked up orders of the past 3 years in the same way.', 'trustorder-for-woo' ),
			__( 'Purpose: assessing the risk of cash-on-delivery orders from the delivery history (picked up or not) of the same buyer across the participating webshops. Based on the result the webshop may hide cash on delivery; other payment methods stay available.', 'trustorder-for-woo' ),
			__( 'Legal basis: the legitimate interest of the webshop and the participating webshops in preventing losses from parcels that are not picked up (GDPR Article 6(1)(f)).', 'trustorder-for-woo' ),
			__( 'The webshop is the contact point for this processing: questions and objections go to the webshop. The service keeps the hashed delivery outcomes for 3 years, then deletes them.', 'trustorder-for-woo' ),
		];

		return '<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>';
	}
}
