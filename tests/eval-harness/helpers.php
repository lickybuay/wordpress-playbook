<?php
/**
 * Tiny test helpers for `wp eval-file` tests (no PHPUnit).
 *
 * What it is: PASS/FAIL counters, two assertions, and a runner that always
 * restores whatever the test wrote to the database, even when it throws.
 *
 * How to use: start each test file with `require_once __DIR__ . '/helpers.php';`.
 * Rename the mysite_t_ prefix to your own.
 *
 * Rules for tests that use it:
 *   - Create posts with mysite_t_post() and change options with mysite_t_option().
 *     Both are undone by mysite_t_cleanup(), which mysite_t_run() always calls.
 *   - Anything else you write (user meta, term meta, transients) you restore
 *     yourself inside the test body.
 *   - Run against a local database, never a production one.
 *
 * @package MySite
 */

$GLOBALS['mysite_t'] = array(
	'pass'    => 0,
	'fail'    => 0,
	'posts'   => array(),
	'options' => array(), // name => original value, or the MYSITE_T_ABSENT marker.
);

const MYSITE_T_ABSENT = '__mysite_t_option_absent__';

/**
 * Assert that a condition is true.
 *
 * @param mixed  $cond  Condition (truthy passes).
 * @param string $label What is being checked.
 */
function mysite_t_assert_true( $cond, $label ) {
	if ( $cond ) {
		++$GLOBALS['mysite_t']['pass'];
		echo "PASS: $label\n";
	} else {
		++$GLOBALS['mysite_t']['fail'];
		echo "FAIL: $label\n";
	}
}

/**
 * Assert strict equality (===) and print both values on failure.
 *
 * @param mixed  $actual   Value produced by the code under test.
 * @param mixed  $expected Value it should have.
 * @param string $label    What is being checked.
 */
function mysite_t_assert_same( $actual, $expected, $label ) {
	$ok = $actual === $expected;
	mysite_t_assert_true( $ok, $label );
	if ( ! $ok ) {
		echo '      expected: ' . var_export( $expected, true ) . "\n";
		echo '      actual:   ' . var_export( $actual, true ) . "\n";
	}
}

/**
 * Create a temporary published post. It is force-deleted by mysite_t_cleanup().
 *
 * @param array $args wp_insert_post() arguments to override.
 * @return int Post ID.
 */
function mysite_t_post( array $args = array() ) {
	$id = wp_insert_post(
		array_merge(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'MYSITE TEST ' . wp_generate_password( 8, false ),
			),
			$args
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'could not create temp post: ' . $id->get_error_message() );
	}

	$GLOBALS['mysite_t']['posts'][] = $id;
	return $id;
}

/**
 * Set an option for the duration of the test. The original value (or its
 * absence) is put back by mysite_t_cleanup().
 *
 * @param string $name  Option name.
 * @param mixed  $value Temporary value.
 */
function mysite_t_option( $name, $value ) {
	if ( ! array_key_exists( $name, $GLOBALS['mysite_t']['options'] ) ) {
		$GLOBALS['mysite_t']['options'][ $name ] = get_option( $name, MYSITE_T_ABSENT );
	}
	update_option( $name, $value );
}

/**
 * Undo every database write made through the helpers above.
 */
function mysite_t_cleanup() {
	foreach ( $GLOBALS['mysite_t']['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	$GLOBALS['mysite_t']['posts'] = array();

	foreach ( $GLOBALS['mysite_t']['options'] as $name => $original ) {
		if ( MYSITE_T_ABSENT === $original ) {
			delete_option( $name );
		} else {
			update_option( $name, $original );
		}
	}
	$GLOBALS['mysite_t']['options'] = array();
}

/**
 * Run a test body, always clean up, print the totals and exit non-zero on failure.
 *
 * @param callable $body The test.
 */
function mysite_t_run( callable $body ) {
	try {
		$body();
	} catch ( Throwable $e ) {
		++$GLOBALS['mysite_t']['fail'];
		echo 'FAIL: uncaught ' . get_class( $e ) . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n";
	} finally {
		mysite_t_cleanup();
	}

	printf( "TOTAL %d passed, %d failed\n", $GLOBALS['mysite_t']['pass'], $GLOBALS['mysite_t']['fail'] );
	exit( $GLOBALS['mysite_t']['fail'] > 0 ? 1 : 0 );
}
