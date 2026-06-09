<?php
/**
 * Cardcom (Israeli) payment gateway — v11 Low Profile hosted checkout.
 *
 * @package ComSign
 */

namespace ComSign\Billing;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Cardcom's v11 JSON API: creates a Low Profile hosted payment page
 * (charging the first period and tokenising the card for recurring billing)
 * and verifies the result server-to-server.
 *
 * The request-building and response-parsing logic is kept pure (and public) so
 * it can be unit-tested without network access; only {@see request()} performs
 * HTTP and is overridden by the test double.
 */
class CardcomGateway implements GatewayInterface {

	/** Cardcom ISO coin id for ILS. */
	private const COIN_ILS = 1;

	public function create_checkout( array $args ): array {
		if ( ! CardcomSettings::is_configured() ) {
			return array( 'ok' => false, 'url' => '', 'reference' => '', 'error' => __( 'Cardcom is not configured.', 'comsign' ) );
		}

		$response = $this->request( '/LowProfile/Create', $this->build_create_payload( $args ) );
		return $this->parse_create_response( $response );
	}

	public function verify_transaction( string $reference ): array {
		if ( '' === $reference || ! CardcomSettings::is_configured() ) {
			return $this->verify_failure( __( 'Missing transaction reference.', 'comsign' ) );
		}

		$response = $this->request(
			'/LowProfile/GetLpResult',
			array(
				'TerminalNumber' => CardcomSettings::terminal(),
				'ApiName'        => CardcomSettings::api_name(),
				'LowProfileId'   => $reference,
			)
		);
		return $this->parse_verify_response( $response );
	}

	public function charge_token( array $args ): array {
		if ( ! CardcomSettings::is_configured() ) {
			return array( 'ok' => false, 'paid' => false, 'error' => __( 'Cardcom is not configured.', 'comsign' ) );
		}
		$token = (string) ( $args['token'] ?? '' );
		if ( '' === $token ) {
			return array( 'ok' => false, 'paid' => false, 'error' => __( 'Missing billing token.', 'comsign' ) );
		}

		$response = $this->request(
			'/Transactions/Transaction',
			array(
				'TerminalNumber' => CardcomSettings::terminal(),
				'ApiName'        => CardcomSettings::api_name(),
				'Amount'         => round( (float) ( $args['amount'] ?? 0 ), 2 ),
				'ISOCoinId'      => self::COIN_ILS,
				'ProductName'    => mb_substr( (string) ( $args['product_name'] ?? 'ComSign' ), 0, 250 ),
				'Token'          => $token,
			)
		);
		return $this->parse_charge_response( $response );
	}

	/* ---------------------------------------------------------------------
	 * Pure logic (unit-testable)
	 * ------------------------------------------------------------------- */

	/**
	 * Parse a token-charge response.
	 *
	 * @param array $json Decoded JSON (or an error marker).
	 *
	 * @return array{ok:bool,paid:bool,error:string}
	 */
	public function parse_charge_response( array $json ): array {
		if ( isset( $json['__error'] ) ) {
			return array( 'ok' => false, 'paid' => false, 'error' => (string) $json['__error'] );
		}
		$paid = 0 === (int) ( $json['ResponseCode'] ?? -1 );
		return array(
			'ok'    => true,
			'paid'  => $paid,
			'error' => $paid ? '' : (string) ( $json['Description'] ?? '' ),
		);
	}

	/**
	 * Build the LowProfile/Create request body.
	 *
	 * @param array $args See {@see GatewayInterface::create_checkout()}.
	 *
	 * @return array<string,mixed>
	 */
	public function build_create_payload( array $args ): array {
		$amount = round( (float) ( $args['amount'] ?? 0 ), 2 );

		return array(
			'TerminalNumber'     => CardcomSettings::terminal(),
			'ApiName'            => CardcomSettings::api_name(),
			// Charge the first period AND tokenise for recurring billing.
			'Operation'          => ! empty( $args['create_token'] ) ? 'ChargeAndCreateToken' : 'ChargeOnly',
			'Amount'             => $amount,
			'ISOCoinId'          => self::COIN_ILS,
			'Language'           => preg_match( '/^[a-z]{2}$/', (string) ( $args['language'] ?? 'he' ) ) ? (string) $args['language'] : 'he',
			'ProductName'        => mb_substr( (string) ( $args['product_name'] ?? 'ComSign' ), 0, 250 ),
			'ReturnValue'        => (string) ( $args['return_value'] ?? '' ),
			'SuccessRedirectUrl' => (string) ( $args['success_url'] ?? '' ),
			'FailedRedirectUrl'  => (string) ( $args['failed_url'] ?? '' ),
			'WebHookUrl'         => (string) ( $args['webhook_url'] ?? '' ),
		);
	}

