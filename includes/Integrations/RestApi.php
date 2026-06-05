<?php
/**
 * REST API for automation/integrations.
 *
 * @package ComSign
 */

namespace ComSign\Integrations;

defined( 'ABSPATH' ) || exit;

use ComSign\Database\AuditRepository;
use ComSign\Database\DocumentRepository;
use ComSign\Database\FieldRepository;
use ComSign\Database\SignerRepository;
use ComSign\Services\DocumentService;
use ComSign\Support\Capabilities;
use ComSign\Support\Settings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exposes documents under the comsign/v1 namespace.
 *
 * Authentication: either a logged-in user with the manage capability, or an
 * `X-ComSign-Key` header matching the API key from Settings.
 */
final class RestApi {

	private const NS = 'comsign/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/documents',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_documents' ),
					'permission_callback' => array( $this, 'authorize' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_document' ),
					'permission_callback' => array( $this, 'authorize' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/documents/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_document' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			self::NS,
			'/documents/(?P<id>\d+)/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_audit' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
	}

	/**
	 * Allow logged-in managers or a valid API key.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function authorize( WP_REST_Request $request ): bool {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			return true;
		}

		$provided = (string) $request->get_header( 'x-comsign-key' );
		$expected = (string) Settings::get( 'api_key' );

		return '' !== $expected && '' !== $provided && hash_equals( $expected, $provided );
	}

	/**
	 * GET /documents
	 */
	public function list_documents( WP_REST_Request $request ): WP_REST_Response {
		$documents = new DocumentRepository();
		$per_page  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) );
		$page      = max( 1, (int) $request->get_param( 'page' ) ?: 1 );

		$items = array();
		foreach ( $documents->paginate( $per_page, ( $page - 1 ) * $per_page ) as $doc ) {
			$items[] = $this->shape_document( $doc );
		}

		return new WP_REST_Response(
			array(
				'total' => $documents->count(),
				'items' => $items,
			),
			200
		);
	}

	/**
	 * GET /documents/{id}
	 */
	public function get_document( WP_REST_Request $request ) {
		$documents = new DocumentRepository();
		$signers   = new SignerRepository();

		$doc = $documents->find( (int) $request['id'] );
		if ( ! $doc ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$data            = $this->shape_document( $doc );
		$data['signers'] = array();
		foreach ( $signers->for_document( (int) $doc->id ) as $signer ) {
			$data['signers'][] = array(
				'id'        => (int) $signer->id,
				'name'      => $signer->name,
				'email'     => $signer->email,
				'status'    => $signer->status,
				'signed_at' => $signer->signed_at,
			);
		}

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * GET /documents/{id}/audit
	 */
	public function get_audit( WP_REST_Request $request ): WP_REST_Response {
		$audit   = new AuditRepository();
		$entries = array();
		foreach ( $audit->for_document( (int) $request['id'] ) as $row ) {
			$entries[] = array(
				'event'      => $row->event,
				'signer_id'  => (int) $row->signer_id,
				'ip'         => $row->ip,
				'user_agent' => $row->user_agent,
				'created_at' => $row->created_at,
			);
		}

		return new WP_REST_Response( array( 'items' => $entries ), 200 );
	}

	/**
	 * POST /documents — create a document from text + signers, optionally send.
	 */
	public function create_document( WP_REST_Request $request ) {
		$service = new DocumentService();
		$signers = new SignerRepository();

		$title     = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$content   = wp_kses_post( (string) $request->get_param( 'content' ) );
		$variables = (array) ( $request->get_param( 'variables' ) ?: array() );

		try {
			$document_id = $service->create_from_text( $title, $content, $variables );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'error' => $e->getMessage() ), 400 );
		}

		// Add signers (by index), keep the mapping for fields.
		$signer_ids = array();
		foreach ( (array) $request->get_param( 'signers' ) as $index => $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			try {
				$signer_ids[ (int) $index ] = $service->add_signer(
					$document_id,
					sanitize_text_field( (string) ( $s['name'] ?? '' ) ),
					sanitize_email( (string) ( $s['email'] ?? '' ) ),
					sanitize_text_field( (string) ( $s['phone'] ?? '' ) )
				);
			} catch ( \Throwable $e ) {
				return new WP_REST_Response( array( 'error' => $e->getMessage() ), 400 );
			}
		}

		// Optional fields, referencing signers by index.
		$fields = array();
		foreach ( (array) $request->get_param( 'fields' ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$idx = (int) ( $f['signer_index'] ?? 0 );
			if ( ! isset( $signer_ids[ $idx ] ) ) {
				continue;
			}
			$fields[] = array(
				'signer_id' => $signer_ids[ $idx ],
				'type'      => sanitize_key( (string) ( $f['type'] ?? 'signature' ) ),
				'required'  => ! empty( $f['required'] ),
				'page'      => (int) ( $f['page'] ?? 1 ),
				'pos_x'     => (float) ( $f['pos_x'] ?? 0 ),
				'pos_y'     => (float) ( $f['pos_y'] ?? 0 ),
				'width'     => (float) ( $f['width'] ?? 0 ),
				'height'    => (float) ( $f['height'] ?? 0 ),
				'options'   => isset( $f['options'] ) && is_array( $f['options'] ) ? array_map( 'sanitize_text_field', $f['options'] ) : null,
			);
		}
		if ( $fields ) {
			$service->save_fields( $document_id, $fields );
		}

		$result = array( 'id' => $document_id, 'status' => 'draft' );

		if ( $request->get_param( 'send' ) ) {
			try {
				$service->send(
					$document_id,
					array(
						'sequential'  => (bool) $request->get_param( 'sequential' ),
						'message'     => sanitize_textarea_field( (string) $request->get_param( 'message' ) ),
						'expiry_days' => (int) $request->get_param( 'expiry_days' ),
					)
				);
				$result['status'] = 'sent';
			} catch ( \Throwable $e ) {
				// The document was created but could not be sent. Signal this
				// distinctly with a 502 + structured error so automation does
				// not mistake a partial result for a successful send.
				$result['status'] = 'created_but_not_sent';
				$result['error']  = array(
					'code'    => 'send_failed',
					'message' => $e->getMessage(),
				);
				return new WP_REST_Response( $result, 502 );
			}
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Shape a document row for API output.
	 *
	 * @param object $doc Document row.
	 */
	private function shape_document( object $doc ): array {
		return array(
			'id'          => (int) $doc->id,
			'title'       => $doc->title,
			'status'      => $doc->status,
			'sha256'      => $doc->signed_hash,
			'created_at'  => $doc->created_at,
			'updated_at'  => $doc->updated_at,
		);
	}
}
