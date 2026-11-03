<?php
/**
 * The hashed identities of one order / checkout.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Identity;

/**
 * Builds the protocol v1 client hashes from raw order fields. Raw values are
 * never kept on the object, only hashes (and the punycode e-mail domain for
 * the local MX check).
 */
final class IdentitySet {

	/**
	 * Wire hashes.
	 *
	 * @param string|null $email_hash    E-mail hash.
	 * @param string[]    $domain_hashes Domain + parent suffix hashes.
	 * @param string|null $phone_hash    Phone hash.
	 * @param string|null $address_hash  Address hash.
	 * @param string|null $domain        Normalised e-mail domain.
	 *
	 * @phpstan-param list<string> $domain_hashes
	 */
	private function __construct(
		private ?string $email_hash,
		private array $domain_hashes,
		private ?string $phone_hash,
		private ?string $address_hash,
		private ?string $domain
	) {}

	/**
	 * From order/checkout data.
	 *
	 * Keys: email, phone, billing_country, shipping_country, shipping_postcode,
	 * shipping_address_1, shipping_address_2 (strings), needs_shipping,
	 * skip_address (bool). Missing or non-string values count as empty.
	 *
	 * @param array<string, mixed> $d Order data.
	 */
	public static function fromOrderData( array $d ): self {
		$s = static fn( string $k ): string => isset( $d[ $k ] ) && is_string( $d[ $k ] ) ? $d[ $k ] : '';

		$email         = Email::normalize( $s( 'email' ) );
		$domain_hashes = [];
		if ( null !== $email ) {
			foreach ( Domain::suffixes( $email['domain'] ) as $suffix ) {
				$domain_hashes[] = Hasher::client( 'domain', $suffix );
			}
		}

		$region = '' !== Unicode::trim( $s( 'billing_country' ) ) ? $s( 'billing_country' ) : $s( 'shipping_country' );
		$phone  = Phone::normalize( $s( 'phone' ), $region );

		$address = null;
		if ( ! empty( $d['needs_shipping'] ) && empty( $d['skip_address'] ) ) {
			$address = Address::normalize( $s( 'shipping_country' ), $s( 'shipping_postcode' ), $s( 'shipping_address_1' ), $s( 'shipping_address_2' ) );
		}

		return new self(
			null === $email ? null : Hasher::client( 'email', $email['norm'] ),
			$domain_hashes,
			null === $phone ? null : Hasher::client( 'phone', $phone ),
			null === $address ? null : Hasher::client( 'address', $address ),
			null === $email ? null : $email['domain']
		);
	}

	/**
	 * Whether a valid e-mail is present (required for /check).
	 */
	public function hasEmail(): bool {
		return null !== $this->email_hash;
	}

	/**
	 * Normalised (punycode) e-mail domain, for the local MX check.
	 */
	public function emailDomain(): ?string {
		return $this->domain;
	}

	/**
	 * All hashes with a stable shape (for order meta).
	 *
	 * @return array{email_hash: string|null, phone_hash: string|null, address_hash: string|null, email_domain_hashes: list<string>}
	 */
	public function hashes(): array {
		return [
			'email_hash'          => $this->email_hash,
			'phone_hash'          => $this->phone_hash,
			'address_hash'        => $this->address_hash,
			'email_domain_hashes' => $this->domain_hashes,
		];
	}

	/**
	 * Identity part of the /check body; absent identities are omitted, and
	 * without a valid e-mail the body is empty (no request must be sent).
	 *
	 * @return array<string, string|list<string>>
	 */
	public function toCheckBody(): array {
		if ( null === $this->email_hash ) {
			return [];
		}
		$body = [
			'email_hash'          => $this->email_hash,
			'email_domain_hashes' => $this->domain_hashes,
		];
		if ( null !== $this->phone_hash ) {
			$body['phone_hash'] = $this->phone_hash;
		}
		if ( null !== $this->address_hash ) {
			$body['address_hash'] = $this->address_hash;
		}

		return $body;
	}

	/**
	 * Hash of the identity hashes (for result caching; env/strictness are
	 * the caller's to add).
	 */
	public function cacheKey(): string {
		$h = $this->hashes();

		return hash(
			'sha256',
			implode(
				'|',
				[
					(string) $h['email_hash'],
					(string) $h['phone_hash'],
					(string) $h['address_hash'],
					implode( ',', $h['email_domain_hashes'] ),
				]
			)
		);
	}
}
