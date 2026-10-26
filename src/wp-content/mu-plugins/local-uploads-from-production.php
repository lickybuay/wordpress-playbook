<?php
/**
 * Plugin Name: Local only - uploads from production
 *
 * OPTIONAL. Keep it if you don't copy the uploads folder to your machine; delete
 * this file if you do. Before using it, set MYSITE_LOCAL_HOSTS and
 * MYSITE_PRODUCTION_ORIGIN below to your local hosts and production URL.
 *
 * Description: On a local copy (WP_ENVIRONMENT_TYPE=local) served as
 *              mysite.test (Valet) or localhost:8080 (Docker), a request for a file under the uploads folder that
 *              is not on disk is redirected to the same file on production, so
 *              gigabytes of uploads do not have to be copied locally. Production's
 *              hotlink rule (.htaccess) must allow the local referer.
 *              Anywhere else the environment type is not 'local' and this does
 *              nothing, so it is safe to deploy with the rest of the code.
 *
 * How it gets to run: Valet (and most dev servers) hand a request for a missing
 * static file to WordPress' index.php, which loads mu-plugins before anything
 * else. A real redirect rule in the web server would work too; this keeps the
 * whole thing in one reviewable file.
 *
 * @package mysite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The local hosts this applies to (HTTP_HOST includes the port), and where the real files live.
const MYSITE_LOCAL_HOSTS       = array( 'mysite.test', 'localhost:8080' );
const MYSITE_PRODUCTION_ORIGIN = 'https://www.example.org';

// Public path of the uploads folder. WordPress' default is /wp-content/uploads/.
// If the site moved it (for example the `upload_path` option), change it here.
const MYSITE_UPLOADS_PREFIX = '/wp-content/uploads/';

/**
 * Redirects a missing uploads file to production. Does nothing outside a local copy.
 */
function mysite_redirect_missing_upload() {
	// wp-config.php defines WP_ENVIRONMENT_TYPE=local only on a local copy.
	if ( 'local' !== wp_get_environment_type() ) {
		return;
	}

	$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	if ( ! in_array( $host, MYSITE_LOCAL_HOSTS, true ) ) {
		return;
	}

	// Raw on purpose: sanitizing would strip %-escapes and break the path match. It is only
	// compared and echoed back under a fixed origin, never output as HTML.
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$file = rawurldecode( $path );
	if ( 0 !== strpos( $path, MYSITE_UPLOADS_PREFIX ) || false !== strpos( $file, '..' ) ) {
		return;
	}

	// The file exists locally: let the web server serve it.
	if ( is_file( ABSPATH . ltrim( $file, '/' ) ) ) {
		return;
	}

	header( 'Cache-Control: public, max-age=86400' ); // The browser reuses the redirect for a day.
	header( 'Location: ' . MYSITE_PRODUCTION_ORIGIN . $path, true, 302 );
	exit;
}
mysite_redirect_missing_upload();
