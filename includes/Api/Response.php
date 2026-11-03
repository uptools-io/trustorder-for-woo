<?php
/**
 * TrustOrder API response.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Api;

/**
 * Immutable result of one API call.
 *
 * `errorCode()` is the problem+json `code` for API errors, or one of the
 * client-side codes: `NETWORK`, `TIMEOUT`, `BAD_JSON`, `NO_KEY`.
 */
final class Response {

	public const NETWORK  = 'NETWORK';
	public const TIMEOUT  = 'TIMEOUT';
	public const BAD_JSON = 'BAD_JSON';
	public const NO_KEY   = 'NO_KEY';

	private const RETRYABLE_STATUS = [ 429, 503 ];
	private const RETRYABLE_CODES  = [ self::NETWORK, self::TIMEOUT ];

	/**
	 * HTTP status (0 when no response arrived).
	 *
	 * @var int
	 */
	private int $status;

	/**
	 * Decoded JSON body (empty on errors without a body).
	 *
	 * @var array<mixed>
	 */
	private array $data;

	/**
	 * Error code or null on success.
	 *
	 * @var string|null
	 */
	private ?string $error_code;

	/**
	 * Constructor.
	 *
	 * @param int          $status     HTTP status (0 = no response).
	 * @param array<mixed> $data       Decoded body.
	 * @param string|null  $error_code Error code, null on success.
	 */
	public function __construct( int $status, array $data, ?string $error_code ) {
		$this->status     = $status;
		$this->data       = $data;
		$this->error_code = $error_code;
	}

	/**
	 * Build from an HTTP status and raw body.
	 *
	 * A 204 (or another non-200 2xx with an empty body) has no data. A 200 or
	 * any body that isn't a JSON object/array is `BAD_JSON`, as is an error
	 * response without a problem+json `code`.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Raw body.
	 * @return self
	 */
	public static function fromHttp( int $status, string $body ): self {
		$ok = $status >= 200 && $status < 300;

		if ( 204 === $status || ( $ok && '' === $body && 200 !== $status ) ) {
			return new self( $status, [], null );
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new self( $status, [], self::BAD_JSON );
		}

		if ( $ok ) {
			return new self( $status, $data, null );
		}

		if ( ! isset( $data['code'] ) || ! is_string( $data['code'] ) || '' === $data['code'] ) {
			return new self( $status, [], self::BAD_JSON );
		}

		return new self( $status, $data, $data['code'] );
	}

	/**
	 * A failure with no HTTP response.
	 *
	 * @param string $code Client-side error code.
	 * @return self
	 */
	public static function failure( string $code ): self {
		return new self( 0, [], $code );
	}

	/**
	 * Whether the call succeeded (2xx with a usable body).
	 *
	 * @return bool
	 */
	public function ok(): bool {
		return null === $this->error_code && $this->status >= 200 && $this->status < 300;
	}

	/**
	 * HTTP status, 0 when no response arrived.
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Decoded JSON body.
	 *
	 * @return array<mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	/**
	 * Error code, null on success.
	 *
	 * @return string|null
	 */
	public function errorCode(): ?string {
		return $this->error_code;
	}

	/**
	 * Whether a background job should retry (429, 503, network, timeout).
	 *
	 * @return bool
	 */
	public function retryable(): bool {
		return in_array( $this->status, self::RETRYABLE_STATUS, true )
			|| in_array( $this->error_code, self::RETRYABLE_CODES, true );
	}
}
