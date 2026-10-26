<?php
/**
 * Theme bootstrap.
 *
 * @package mytheme
 */

defined( 'ABSPATH' ) || exit;

/**
 * URL of a theme file with a content-hash version, so browsers and CDNs refetch
 * it exactly when its content changes (no manual version bumps, no stale cache).
 *
 * @param string $relative File path relative to the theme folder, e.g. 'style.css'.
 * @return string Absolute URL ending in ?ver=<first 8 chars of the md5>.
 */
function mysite_asset_url( $relative ) {
	static $versions = array();

	$relative = ltrim( $relative, '/' );
	if ( ! isset( $versions[ $relative ] ) ) {
		$file                  = get_theme_file_path( $relative );
		$hash                  = is_file( $file ) ? md5_file( $file ) : false;
		$versions[ $relative ] = $hash ? substr( $hash, 0, 8 ) : wp_get_theme()->get( 'Version' );
	}

	return add_query_arg( 'ver', $versions[ $relative ], get_theme_file_uri( $relative ) );
}

/**
 * Enqueues the theme stylesheet.
 */
function mysite_enqueue_assets() {
	// Theme stylesheet; the version is a hash of the file, see mysite_asset_url().
	wp_enqueue_style( 'mytheme', mysite_asset_url( 'style.css' ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- The version is already in the URL.
}
add_action( 'wp_enqueue_scripts', 'mysite_enqueue_assets' );

/**
 * Registers theme features.
 */
function mysite_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
}
add_action( 'after_setup_theme', 'mysite_setup' );
