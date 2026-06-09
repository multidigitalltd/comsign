<?php
/**
 * CSV export of documents.
 *
 * @package ComSign
 */

namespace ComSign\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a CSV from decorated document rows. The row-building is pure (and
 * unit-tested); the controller streams the result with download headers.
 */
final class DocumentExport {

	/**
	 * Column headers for the export.
	 *
	 * @return string[]
	 */
	public static function headers(): array {
		return array(
			__( 'Title', 'comsign' ),
			__( 'Status', 'comsign' ),
			__( 'Signed', 'comsign' ),
			__( 'Total signers', 'comsign' ),
			__( 'Created', 'comsign' ),
			__( 'Verification code (SHA-256)', 'comsign' ),
		);
	}

	/**
	 * Render decorated document rows as a CSV string (UTF-8 with BOM so Excel
	 * opens Hebrew/Arabic correctly).
	 *
	 * @param array $documents Rows with title/status/created_at/signed_hash and
	 *                         decorated signer_signed/signer_total.
	 */
	public static function to_csv( array $documents ): string {
		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, array_map( array( __CLASS__, 'sanitize_cell' ), self::headers() ) );

		foreach ( $documents as $doc ) {
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'sanitize_cell' ),
					array(
						(string) ( $doc->title ?? '' ),
						(string) ( $doc->status ?? '' ),
						(string) (int) ( $doc->signer_signed ?? 0 ),
						(string) (int) ( $doc->signer_total ?? 0 ),
						self::format_date( (string) ( $doc->created_at ?? '' ) ),
						(string) ( $doc->signed_hash ?? '' ),
					)
				)
			);
		}

		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );

		// UTF-8 BOM for spreadsheet apps.
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Neutralise spreadsheet formula injection (CSV injection / CWE-1236).
	 *
	 * Document titles and other text are user-controlled. A cell beginning with
	 * =, +, -, @, or a control character (tab / CR / LF) can be interpreted as a
	 * formula — and run HYPERLINK/WEBSERVICE/DDE payloads — when the CSV is opened
	 * in Excel, LibreOffice or Google Sheets. We prefix any such cell with a
	 * single quote so the spreadsheet treats it as literal text.
	 *
	 * @param string $value Raw cell value.
	 */
	public static function sanitize_cell( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}
		$first = $value[0];
		if ( in_array( $first, array( '=', '+', '-', '@' ), true )
			|| "\t" === $first || "\r" === $first || "\n" === $first ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Format a stored UTC datetime for the export (site date format), or '' .
	 */
	private static function format_date( string $gmt ): string {
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return '';
		}
		return (string) mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_date_from_gmt( $gmt ) );
	}
}
