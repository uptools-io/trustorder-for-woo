<?php
/**
 * Runs the identity normalisers as if intl were missing. NoIntlTest starts it
 * with `-d disable_functions=idn_to_ascii,normalizer_normalize` and reads the
 * JSON it prints. Every PHP warning/notice is collected and reported.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

// phpcs:ignoreFile -- Test-only probe script.

define( 'ABSPATH', __DIR__ . '/' );

$errors = [];
set_error_handler(
	static function ( int $no, string $msg ) use ( &$errors ): bool {
		$errors[] = $msg;
		return true;
	}
);

require __DIR__ . '/WpStubs.php';
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require dirname( __DIR__, 2 ) . '/vendor-prefixed/autoload.php';

use LightweightPlugins\TrustOrder\Identity\Address;
use LightweightPlugins\TrustOrder\Identity\Domain;
use LightweightPlugins\TrustOrder\Identity\Email;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use LightweightPlugins\TrustOrder\Identity\Phone;
use LightweightPlugins\TrustOrder\Identity\Unicode;

$email = static fn( string $s ): ?string => Email::normalize( $s )['norm'] ?? null;

$out = [
	'full'            => Unicode::full(),
	'email_ascii'     => $email( ' John.Doe+x@Gmail.COM ' ),
	'email_nbsp'      => $email( "\u{00A0}A@b.hu\u{3000}" ),
	'email_inner_sp'  => $email( "a\u{00A0}@b.hu" ),
	'domain_nbsp'     => Domain::normalize( "\u{2000}Example.com\u{00A0}" ),
	'address_nbsp'    => Address::normalize( "HU\u{00A0}", "1051\u{00A0}", "Kossuth\u{00A0}u. 3", '' ),
	'email_idn'       => $email( 'user@bücher.de' ),
	'email_local_utf' => $email( 'ördög@example.hu' ),
	'domain_ascii'    => Domain::normalize( 'Example.COM' ),
	'domain_puny'     => Domain::normalize( 'xn--bcher-kva.de' ),
	'domain_under'    => Domain::normalize( 'a_b.com' ),
	'domain_hyphen'   => Domain::normalize( 'ab--c.com' ),
	'phone'           => Phone::normalize( '06 30 123 4567', 'HU' ),
	'address_ascii'   => Address::normalize( 'HU', 'H-1051', 'Kossuth u. 3. B 2/5', '' ),
	'address_utf'     => Address::normalize( 'HU', '1051', 'Fő út 3', '' ),
	'set_body_keys'   => array_keys(
		IdentitySet::fromOrderData(
			[
				'email'              => 'kovacs@pelda.hu',
				'phone'              => '06 30 123 4567',
				'billing_country'    => 'HU',
				'shipping_country'   => 'HU',
				'shipping_postcode'  => '1051',
				'shipping_address_1' => 'Fő út 3',
				'needs_shipping'     => true,
			]
		)->toCheckBody()
	),
	'set_utf_email'   => IdentitySet::fromOrderData( [ 'email' => 'ördög@pelda.hu' ] )->toCheckBody(),
];
$out['errors'] = $errors;

echo json_encode( $out, JSON_UNESCAPED_UNICODE );
