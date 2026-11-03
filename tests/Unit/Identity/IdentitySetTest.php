<?php
/**
 * IdentitySet: order data -> wire hashes.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Tests\Unit\Identity;

use LightweightPlugins\TrustOrder\Identity\Hasher;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use PHPUnit\Framework\TestCase;

final class IdentitySetTest extends TestCase {

	/**
	 * A complete HU order.
	 *
	 * @param array<string, mixed> $over Overrides.
	 * @return array<string, mixed>
	 */
	private static function order( array $over = [] ): array {
		return array_merge(
			[
				'email'              => 'Foo.Bar+shop@GoogleMail.com',
				'phone'              => '06 30 123 4567',
				'billing_country'    => 'HU',
				'shipping_country'   => 'HU',
				'shipping_postcode'  => '1051',
				'shipping_address_1' => 'Petőfi Sándor u. 12/A 3. em. 5.',
				'shipping_address_2' => '',
				'needs_shipping'     => true,
				'skip_address'       => false,
			],
			$over
		);
	}

	public function test_full_order_builds_every_hash(): void {
		$set  = IdentitySet::fromOrderData( self::order() );
		$body = $set->toCheckBody();

		$this->assertTrue( $set->hasEmail() );
		$this->assertSame( 'gmail.com', $set->emailDomain() );
		$this->assertSame( '8cd79ce2224abde026ddc67563fe5edb8b5eb41846c63cd550a76ea76cc2eddd', $body['email_hash'] );
		$this->assertSame( Hasher::client( 'phone', '+36301234567' ), $body['phone_hash'] );
		$this->assertSame( Hasher::client( 'address', 'hu|1051|petofi sandor utca 12a' ), $body['address_hash'] );
		$this->assertSame( [ Hasher::client( 'domain', 'gmail.com' ) ], $body['email_domain_hashes'] );
		$this->assertSame( [ 'email_hash', 'email_domain_hashes', 'phone_hash', 'address_hash' ], array_keys( $body ) );
		foreach ( [ $body['email_hash'], $body['phone_hash'], $body['address_hash'] ] as $h ) {
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/D', $h );
		}
	}

	public function test_domain_hashes_cover_parent_suffixes(): void {
		$body = IdentitySet::fromOrderData( self::order( [ 'email' => 'x@a.b.mailinator.com' ] ) )->toCheckBody();

		$this->assertSame(
			[
				'351df6a1401bc8c3b39c83cc87b8e2d943134ee7071bf3e6115ba930263e601a',
				'04def0dee903c7855f7d210f97f7465be25b487856942e435e8753d5368e41b2',
				'd612e31dc021a25d209e94df9ba873a15ed02e08a48782ca56defc5b1710c8e6',
			],
			$body['email_domain_hashes']
		);
	}

	public function test_absent_identities_are_omitted_from_body(): void {
		$set  = IdentitySet::fromOrderData( self::order( [ 'phone' => 'abc', 'shipping_postcode' => '' ] ) );
		$body = $set->toCheckBody();

		$this->assertSame( [ 'email_hash', 'email_domain_hashes' ], array_keys( $body ) );
		$this->assertNull( $set->hashes()['phone_hash'] );
		$this->assertNull( $set->hashes()['address_hash'] );
	}

	public function test_invalid_email_gives_empty_body(): void {
		$set = IdentitySet::fromOrderData( self::order( [ 'email' => 'not-an-email' ] ) );

		$this->assertFalse( $set->hasEmail() );
		$this->assertNull( $set->emailDomain() );
		$this->assertSame( [], $set->toCheckBody() );
		$this->assertSame(
			[
				'email_hash'          => null,
				'phone_hash'          => Hasher::client( 'phone', '+36301234567' ),
				'address_hash'        => Hasher::client( 'address', 'hu|1051|petofi sandor utca 12a' ),
				'email_domain_hashes' => [],
			],
			$set->hashes()
		);
	}

	public function test_no_address_without_shipping_or_when_skipped(): void {
		$virtual = IdentitySet::fromOrderData( self::order( [ 'needs_shipping' => false ] ) );
		$pickup  = IdentitySet::fromOrderData( self::order( [ 'skip_address' => true ] ) );

		$this->assertNull( $virtual->hashes()['address_hash'] );
		$this->assertNull( $pickup->hashes()['address_hash'] );
		$this->assertNotNull( $pickup->hashes()['phone_hash'] );
	}

	public function test_phone_region_falls_back_to_shipping_country(): void {
		$billing  = IdentitySet::fromOrderData( self::order( [ 'phone' => '0722 123 456', 'billing_country' => 'RO', 'shipping_country' => 'HU' ] ) );
		$shipping = IdentitySet::fromOrderData( self::order( [ 'phone' => '0722 123 456', 'billing_country' => '', 'shipping_country' => 'RO' ] ) );

		$this->assertSame( Hasher::client( 'phone', '+40722123456' ), $billing->hashes()['phone_hash'] );
		$this->assertSame( Hasher::client( 'phone', '+40722123456' ), $shipping->hashes()['phone_hash'] );
	}

	public function test_missing_and_non_string_fields_are_tolerated(): void {
		$set = IdentitySet::fromOrderData(
			[
				'email'          => 'a@b.hu',
				'phone'          => [ 'x' ],
				'needs_shipping' => true,
			]
		);

		$this->assertSame( [ 'email_hash', 'email_domain_hashes' ], array_keys( $set->toCheckBody() ) );
		$this->assertSame( [], IdentitySet::fromOrderData( [] )->toCheckBody() );
	}

	public function test_cache_key_is_stable_and_identity_sensitive(): void {
		$a = IdentitySet::fromOrderData( self::order() )->cacheKey();
		$b = IdentitySet::fromOrderData( self::order( [ 'email' => 'foobar@gmail.com' ] ) )->cacheKey();
		$c = IdentitySet::fromOrderData( self::order( [ 'phone' => '+36 30 123 4568' ] ) )->cacheKey();
		$d = IdentitySet::fromOrderData( self::order( [ 'skip_address' => true ] ) )->cacheKey();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/D', $a );
		$this->assertSame( $a, $b, 'same normalised identity, same key' );
		$this->assertNotSame( $a, $c );
		$this->assertNotSame( $a, $d );
		$this->assertStringNotContainsString( 'gmail', $a );
	}
}
