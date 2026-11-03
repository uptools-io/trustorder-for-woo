<?php
/**
 * Decides a checkout: memo, cache, quota, then POST /check.
 *
 * @package LightweightPlugins\TrustOrder
 */

declare(strict_types=1);

namespace LightweightPlugins\TrustOrder\Checkout;

use LightweightPlugins\TrustOrder\Api\ApiKey;
use LightweightPlugins\TrustOrder\Api\Client;
use LightweightPlugins\TrustOrder\Api\Response;
use LightweightPlugins\TrustOrder\Identity\IdentitySet;
use LightweightPlugins\TrustOrder\Identity\MxCache;
use LightweightPlugins\TrustOrder\Log;
use LightweightPlugins\TrustOrder\Settings;

/**
 * Never throws and never blocks on error: every failure is Decision::failOpen().
 * Failures are memoised for the request too, so a timeout costs 1.5 s once.
 * The session memory of this identity is reused while younger than the
 * result cache TTL; older, only when the budget is spent. Otherwise see
 * fallbackFor(). Fail-open at place order only on API errors;
 * only an outage (isOutage()) gives the place-order unit back.
 */
final class Checker {

	public const NO_EMAIL      = 'NO_EMAIL';
	public const SESSION_QUOTA = QuotaGuard::SESSION_QUOTA;
	public const EXCEPTION     = 'EXCEPTION';

	/**
	 * Request-wide instance.
	 *
	 * @var Checker|null
	 */
	private static ?Checker $current = null;

	/**
	 * Decisions of this request by cache key.
	 *
	 * @var array<string, Decision>
	 */
	private array $memo = [];

	/**
	 * Budget-exhausted (fallback) decisions of this request, browsing only.
	 *
	 * @var array<string, Decision>
	 */
	private array $fallback = [];

	/**
	 * Constructor.
	 *
	 * @param Client $client      API client.
	 * @param string $env         live|test ('' = no usable key).
	 * @param string $session_key Quota session key.
	 */
	public function __construct(
		private Client $client,
		private string $env,
		private string $session_key
	) {}

	/**
	 * The request's checker (stored key, current session).
	 */
	public static function current(): self {
		if ( null === self::$current ) {
			self::$current = new self( Client::fromSettings(), (string) ApiKey::env( Settings::get()['api_key'] ), SessionKey::current() );
		}

		return self::$current;
	}

	/**
	 * Forget the request instance (tests, long-running processes).
	 */
	public static function reset(): void {
		self::$current = null;
	}

	/**
	 * Key environment.
	 */
	public function env(): string {
		return $this->env;
	}

	/**
	 * Decide while browsing the checkout (browsing budget).
	 *
	 * @param IdentitySet $ids        Identity hashes.
	 * @param string      $strictness lenient|normal|strict.
	 */
	public function decide( IdentitySet $ids, string $strictness ): Decision {
		return $this->resolve( $ids, $strictness, false );
	}

	/**
	 * Decide at place order: unless this exact identity is already known, one
	 * real check from the separate submit budget.
	 *
	 * @param IdentitySet $ids        Identity hashes.
	 * @param string      $strictness lenient|normal|strict.
	 */
	public function decideForSubmit( IdentitySet $ids, string $strictness ): Decision {
		return $this->resolve( $ids, $strictness, true );
	}

	/**
	 * Memoised or cached decision only; never calls the API.
	 *
	 * @param IdentitySet $ids        Identity hashes.
	 * @param string      $strictness lenient|normal|strict.
	 */
	public function peek( IdentitySet $ids, string $strictness ): ?Decision {
		if ( ! $ids->hasEmail() || '' === $this->env ) {
			return null;
		}
		$key = $this->memoKey( $ids, $strictness );
		if ( isset( $this->memo[ $key ] ) ) {
			return $this->memo[ $key ];
		}
		$cached = ResultCache::get( $this->env, $ids->cacheKey(), $strictness );
		if ( null !== $cached ) {
			$this->memo[ $key ] = $cached;
		}

		return $cached;
	}

