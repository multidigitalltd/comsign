<?php
/**
 * Tiny assertion + test-registry helper for ComSign's integration tests.
 *
 * Deliberately dependency-free: the tests boot a real WordPress (SQLite) and
 * exercise the plugin's service/repository layer, so all we need here is a way
 * to register named checks and tally pass/fail. {@see tests/run.php} drives it.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

final class Test {

	/** @var array<int,array{name:string,fn:callable}> */
	private static array $cases = array();

	private static int $passed = 0;
	private static int $failed = 0;

	/** @var string[] */
	private static array $failures = array();

	/** Register a test case. */
	public static function add( string $name, callable $fn ): void {
		self::$cases[] = array( 'name' => $name, 'fn' => $fn );
	}

	/** Run every registered case; returns the process exit code. */
	public static function run(): int {
		foreach ( self::$cases as $case ) {
			self::$current = $case['name'];
			try {
				( $case['fn'] )();
			} catch ( \Throwable $e ) {
				self::fail( 'threw ' . get_class( $e ) . ': ' . $e->getMessage() );
			}
		}

		echo "\n----------------------------------------\n";
		echo sprintf( "ComSign tests: %d passed, %d failed\n", self::$passed, self::$failed );
		foreach ( self::$failures as $line ) {
			echo '  FAIL  ' . $line . "\n";
		}

		return self::$failed > 0 ? 1 : 0;
	}

	private static string $current = '';

	/** Assert a condition is truthy. */
	public static function ok( bool $cond, string $label ): void {
		if ( $cond ) {
			self::pass();
		} else {
			self::fail( $label );
		}
	}

	/** Assert two values are equal (loose, then strict-type checked). */
	public static function equals( $expected, $actual, string $label ): void {
		if ( $expected === $actual ) {
			self::pass();
			return;
		}
		self::fail(
			$label . ' — expected ' . self::dump( $expected ) . ', got ' . self::dump( $actual )
		);
	}

	/** Assert that running $fn throws. */
	public static function throws( callable $fn, string $label ): void {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			self::pass();
			return;
		}
		self::fail( $label . ' — expected an exception, none thrown' );
	}

	private static function pass(): void {
		self::$passed++;
		echo '.';
	}

	private static function fail( string $label ): void {
		self::$failed++;
		self::$failures[] = '[' . self::$current . '] ' . $label;
		echo 'F';
	}

	private static function dump( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}
}
