<?php
/**
 * Payment-gateway contract.
 *
 * @package ComSign
 */

namespace ComSign\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * A hosted-checkout payment gateway. Implemented by {@see CardcomGateway} and a
 * test double, so {@see \ComSign\Services\BillingService} is provider-agnostic
 * and unit-testable.
 */
interface GatewayInterface {

	/**
	 * Create a hosted checkout page.
	 *
	 * @param array $args amount, currency, product_name, return_value,
	 *                    success_url, failed_url, webhook_url, language,
	 *                    create_token (bool).
	 *
	 * @return array{ok:bool,url:string,reference:string,error:string}
	 */
	public function create_checkout( array $args ): array;

	/**
	 * Verify a completed checkout server-to-server (never trust the browser
	 * redirect alone).
	 *
	 * @param string $reference Gateway reference (e.g. Cardcom LowProfileId).
	 *
	 * @return array{ok:bool,paid:bool,return_value:string,token:string,amount:float,currency:string,error:string}
	 */
	public function verify_transaction( string $reference ): array;

	/**
	 * Charge a stored recurring-billing token (for renewals).
	 *
	 * @param array $args token, amount, currency, product_name.
	 *
	 * @return array{ok:bool,paid:bool,error:string}
	 */
	public function charge_token( array $args ): array;
}
