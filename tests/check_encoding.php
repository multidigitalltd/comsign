<?php
/**
 * Encoding guard: fail on common "mojibake" (double-encoded UTF-8) byte
 * sequences in source and translation files.
 *
 * When UTF-8 text (Hebrew dashes/apostrophes, smart quotes, emoji) is decoded
 * as Latin-1/CP-1252 and re-encoded as UTF-8, it turns into recognisable
 * sequences such as "â€™", "Ã©" or "Ã‚". These are virtually impossible in
 * legitimate Hebrew/Arabic/Russian/English content, so their presence almost
 * always means a corrupted file. RTL customers notice immediately.
 *
 * Run: php tests/check_encoding.php   (exit 1 if any are found).
 *
 * @package ComSign\Tests
 */

$root = dirname( __DIR__ );

// Directories/files to scan (skip vendor and binary assets).
$targets = array( 'includes', 'assets/css', 'assets/js', 'languages', 'comsign.php', 'uninstall.php', 'readme.txt' );

// Mojibake signatures, as raw UTF-8 byte sequences.
$bad = array(
	"\xC3\xA2\xE2\x82\xAC" => 'â€ (mangled dash/quote/apostrophe)',
	"\xC3\x83\xC2"         => 'Ã + C2 (double-encoded accent)',
	"\xC3\x82\xC2"         => 'Â + C2 (spurious non-breaking marker)',
	"\xC3\xB0\xC2\x9F"     => 'ð + Ÿ (mangled emoji)',
	"\xEF\xBF\xBD"         => 'U+FFFD replacement character',
);

$files = array();
$push  = static function ( string $path ) use ( &$files ) {
	if ( is_file( $path ) ) {
		$files[] = $path;
	}
};
$walk = static function ( string $dir ) use ( &$files ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$ext = strtolower( $f->getExtension() );
		if ( in_array( $ext, array( 'php', 'css', 'js', 'po', 'pot', 'txt' ), true ) ) {
			$files[] = $f->getPathname();
		}
	}
};

foreach ( $targets as $t ) {
	$path = $root . '/' . $t;
	if ( is_dir( $path ) ) {
		$walk( $path );
	} else {
		$push( $path );
	}
}

$problems = 0;
foreach ( $files as $file ) {
	$data = file_get_contents( $file );
	if ( false === $data ) {
		continue;
	}
	foreach ( $bad as $needle => $label ) {
		if ( false !== strpos( $data, $needle ) ) {
			$rel = str_replace( $root . '/', '', $file );
			fwrite( STDERR, sprintf( "Mojibake in %s: %s\n", $rel, $label ) );
			$problems++;
		}
	}
}

if ( $problems > 0 ) {
	fwrite( STDERR, sprintf( "\nEncoding check FAILED: %d issue(s).\n", $problems ) );
	exit( 1 );
}

echo "Encoding check passed (" . count( $files ) . " files scanned).\n";
