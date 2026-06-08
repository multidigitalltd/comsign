<?php
/**
 * Document CSV export.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

use ComSign\Services\DocumentExport;

Test::add( 'export: documents render as CSV with a header and BOM', static function (): void {
	$rows = array(
		(object) array(
			'title'         => 'Lease, 2026',           // comma forces quoting
			'status'        => 'completed',
			'signer_signed' => 2,
			'signer_total'  => 2,
			'created_at'    => '2026-01-15 09:30:00',
			'signed_hash'   => str_repeat( 'a', 64 ),
		),
		(object) array(
			'title'         => 'Draft "X"',             // quotes inside a field
			'status'        => 'draft',
			'signer_signed' => 0,
			'signer_total'  => 1,
			'created_at'    => '',
			'signed_hash'   => '',
		),
	);

	$csv = DocumentExport::to_csv( $rows );

	// UTF-8 BOM so spreadsheets read Hebrew/Arabic correctly.
	Test::equals( "\xEF\xBB\xBF", substr( $csv, 0, 3 ), 'starts with a UTF-8 BOM' );

	$lines = array_values( array_filter( explode( "\n", str_replace( "\r", '', $csv ) ), static fn( $l ) => '' !== $l ) );
	Test::equals( 3, count( $lines ), 'header + two rows' );

	// Header present.
	Test::ok( false !== strpos( $csv, 'Title' ) && false !== strpos( $csv, 'Status' ), 'header columns present' );

	// CSV quoting: a value with a comma is wrapped in quotes.
	Test::ok( false !== strpos( $csv, '"Lease, 2026"', ), 'comma value is quoted' );
	// Quotes inside a field are doubled.
	Test::ok( false !== strpos( $csv, '"Draft ""X"""' ), 'embedded quotes are escaped' );

	// The hash is included.
	Test::ok( false !== strpos( $csv, str_repeat( 'a', 64 ) ), 'verification hash included' );
} );

Test::add( 'export: spreadsheet formula injection is neutralised', static function (): void {
	$payloads = array(
		'=HYPERLINK("http://evil","click")',
		'+1+1',
		'-2+3',
		'@SUM(A1:A9)',
		"\t=cmd|'/c calc'!A1",
	);
	foreach ( $payloads as $payload ) {
		$out = DocumentExport::sanitize_cell( $payload );
		Test::equals( "'", $out[0], 'risky cell is prefixed with a quote: ' . $payload );
		Test::equals( $payload, substr( $out, 1 ), 'original value preserved after the quote' );
	}

	// Safe values are untouched.
	Test::equals( 'Lease 2026', DocumentExport::sanitize_cell( 'Lease 2026' ), 'plain text untouched' );
	Test::equals( '', DocumentExport::sanitize_cell( '' ), 'empty stays empty' );

	// End-to-end: a malicious title comes out escaped in the CSV body.
	$csv = DocumentExport::to_csv( array(
		(object) array(
			'title'         => '=HYPERLINK("http://evil")',
			'status'        => 'draft',
			'signer_signed' => 0,
			'signer_total'  => 1,
			'created_at'    => '',
			'signed_hash'   => '',
		),
	) );
	Test::ok( false !== strpos( $csv, "'=HYPERLINK" ), 'formula in CSV body is escaped' );
} );