	/**
	 * Memo, cache, same-identity session memory, budgets, then POST /check.
	 *
	 * @param IdentitySet $ids        Identity hashes.
	 * @param string      $strictness lenient|normal|strict.
	 * @param bool        $submit     Place-order budget.
	 */
	private function resolve( IdentitySet $ids, string $strictness, bool $submit ): Decision {
		try {
			if ( ! $ids->hasEmail() ) {
				return Decision::failOpen( self::NO_EMAIL );
			}
			if ( '' === $this->env ) {
				return Decision::failOpen( Response::NO_KEY );
			}
			$known = $this->peek( $ids, $strictness );
			if ( null !== $known ) {
				return $known;
			}
			$key   = $this->memoKey( $ids, $strictness );
			$fresh = SessionMemory::get( $this->session_key, $key, ResultCache::TTL );
			if ( null !== $fresh ) {
				$this->memo[ $key ] = $fresh;
				return $fresh;
			}
			$slot = $key . ( $submit ? '|submit' : '|browse' );
			if ( isset( $this->fallback[ $slot ] ) ) {
				return $this->fallback[ $slot ];
			}

			$taken  = [];
			$denied = $submit ? QuotaGuard::submit( $this->session_key, $taken ) : QuotaGuard::browse( $this->session_key );
			if ( null !== $denied ) {
				// Budget spent: an older answer for this very identity beats a stand-in.
				$seen = SessionMemory::get( $this->session_key, $key );
				if ( null !== $seen ) {
					$this->memo[ $key ] = $seen;
					return $seen;
				}
				$this->fallback[ $slot ] = $this->fallbackFor( $denied, $submit );

				return $this->fallback[ $slot ];
			}

			$outage   = false;
			$decision = $this->ask( $ids, $strictness, $key, $outage );
			if ( $submit && $outage ) {
				QuotaGuard::refundSubmit( $this->session_key, $taken );
			}

			$this->memo[ $key ] = $decision;

			return $decision;
		} catch ( \Throwable $e ) {
			Log::error( 'checkout', [ 'code' => self::EXCEPTION ] );

			return Decision::failOpen( self::EXCEPTION );
		}
	}

	/**
	 * Stand-in when a budget or cap denies a check for an unseen identity.
	 * Place order: `block` (the hide rule still keeps a way to pay), never
	 * fail-open, so spending the submit budget cannot open the hidden gateway.
	 * Browsing: the session's strictest verdict, else fail-open.
	 *
	 * @param string $code   Denial code.
	 * @param bool   $submit Place order.
	 */
	private function fallbackFor( string $code, bool $submit ): Decision {
		if ( $submit ) {
			return Decision::fallback( Decision::BLOCK, $code );
		}
		$strictest = SessionMemory::strictest( $this->session_key );

		return null === $strictest ? Decision::failOpen( $code ) : $strictest->asFallback( $code );
	}

	/**
	 * POST /check; caches and remembers a real verdict.
	 *
	 * @param IdentitySet $ids        Identity hashes.
	 * @param string      $strictness lenient|normal|strict.
	 * @param string      $key        Identity key.
	 * @param bool        $outage     Out: a transport or server outage.
	 */
	private function ask( IdentitySet $ids, string $strictness, string $key, bool &$outage ): Decision {
		$domain = (string) $ids->emailDomain();
		$mx     = MxCache::get( $domain );
		if ( null === $mx ) {
			MxCache::schedule( $domain );
		}

		$body                    = $ids->toCheckBody();
		$body['email_domain_mx'] = $mx;
		$body['strictness']      = $strictness;

		$response = $this->client->check( $body );
		$outage   = self::isOutage( $response );
		$decision = Decision::fromResponse( $response );
		if ( Decision::BAD_RESPONSE === $decision->errorCode() ) {
			Log::error( 'checkout', [ 'code' => Decision::BAD_RESPONSE ] );
		}
		if ( ! $decision->failedOpen() ) {
			ResultCache::put( $this->env, $ids->cacheKey(), $strictness, $decision );
			SessionMemory::remember( $this->session_key, $key, $decision );
		}

		return $decision;
	}

	/**
	 * Transport failure (NETWORK, TIMEOUT) or an HTTP 5xx (any body, BAD_JSON
	 * included). Not 4xx (401/402/403/429…) and not a 2xx with a bad shape:
	 * those keep the place-order unit spent.
	 *
	 * @param Response $r Response of POST /check.
	 */
	private static function isOutage( Response $r ): bool {
		if ( 0 === $r->status() ) {
			return in_array( $r->errorCode(), [ Response::NETWORK, Response::TIMEOUT ], true );
		}

		return $r->status() >= 500 && $r->status() < 600;
	}

	/**
	 * Memo key.
	 *
	 * @param IdentitySet $ids        Identity hashes.
	 * @param string      $strictness Strictness.
	 */
	private function memoKey( IdentitySet $ids, string $strictness ): string {
		return ResultCache::key( $this->env, $ids->cacheKey(), $strictness );
	}
}