	/**
	 * Parse a LowProfile/Create response.
	 *
	 * @param array $json Decoded JSON (or an error marker).
	 *
	 * @return array{ok:bool,url:string,reference:string,error:string}
	 */
	public function parse_create_response( array $json ): array {
		if ( isset( $json['__error'] ) ) {
			return array( 'ok' => false, 'url' => '', 'reference' => '', 'error' => (string) $json['__error'] );
		}
		$code = (int) ( $json['ResponseCode'] ?? -1 );
		if ( 0 !== $code || empty( $json['Url'] ) ) {
			return array(
				'ok'        => false,
				'url'       => '',
				'reference' => (string) ( $json['LowProfileId'] ?? '' ),
				'error'     => (string) ( $json['Description'] ?? __( 'Could not start checkout.', 'comsign' ) ),
			);
		}
		return array(
			'ok'        => true,
			'url'       => (string) $json['Url'],
			'reference' => (string) ( $json['LowProfileId'] ?? '' ),
			'error'     => '',
		);
	}

	/**
	 * Parse a LowProfile/GetLpResult response.
	 *
	 * @param array $json Decoded JSON (or an error marker).
	 *
	 * @return array{ok:bool,paid:bool,return_value:string,token:string,amount:float,error:string}
	 */
	public function parse_verify_response( array $json ): array {
		if ( isset( $json['__error'] ) ) {
			return $this->verify_failure( (string) $json['__error'] );
		}
		$code = (int) ( $json['ResponseCode'] ?? -1 );
		$paid = 0 === $code;

		$token = '';
		if ( isset( $json['TokenInfo']['Token'] ) ) {
			$token = (string) $json['TokenInfo']['Token'];
		}
		$amount   = 0.0;
		$currency = '';
		if ( isset( $json['TranzactionInfo']['Amount'] ) ) {
			$amount = (float) $json['TranzactionInfo']['Amount'];
		}
		if ( isset( $json['TranzactionInfo']['CoinId'] ) ) {
			$currency = self::coin_to_currency( (int) $json['TranzactionInfo']['CoinId'] );
		} elseif ( isset( $json['TranzactionInfo']['ISOCoinId'] ) ) {
			$currency = self::coin_to_currency( (int) $json['TranzactionInfo']['ISOCoinId'] );
		}

		return array(
			'ok'           => true,
			'paid'         => $paid,
			'return_value' => (string) ( $json['ReturnValue'] ?? '' ),
			'token'        => $token,
			'amount'       => $amount,
			'currency'     => $currency,
			'error'        => $paid ? '' : (string) ( $json['Description'] ?? '' ),
		);
	}

	/**
	 * Map a Cardcom ISO coin id to a currency code (only the ones we charge in).
	 */
	private static function coin_to_currency( int $coin ): string {
		$map = array( self::COIN_ILS => 'ILS', 2 => 'USD', 978 => 'EUR' );
		return $map[ $coin ] ?? '';
	}

	/**
	 * A normalised verification failure.
	 */
	private function verify_failure( string $error ): array {
		return array( 'ok' => false, 'paid' => false, 'return_value' => '', 'token' => '', 'amount' => 0.0, 'currency' => '', 'error' => $error );
	}

	/* ---------------------------------------------------------------------
	 * HTTP (overridden by the test double)
	 * ------------------------------------------------------------------- */

	/**
	 * POST a JSON request to the Cardcom API and decode the JSON response.
	 *
	 * @param string $path Path under the API base (leading slash).
	 * @param array  $body Request body.
	 *
	 * @return array Decoded response, or array{'__error':string}.
	 */
	protected function request( string $path, array $body ): array {
		$response = wp_remote_post(
			CardcomSettings::API_BASE . $path,
			array(
				'timeout' => 25,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( '__error' => $response->get_error_message() );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : array( '__error' => __( 'Unexpected gateway response.', 'comsign' ) );
	}
}
