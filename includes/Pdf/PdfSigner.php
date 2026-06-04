<?php
/**
 * Embeds captured signatures onto a source PDF using FPDI + TCPDF.
 *
 * @package ComSign
 */

namespace ComSign\Pdf;

defined( 'ABSPATH' ) || exit;

use setasign\Fpdi\Tcpdf\Fpdi;
use ComSign\Database\FieldRepository;

/**
 * Stamps signature images / text fields onto an existing PDF.
 *
 * Field coordinates are stored as fractions (0..1) of each page, so the same
 * placement made in the browser preview maps exactly onto the PDF regardless
 * of page size or zoom.
 */
final class PdfSigner {

	/**
	 * Render a signed PDF.
	 *
	 * @param string $source_path Absolute path to the source PDF.
	 * @param string $output_path Absolute path to write the signed PDF.
	 * @param array  $fields      Field rows. Each must have page/pos_x/pos_y/
	 *                            width/height and a decoded `value` payload.
	 * @param array  $certificate Optional certificate data: 'title', 'lines'
	 *                            (array of label => value strings) appended as
	 *                            a final page. Empty to skip.
	 *
	 * @throws \RuntimeException On import/render failure.
	 */
	public function render( string $source_path, string $output_path, array $fields, array $certificate = array() ): void {
		if ( ! is_readable( $source_path ) ) {
			throw new \RuntimeException( 'Source PDF is not readable.' );
		}

		// Group fields by page for efficient placement.
		$by_page = array();
		foreach ( $fields as $field ) {
			$by_page[ (int) $field->page ][] = $field;
		}

		$pdf = new Fpdi( 'P', 'pt' );
		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->setAutoPageBreak( false );
		$pdf->SetMargins( 0, 0, 0 );

		try {
			$page_count = $pdf->setSourceFile( $source_path );
		} catch ( \Throwable $e ) {
			// FPDI's free parser only reads up to PDF 1.4; surface a clear error.
			throw new \RuntimeException(
				'Unable to read the PDF. It may use a newer/encrypted format than supported: ' . $e->getMessage(),
				0,
				$e
			);
		}

		for ( $page_no = 1; $page_no <= $page_count; $page_no++ ) {
			$template = $pdf->importPage( $page_no );
			$size     = $pdf->getTemplateSize( $template );

			$orientation = ( $size['width'] > $size['height'] ) ? 'L' : 'P';
			$pdf->AddPage( $orientation, array( $size['width'], $size['height'] ) );
			$pdf->useTemplate( $template );

			if ( empty( $by_page[ $page_no ] ) ) {
				continue;
			}

			foreach ( $by_page[ $page_no ] as $field ) {
				$this->place_field( $pdf, $field, (float) $size['width'], (float) $size['height'] );
			}
		}

		if ( ! empty( $certificate['lines'] ) ) {
			$this->append_certificate( $pdf, $certificate );
		}

		$dir = dirname( $output_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// 'F' writes to a file path.
		$pdf->Output( $output_path, 'F' );

		if ( ! is_file( $output_path ) ) {
			throw new \RuntimeException( 'Failed to write the signed PDF.' );
		}
	}

	/**
	 * Append a final "Signature Certificate" page summarising the audit trail.
	 *
	 * @param Fpdi  $pdf         PDF instance.
	 * @param array $certificate 'title' string and 'lines' (label => value).
	 */
	private function append_certificate( Fpdi $pdf, array $certificate ): void {
		// A4 in points.
		$pdf->AddPage( 'P', array( 595.28, 841.89 ) );
		$pdf->SetMargins( 40, 40, 40 );
		$pdf->setRTL( true );

		$title = isset( $certificate['title'] ) ? (string) $certificate['title'] : '';

		$html  = '<h1 style="font-size:16pt;">' . esc_html( $title ) . '</h1>';
		$html .= '<table cellpadding="6" border="0.5" style="font-size:10pt;">';
		foreach ( (array) $certificate['lines'] as $label => $value ) {
			$html .= '<tr><td width="35%"><b>' . esc_html( (string) $label ) . '</b></td>'
				. '<td width="65%">' . esc_html( (string) $value ) . '</td></tr>';
		}
		$html .= '</table>';

		$pdf->SetFont( 'dejavusans', '', 10 );
		$pdf->writeHTML( $html, true, false, true, false, '' );
		$pdf->setRTL( false );
	}

	/**
	 * Place a single field on the current page.
	 *
	 * @param Fpdi   $pdf         PDF instance.
	 * @param object $field       Field row with a decoded `value` payload.
	 * @param float  $page_width  Page width in points.
	 * @param float  $page_height Page height in points.
	 */
	private function place_field( Fpdi $pdf, object $field, float $page_width, float $page_height ): void {
		$x = (float) $field->pos_x * $page_width;
		$y = (float) $field->pos_y * $page_height;
		$w = (float) $field->width * $page_width;
		$h = (float) $field->height * $page_height;

		$value = $this->decode_value( $field );

		if ( '' === $value['kind'] ) {
			return;
		}

		if ( 'image' === $value['kind'] && '' !== $value['data'] ) {
			// $value['data'] is a base64-encoded PNG (no data: prefix).
			$pdf->Image(
				'@' . base64_decode( $value['data'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				$x,
				$y,
				$w,
				$h,
				'PNG',
				'',
				'',
				false,
				300,
				'',
				false,
				false,
				0,
				false,
				false,
				false
			);
			return;
		}

		if ( 'text' === $value['kind'] && '' !== $value['text'] ) {
			$pdf->SetFont( 'dejavusans', '', max( 8.0, $h * 0.6 ) );
			$pdf->SetTextColor( 0, 0, 0 );
			$pdf->SetXY( $x, $y );
			// dejavusans carries Hebrew glyphs; RTL handled per-cell.
			$pdf->setRTL( $this->is_rtl( $value['text'] ) );
			$pdf->Cell( $w, $h, $value['text'], 0, 0, '' );
			$pdf->setRTL( false );
		}
	}

	/**
	 * Normalise a field's stored value into a render payload.
	 *
	 * @param object $field Field row.
	 *
	 * @return array{kind:string,data:string,text:string}
	 */
	private function decode_value( object $field ): array {
		$out = array(
			'kind' => '',
			'data' => '',
			'text' => '',
		);

		$raw = is_string( $field->value ) ? $field->value : '';
		if ( '' === $raw ) {
			return $out;
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['kind'] ) ) {
			return $out;
		}

		$out['kind'] = (string) $decoded['kind'];
		$out['data'] = isset( $decoded['data'] ) ? (string) $decoded['data'] : '';
		$out['text'] = isset( $decoded['text'] ) ? (string) $decoded['text'] : '';

		return $out;
	}

	/**
	 * Heuristic: does the string contain Hebrew/Arabic characters?
	 *
	 * @param string $text Text to inspect.
	 */
	private function is_rtl( string $text ): bool {
		return (bool) preg_match( '/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}]/u', $text );
	}
}
