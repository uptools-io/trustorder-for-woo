<?php
/**
 * The outcome of one /check (or of failing open).
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

use LightweightPlugins\TrustOrder\Api\Response;

/**
 * Immutable checkout decision. A fail-open decision is always `allow`.
 */
final class Decision {

	public const ALLOW = 'allow';
	public const FLAG  = 'flag';
	public const BLOCK = 'block';

	public const VERDICTS = [ self::ALLOW, self::FLAG, self::BLOCK ];
	public const LEVELS   = [ 'trusted', 'unknown', 'risky', 'blocked' ];

	public const BAD_RESPONSE = 'BAD_RESPONSE';
	public const UNKNOWN      = 'UNKNOWN';

	private const TOKEN = '/^[A-Z0-9_]{1,40}$/D';
	private const ID    = '/^[0-9A-Za-z-]{1,64}$/D';

	/**
	 * Constructor.
	 *
	 * @param string      $verdict    allow|flag|block.
	 * @param string      $level      trusted|unknown|risky|blocked ('' when failed open).
	 * @param int         $score      0..100.
	 * @param string[]    $reasons    Reason codes.
	 * @param string      $check_id   Backend check id ('' when failed open).
	 * @param string|null $error_code Fail-open cause, null for a real verdict.
	 * @param string|null $fallback   Local fallback cause (no API verdict for this identity).
	 *
	 * @phpstan-param list<string> $reasons
	 */
	private function __construct(
		private string $verdict,
		private string $level,
		private int $score,
		private array $reasons,
		private string $check_id,
		private ?string $error_code,
		private ?string $fallback = null
	) {}

	/**
	 * From an API response; anything unexpected fails open.
	 *
	 * @param Response $r Response of POST /check.
	 */
	public static function fromResponse( Response $r ): self {
		if ( ! $r->ok() ) {
			return self::failOpen( (string) ( $r->errorCode() ?? 'HTTP_' . $r->status() ) );
		}

		return self::fromArray( $r->data() ) ?? self::failOpen( self::BAD_RESPONSE );
	}

	/**
	 * A decision that hides nothing and blocks nothing.
	 *
	 * @param string $code Error class (no personal data).
	 */
	public static function failOpen( string $code ): self {
		return new self( self::ALLOW, '', 0, [], '', 1 === preg_match( self::TOKEN, $code ) ? $code : self::UNKNOWN );
	}

	/**
	 * A local stand-in verdict when no check may run for this identity (a
	 * budget or cap is spent). Carries no other identity's details.
	 *
	 * @param string $verdict allow|flag|block.
	 * @param string $code    Cause (token).
	 */
	public static function fallback( string $verdict, string $code ): self {
		return new self(
			in_array( $verdict, self::VERDICTS, true ) ? $verdict : self::ALLOW,
			'',
			0,
			[],
			'',
			null,
			1 === preg_match( self::TOKEN, $code ) ? $code : self::UNKNOWN
		);
	}

	/**
	 * This verdict, as a fallback for another identity.
	 *
	 * @param string $code Cause (token).
	 */
	public function asFallback( string $code ): self {
		return self::fallback( $this->verdict, $code );
	}

	/**
	 * From a CheckResponse body or from toArray() output; null when malformed.
	 *
	 * @param array<mixed> $a Data.
	 */
	public static function fromArray( array $a ): ?self {
		if ( isset( $a['fallback'] ) && is_string( $a['fallback'] ) ) {
			return in_array( $a['verdict'] ?? null, self::VERDICTS, true ) ? self::fallback( (string) $a['verdict'], $a['fallback'] ) : null;
		}
		if ( ! empty( $a['failed_open'] ) ) {
			$code = isset( $a['error'] ) && is_string( $a['error'] ) && 1 === preg_match( self::TOKEN, $a['error'] ) ? $a['error'] : self::BAD_RESPONSE;

			return self::failOpen( $code );
		}

		$verdict = $a['verdict'] ?? null;
		$level   = $a['level'] ?? null;
		$score   = $a['score'] ?? null;
		$reasons = $a['reasons'] ?? null;
		$id      = $a['check_id'] ?? null;

		if ( ! in_array( $verdict, self::VERDICTS, true ) || ! in_array( $level, self::LEVELS, true ) ) {
			return null;
		}
		if ( ! is_int( $score ) || $score < 0 || $score > 100 || ! is_string( $id ) || 1 !== preg_match( self::ID, $id ) ) {
			return null;
		}
		if ( ! is_array( $reasons ) ) {
			return null;
		}
		foreach ( $reasons as $reason ) {
			if ( ! is_string( $reason ) || 1 !== preg_match( self::TOKEN, $reason ) ) {
				return null;
			}
		}

		return new self( $verdict, $level, $score, array_values( $reasons ), $id, null );
	}

	/**
	 * Verdict: allow|flag|block.
	 */
	public function verdict(): string {
		return $this->verdict;
	}

	/**
	 * Level: trusted|unknown|risky|blocked, '' when failed open.
	 */
	public function level(): string {
		return $this->level;
	}

	/**
	 * Score 0..100.
	 */
	public function score(): int {
		return $this->score;
	}

	/**
	 * Reason codes.
	 *
	 * @return list<string>
	 */
	public function reasons(): array {
		return $this->reasons;
	}

	/**
	 * Backend check id ('' when failed open).
	 */
	public function checkId(): string {
		return $this->check_id;
	}

	/**
	 * Whether this decision came from an error, not from the API.
	 */
	public function failedOpen(): bool {
		return null !== $this->error_code;
	}

	/**
	 * Local fallback cause, null for an API verdict or a fail-open.
	 */
	public function fallbackCode(): ?string {
		return $this->fallback;
	}

	/**
	 * Whether this is a local stand-in, not this identity's API verdict.
	 */
	public function isFallback(): bool {
		return null !== $this->fallback;
	}

	/**
	 * Fail-open cause (error class), null for a real verdict.
	 */
	public function errorCode(): ?string {
		return $this->error_code;
	}

	/**
	 * Serialisable form (cache, order meta).
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		if ( null !== $this->fallback ) {
			return [
				'verdict'  => $this->verdict,
				'fallback' => $this->fallback,
			];
		}
		if ( null !== $this->error_code ) {
			return [
				'verdict'     => self::ALLOW,
				'failed_open' => true,
				'error'       => $this->error_code,
			];
		}

		return [
			'verdict'  => $this->verdict,
			'level'    => $this->level,
			'score'    => $this->score,
			'reasons'  => $this->reasons,
			'check_id' => $this->check_id,
		];
	}
}
