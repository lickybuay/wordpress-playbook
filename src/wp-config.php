<?php
/**
 * Site configuration for example.org (production and local).
 *
 * This file is copied into wordpress/ by `composer build` and deployed with the
 * rest of the code. It contains NO secrets: database credentials and the
 * authentication keys/salts are read from a .env file that lives outside the
 * docroot. See docs/02-config-and-secrets.md.
 *
 * Candidate .env files, first readable one wins:
 * 1. $MYSITE_ENV_FILE (explicit override, used by tooling and tests).
 * 2. /home/siteuser/.env (production server, chmod 600, owned by the site user).
 * 3. <repo root>/.env (local development; this file lives in wordpress/). Only
 *    used when the parent directory really is the repo (it has a composer.json).
 *    On the server the parent is the docroot's parent, which a vhost may serve,
 *    so a .env there must never be picked up.
 *
 * Format: one KEY=value per line. Blank lines and lines starting with # are
 * skipped. The line is split on the FIRST `=` only, and one pair of matching
 * surrounding quotes is stripped from the value. There is no inline-comment
 * handling because the salts legitimately contain # ' " $ and =.
 *
 * @package mysite
 */

/**
 * Stops the request without revealing anything but the given message.
 *
 * @param string $message Generic message; must never contain secret values.
 */
function mysite_config_fail( $message ) {
	if ( 'cli' !== PHP_SAPI ) {
		if ( ! headers_sent() ) {
			http_response_code( 503 );
			header( 'Retry-After: 300' );
			header( 'Content-Type: text/plain; charset=utf-8' );
		}
	}
	echo $message . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text, fixed strings and key names only.
	exit( 1 );
}

/**
 * Parses the first readable env file out of the candidates.
 *
 * @param string[] $candidates Paths to try, in order.
 * @return array<string,string>|null Parsed values, or null when no file is readable.
 */
function mysite_load_env( array $candidates ) {
	foreach ( $candidates as $path ) {
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			continue;
		}
		$lines = file( $path, FILE_IGNORE_NEW_LINES );
		if ( false === $lines ) {
			continue;
		}
		$values = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$pos = strpos( $line, '=' );
			if ( false === $pos ) {
				continue;
			}
			$key   = trim( substr( $line, 0, $pos ) );
			$value = trim( substr( $line, $pos + 1 ) );
			$len   = strlen( $value );
			if ( $len >= 2 && ( '"' === $value[0] || "'" === $value[0] ) && $value[0] === $value[ $len - 1 ] ) {
				$value = substr( $value, 1, -1 );
			}
			if ( '' !== $key ) {
				$values[ $key ] = $value;
			}
		}
		return $values;
	}
	return null;
}

$mysite_candidates = array(
	(string) getenv( 'MYSITE_ENV_FILE' ),
	'/home/siteuser/.env',
);
if ( is_file( dirname( __DIR__ ) . '/composer.json' ) ) {
	$mysite_candidates[] = dirname( __DIR__ ) . '/.env';
}
$mysite_env = mysite_load_env( $mysite_candidates );

if ( null === $mysite_env ) {
	mysite_config_fail( 'Site configuration error: no environment file found.' );
}

$mysite_required = array(
	'DB_NAME',
	'DB_USER',
	'DB_PASSWORD',
	'DB_HOST',
	'AUTH_KEY',
	'SECURE_AUTH_KEY',
	'LOGGED_IN_KEY',
	'NONCE_KEY',
	'AUTH_SALT',
	'SECURE_AUTH_SALT',
	'LOGGED_IN_SALT',
	'NONCE_SALT',
);
$mysite_missing  = array();
foreach ( $mysite_required as $mysite_key ) {
	if ( ! isset( $mysite_env[ $mysite_key ] ) || '' === $mysite_env[ $mysite_key ] ) {
		$mysite_missing[] = $mysite_key;
	}
}
if ( $mysite_missing ) {
	// Names only, never values.
	mysite_config_fail( 'Site configuration error: missing required setting(s): ' . implode( ', ', $mysite_missing ) );
}

foreach ( $mysite_required as $mysite_key ) {
	define( $mysite_key, $mysite_env[ $mysite_key ] );
}

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/** WordPress database table prefix. */
$table_prefix = 'wp_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

// Debugging: off by default; WP_ENV=local in the .env turns on the debug log.
$mysite_is_local = isset( $mysite_env['WP_ENV'] ) && 'local' === $mysite_env['WP_ENV'];
define( 'WP_DEBUG', $mysite_is_local );
define( 'WP_DEBUG_LOG', $mysite_is_local );
define( 'WP_DEBUG_DISPLAY', false );
// Only a local copy declares itself; production keeps WordPress' default
// ('production'). Local-only code checks wp_get_environment_type().
if ( $mysite_is_local ) {
	define( 'WP_ENVIRONMENT_TYPE', 'local' );
}

// Code only changes through the repo: no theme/plugin editor, no installs or
// updates from the dashboard, no automatic updates. Composer owns every version.
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );

// Outgoing mail through the domain's mail provider (WP Mail SMTP). Only when the
// .env has SMTP_HOST; without it WordPress keeps sending the default way. The
// plugin reads these constants, so nothing is stored in the database or editable
// from the dashboard.
if ( ! empty( $mysite_env['SMTP_HOST'] ) ) {
	define( 'WPMS_ON', true );
	define( 'WPMS_MAILER', 'smtp' );
	define( 'WPMS_SMTP_HOST', $mysite_env['SMTP_HOST'] );
	define( 'WPMS_SMTP_PORT', (int) ( $mysite_env['SMTP_PORT'] ?? 587 ) );
	define( 'WPMS_SSL', $mysite_env['SMTP_SECURE'] ?? 'tls' ); // Encryption: tls on port 587, ssl on 465, or empty.
	define( 'WPMS_SMTP_AUTOTLS', true );
	define( 'WPMS_SMTP_AUTH', true );
	define( 'WPMS_SMTP_USER', $mysite_env['SMTP_USER'] ?? '' );
	define( 'WPMS_SMTP_PASS', $mysite_env['SMTP_PASSWORD'] ?? '' );
	define( 'WPMS_MAIL_FROM', $mysite_env['SMTP_FROM'] ?? ( $mysite_env['SMTP_USER'] ?? '' ) );
	define( 'WPMS_MAIL_FROM_FORCE', true );
	define( 'WPMS_MAIL_FROM_NAME', $mysite_env['SMTP_FROM_NAME'] ?? 'My Site' );
	define( 'WPMS_MAIL_FROM_NAME_FORCE', true );
}

// Drop everything we parsed so that a plugin cannot read it from the global scope.
unset( $mysite_candidates, $mysite_env, $mysite_required, $mysite_missing, $mysite_key, $mysite_is_local );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
