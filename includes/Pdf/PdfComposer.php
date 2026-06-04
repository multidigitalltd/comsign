<?php
/**
 * Generates a source PDF from rich-text (HTML) content.
 *
 * @package ComSign
 */

namespace ComSign\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * Builds an A4 PDF from admin-authored HTML, with full Hebrew/RTL support.
 *
 * This is the "compose a document" counterpart to uploading a PDF: the output
 * is an ordinary source PDF that the rest of the workflow (field placement,
 * signing, stamping) treats identically.
 */
final class PdfComposer {

	/**
	 * Render HTML content to a PDF file.
	 *
	 * @param string $title       Document title (used for metadata + heading).
	 * @param string $html        Sanitised HTML body.
	 * @param string $output_path Absolute path to write the PDF.
	 *
	 * @throws \RuntimeException On write failure.
	 */
	public function render( string $title, string $html, string $output_path ): void {
		$pdf = new \TCPDF( 'P', 'pt', 'A4', true, 'UTF-8' );

		$pdf->SetCreator( 'ComSign' );
		$pdf->SetTitle( $title );
		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->SetMargins( 50, 50, 50 );
		$pdf->SetAutoPageBreak( true, 50 );

		// Match the document direction to the site locale.
		$pdf->setRTL( is_rtl() );

		// dejavusans ships with TCPDF and covers Hebrew glyphs.
		$pdf->SetFont( 'dejavusans', '', 12 );

		// Keep FPDI's free parser happy downstream (PDF 1.4, no object streams).
		if ( method_exists( $pdf, 'setPDFVersion' ) ) {
			$pdf->setPDFVersion( '1.4' );
		}

		$pdf->AddPage();

		$body = '';
		if ( '' !== $title ) {
			$body .= '<h1 style="font-size:18pt;">' . esc_html( $title ) . '</h1>';
		}
		$body .= $html;

		$pdf->writeHTML( $body, true, false, true, false, '' );

		$dir = dirname( $output_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$pdf->Output( $output_path, 'F' );

		if ( ! is_file( $output_path ) ) {
			throw new \RuntimeException( __( 'Could not generate the document PDF.', 'comsign' ) );
		}
	}
}
