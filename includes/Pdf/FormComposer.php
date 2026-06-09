<?php
/**
 * Generates a fillable "form / questionnaire" PDF from a list of questions.
 *
 * @package ComSign
 */

namespace ComSign\Pdf;

defined( 'ABSPATH' ) || exit;

/**
 * Lays a questionnaire out on an A4 PDF — one labelled question after another,
 * each with a drawn answer area — and returns the exact (fractional) coordinates
 * of every answer field so the signing layer can overlay an input the recipient
 * fills in. A final "Signatures" page is appended for the signature block.
 *
 * Output coordinates use the same top-left, fraction-of-page convention as the
 * manual field editor and {@see PdfSigner}, so the overlaid inputs and the final
 * stamped values line up with the drawn boxes.
 */
final class FormComposer {

	private const MARGIN = 50.0;

	/**
	 * Render the questionnaire and report where each answer field sits.
	 *
	 * @param string $title       Form title.
	 * @param array  $questions   List of ['label','type','required','options'].
	 *                            type is one of text|choice|checkbox.
	 * @param string $output_path Absolute path to write the PDF.
	 *
	 * @return array{pages:int,signature_page:int,fields:array} Field rows carry
	 *               type/page/pos_x/pos_y/width/height/label/required/options
	 *               (no signer_id — the caller assigns it).
	 *
	 * @throws \RuntimeException On write failure.
	 */
	public function render( string $title, array $questions, string $output_path ): array {
		$pdf = new \TCPDF( 'P', 'pt', 'A4', true, 'UTF-8' );
		$pdf->SetCreator( 'ComSign' );
		$pdf->SetTitle( $title );
		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->SetMargins( self::MARGIN, self::MARGIN, self::MARGIN );
		// Manage page breaks ourselves so field coordinates stay predictable.
		$pdf->SetAutoPageBreak( false, self::MARGIN );
		$rtl = is_rtl();
		$pdf->setRTL( $rtl );
		$pdf->SetFont( 'dejavusans', '', 12 );
		if ( method_exists( $pdf, 'setPDFVersion' ) ) {
			$pdf->setPDFVersion( '1.4' );
		}

		$page_w   = (float) $pdf->getPageWidth();
		$page_h   = (float) $pdf->getPageHeight();
		$content_w = $page_w - 2 * self::MARGIN;
		$align    = $rtl ? 'R' : 'L';

		$pdf->AddPage();
		$pdf->SetFont( 'dejavusans', 'B', 16 );
		$pdf->SetXY( self::MARGIN, self::MARGIN );
		$pdf->MultiCell( $content_w, 0, $title, 0, $align );
		$y = (float) $pdf->GetY() + 12.0;
		$pdf->SetFont( 'dejavusans', '', 12 );

		$fields = array();
		$n      = 0;
		foreach ( $questions as $q ) {
			++$n;
			$type    = (string) ( $q['type'] ?? 'text' );
			$label   = $n . '. ' . (string) ( $q['label'] ?? '' );
			$answer_h = 'checkbox' === $type ? 16.0 : ( 'choice' === $type ? 24.0 : 26.0 );

			$label_h = (float) $pdf->getStringHeight( $content_w, $label );
			$needed  = $label_h + 6.0 + $answer_h + 18.0;
			if ( $y + $needed > $page_h - self::MARGIN ) {
				$pdf->AddPage();
				$y = self::MARGIN;
			}

			$pdf->SetXY( self::MARGIN, $y );
			$pdf->MultiCell( $content_w, 0, $label, 0, $align );
			$y    = (float) $pdf->GetY() + 6.0;
			$page = (int) $pdf->getPage();

			if ( 'checkbox' === $type ) {
				$bw = 16.0;
				$fx = $rtl ? ( $page_w - self::MARGIN - $bw ) : self::MARGIN;
				$fw = $bw;
				$fh = $bw;
			} else {
				$fw = 'choice' === $type ? min( 320.0, $content_w ) : $content_w;
				$fx = $rtl ? ( $page_w - self::MARGIN - $fw ) : self::MARGIN;
				$fh = $answer_h;
			}

			$pdf->SetDrawColor( 180, 180, 180 );
			$pdf->Rect( $fx, $y, $fw, $fh );
			$pdf->SetDrawColor( 0, 0, 0 );

			$fields[] = array(
				'type'     => $type,
				'page'     => $page,
				'pos_x'    => round( $fx / $page_w, 4 ),
				'pos_y'    => round( $y / $page_h, 4 ),
				'width'    => round( $fw / $page_w, 4 ),
				'height'   => round( $fh / $page_h, 4 ),
				'label'    => (string) ( $q['label'] ?? '' ),
				'required' => ! empty( $q['required'] ),
				'options'  => 'choice' === $type ? array_values( (array) ( $q['options'] ?? array() ) ) : null,
			);

			$y += $fh + 18.0;
		}

		// A clean final page for the signature block.
		$pdf->AddPage();
		$pdf->SetFont( 'dejavusans', 'B', 15 );
		$pdf->SetXY( self::MARGIN, self::MARGIN );
		$pdf->MultiCell( $content_w, 0, __( 'Signatures', 'comsign' ), 0, $align );
		$signature_page = (int) $pdf->getNumPages();

		$dir = dirname( $output_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$pdf->Output( $output_path, 'F' );
		if ( ! is_file( $output_path ) ) {
			throw new \RuntimeException( __( 'Could not generate the document PDF.', 'comsign' ) );
		}

		return array(
			'pages'          => (int) $pdf->getNumPages(),
			'signature_page' => $signature_page,
			'fields'         => $fields,
		);
	}
}
