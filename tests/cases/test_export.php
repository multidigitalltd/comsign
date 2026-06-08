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
