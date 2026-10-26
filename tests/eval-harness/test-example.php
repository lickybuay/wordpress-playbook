<?php
/**
 * Example test: copy this file, rename it test-<topic>.php and replace the checks.
 *
 * Run one file:  wp --path=wordpress eval-file tests/eval-harness/test-example.php
 * Run them all:  tests/eval-harness/run.sh
 *
 * It shows the three things most tests need: checking WordPress configuration,
 * checking a pure function, and writing to the database and getting it restored.
 *
 * @package MySite
 */

require_once __DIR__ . '/helpers.php';

mysite_t_run(
	function () {
		global $wp;

		// 1. Configuration: a registered post type.
		mysite_t_assert_true( post_type_exists( 'post' ), 'post type "post" is registered' );

		// 2. A custom query variable must not collide with a reserved one.
		// "page" is reserved: using it for your own pagination makes WordPress treat
		// the request as page N of a single post and return a 404.
		mysite_t_assert_true(
			in_array( 'page', $wp->public_query_vars, true ),
			'"page" is a reserved public query var'
		);
		mysite_t_assert_true(
			! in_array( 'mysite_pg', $wp->public_query_vars, true ),
			'"mysite_pg" does not collide with a reserved query var'
		);

		// 3. A pure function: slug sanitizing used before a WP_Query.
		mysite_t_assert_same( sanitize_title( 'Café Menu' ), 'cafe-menu', 'sanitize_title strips accents' );

		// 4. Database writes: both are undone when the test ends.
		$original = get_option( 'blogdescription' );
		mysite_t_option( 'blogdescription', 'temporary tagline' );
		mysite_t_assert_same( get_option( 'blogdescription' ), 'temporary tagline', 'option changed during the test' );

		$post_id = mysite_t_post( array( 'post_title' => 'Hello harness' ) );
		mysite_t_assert_same( get_post_status( $post_id ), 'publish', 'temporary post is published' );

		// After mysite_t_run() finishes, the option is back to $original and the
		// post is gone. This line only documents the value being protected.
		mysite_t_assert_true( is_string( $original ), 'original tagline captured before the change' );
	}
);
