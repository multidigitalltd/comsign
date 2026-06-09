<?php
/**
 * Subscription plan registry.
 *
 * @package ComSign
 */

namespace ComSign\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the commercial subscription plans (data-driven and filterable, so
 * prices/limits can be tuned without code changes). Every paid plan carries a
 * free trial; limits gate usage and features gate capabilities.
 *
 * Limit value 0 means "unlimited".
 */
final class Plans {

	/** Free-trial length, in days. */
	public const TRIAL_DAYS = 30;

	// Feature flags a plan may grant.
	public const FEATURE_PKI      = 'pki';       // PKI/PAdES cryptographic signing.
	public const FEATURE_BRANDING = 'branding';  // Custom logo/colour on the signing page.
	public const FEATURE_API      = 'api';       // REST API access.
	public const FEATURE_WEBHOOKS = 'webhooks';  // Outgoing webhooks.

	// Limit keys.
	public const LIMIT_DOCS_PER_MONTH = 'docs_per_month';
	public const LIMIT_SEATS          = 'seats';
	public const LIMIT_STORAGE_MB     = 'storage_mb';
	public const LIMIT_CONTACTS       = 'contacts';

	/**
	 * Currency the prices are expressed in (ISO 4217).
	 */
	public static function currency(): string {
		return (string) apply_filters( 'comsign_plan_currency', 'ILS' );
	}

	/**
	 * The full plan catalogue, keyed by plan id (cheapest first).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$plans = array(
			'solo'       => array(
				'id'         => 'solo',
				'name'       => __( 'Solo', 'comsign' ),
				'price'      => array( 'monthly' => 49, 'annual' => 490 ),
				'limits'     => array(
					self::LIMIT_DOCS_PER_MONTH => 20,
					self::LIMIT_SEATS          => 1,
					self::LIMIT_STORAGE_MB     => 1000,
					self::LIMIT_CONTACTS       => 100,
				),
				'features'   => array(),
			),
			'business'   => array(
				'id'         => 'business',
				'name'       => __( 'Business', 'comsign' ),
				'price'      => array( 'monthly' => 149, 'annual' => 1490 ),
				'limits'     => array(
					self::LIMIT_DOCS_PER_MONTH => 200,
					self::LIMIT_SEATS          => 5,
					self::LIMIT_STORAGE_MB     => 10000,
					self::LIMIT_CONTACTS       => 2000,
				),
				'features'   => array( self::FEATURE_PKI, self::FEATURE_BRANDING ),
			),
			'enterprise' => array(
				'id'         => 'enterprise',
				'name'       => __( 'Enterprise', 'comsign' ),
				'price'      => array( 'monthly' => 499, 'annual' => 4990 ),
				'limits'     => array(
					self::LIMIT_DOCS_PER_MONTH => 0, // unlimited
					self::LIMIT_SEATS          => 25,
					self::LIMIT_STORAGE_MB     => 100000,
					self::LIMIT_CONTACTS       => 0, // unlimited
				),
				'features'   => array( self::FEATURE_PKI, self::FEATURE_BRANDING, self::FEATURE_API, self::FEATURE_WEBHOOKS ),
			),
		);

		/**
		 * Filter the plan catalogue.
		 *
		 * @param array $plans Plans keyed by id.
		 */
		return (array) apply_filters( 'comsign_plans', $plans );
	}

	/**
	 * Whether a plan id exists.
	 */
	public static function exists( string $plan_id ): bool {
		return array_key_exists( $plan_id, self::all() );
	}

	/**
	 * Get a single plan definition (or null).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get( string $plan_id ): ?array {
		$all = self::all();
		return $all[ $plan_id ] ?? null;
	}

	/**
	 * The default plan a new account starts its trial on (the entry tier).
	 */
	public static function default_id(): string {
		$ids = array_keys( self::all() );
		$default = $ids[0] ?? 'solo';
		return (string) apply_filters( 'comsign_default_plan', $default );
	}

	/**
	 * A plan's limit for a given key (0 = unlimited). Missing => 0.
	 *
	 * @param string $plan_id Plan id.
	 * @param string $key     One of the LIMIT_* constants.
	 */
	public static function limit( string $plan_id, string $key ): int {
		$plan = self::get( $plan_id );
		return $plan ? (int) ( $plan['limits'][ $key ] ?? 0 ) : 0;
	}

	/**
	 * Whether a plan grants a feature.
	 *
	 * @param string $plan_id Plan id.
	 * @param string $feature One of the FEATURE_* constants.
	 */
	public static function has_feature( string $plan_id, string $feature ): bool {
		$plan = self::get( $plan_id );
		return $plan ? in_array( $feature, (array) ( $plan['features'] ?? array() ), true ) : false;
	}

	/**
	 * A plan's price for a billing cycle ('monthly'|'annual').
	 */
	public static function price( string $plan_id, string $cycle ): float {
		$plan = self::get( $plan_id );
		if ( ! $plan ) {
			return 0.0;
		}
		$cycle = 'annual' === $cycle ? 'annual' : 'monthly';
		return (float) ( $plan['price'][ $cycle ] ?? 0 );
	}
}
