<?php
/**
 * ComSign integration-test runner.
 *
 * Usage: COMSIGN_WP_LOAD=/path/to/wp-load.php php tests/run.php
 *
 * Boots WordPress (SQLite) with the plugin active, loads every case under
 * tests/cases/, runs them and exits non-zero if any assertion failed.
 *
 * @package ComSign\Tests
 */

namespace ComSign\Tests;

require __DIR__ . '/bootstrap.php';

foreach ( glob( __DIR__ . '/cases/*.php' ) as $case ) {
	require $case;
}

exit( Test::run() );
