<?php
/**
 * Signature-field data access.
 *
 * @package ComSign
 */

namespace ComSign\Database;

defined( 'ABSPATH' ) || exit;

use ComSign\Setup\Installer;

/**
 * Read/write access to the signature fields table.
 *
 * Field positions are stored as fractions (0..1) of the page width/height so
 * they are resolution-independent between the browser preview and the PDF.
 */
final class FieldRepository {

	public const TYPE_SIGNATURE = 'signature';
	public const TYPE_INITIALS  = 'initials';
	public const TYPE_DATE      = 'date';
	public const TYPE_TEXT      = 'text';
	public const TYPE_NUMBER    = 'number';
	public const TYPE_CHECKBOX  = 'checkbox';
	public const TYPE_CHOICE    = 'choice';
	public const TYPE_NAME      = 'name';  // auto: signer name.
	public const TYPE_EMAIL     = 'email'; // auto: signer email.

	/**
	 * Field types the signer interacts with on the signing page.
	 */
	public const INPUT_TYPES = array(
		self::TYPE_TEXT,
		self::TYPE_NUMBER,
		self::TYPE_CHECKBOX,
		self::TYPE_CHOICE,
	);

	/**
	 * Field types auto-filled from signer/document data (no input needed).
	 */
	public const AUTO_TYPES = array(
		self::TYPE_DATE,
		self::TYPE_NAME,
		self::TYPE_EMAIL,
	);

	/**
	 * Insert a field.
	 *
	 * @param array $data document_id, signer_id, type, page, pos_x, pos_y,
	 *                    width, height, options (array for choice fields).
	 */
	public function create( array $data ): int {
		global $wpdb;

		$options = $data['options'] ?? null;
		if ( null !== $options && ! is_string( $options ) ) {
			$options = wp_json_encode( array_values( (array) $options ) );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::fields_table(),
			array(
				'document_id' => (int) $data['document_id'],
				'signer_id'   => (int) $data['signer_id'],
				'type'        => (string) ( $data['type'] ?? self::TYPE_SIGNATURE ),
				'required'    => ! empty( $data['required'] ) ? 1 : 0,
				'page'        => max( 1, (int) ( $data['page'] ?? 1 ) ),
				'pos_x'       => (float) ( $data['pos_x'] ?? 0 ),
				'pos_y'       => (float) ( $data['pos_y'] ?? 0 ),
				'width'       => (float) ( $data['width'] ?? 0 ),
				'height'      => (float) ( $data['height'] ?? 0 ),
				'options'     => $options,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%d', '%d', '%f', '%f', '%f', '%f', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Decode a field's choice options into a flat array of strings.
	 *
	 * @param object $field Field row.
	 *
	 * @return string[]
	 */
	public static function decode_options( object $field ): array {
		if ( empty( $field->options ) ) {
			return array();
		}
		$decoded = json_decode( (string) $field->options, true );
		return is_array( $decoded ) ? array_values( array_map( 'strval', $decoded ) ) : array();
	}

	/**
	 * All fields for a document.
	 */
	public function for_document( int $document_id ): array {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . Installer::fields_table() . ' WHERE document_id = %d ORDER BY page ASC, id ASC',
				$document_id
			)
		);
	}

	/**
	 * All fields assigned to a specific signer.
	 */
	public function for_signer( int $signer_id ): array {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . Installer::fields_table() . ' WHERE signer_id = %d ORDER BY page ASC, id ASC',
				$signer_id
			)
		);
	}

	/**
	 * All fields for a signer, scoped to a specific document.
	 *
	 * Safer than {@see for_signer()} during signing: it guarantees the returned
	 * fields belong to the document being signed, even if a stray field row
	 * referenced a signer id from elsewhere.
	 */
	public function for_signer_in_document( int $document_id, int $signer_id ): array {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'SELECT * FROM ' . Installer::fields_table() . ' WHERE document_id = %d AND signer_id = %d ORDER BY page ASC, id ASC',
				$document_id,
				$signer_id
			)
		);
	}

	/**
	 * Store the captured value (e.g. signature image reference) for a field.
	 *
	 * @param int    $id    Field id.
	 * @param string $value Value to store.
	 */
	public function set_value( int $id, string $value ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::fields_table(),
			array( 'value' => $value ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Delete all fields assigned to a signer.
	 */
	public function delete_for_signer( int $signer_id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::fields_table(),
			array( 'signer_id' => $signer_id ),
			array( '%d' )
		);
	}

	/**
	 * Delete all fields for a document.
	 */
	public function delete_for_document( int $document_id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Installer::fields_table(),
			array( 'document_id' => $document_id ),
			array( '%d' )
		);
	}
}
