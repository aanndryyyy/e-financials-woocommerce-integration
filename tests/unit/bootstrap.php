<?php
/**
 * Bootstrap for WordPress-free unit tests.
 *
 * Only the handful of WordPress functions the pure helpers touch are stubbed;
 * anything needing a real WordPress runtime belongs in the e2e suite instead.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Minimal stand-in for the WordPress helper.
	 *
	 * @param string $text Raw text.
	 */
	function wp_strip_all_tags( string $text ): string {

		return trim( (string) strip_tags( $text ) );
	}
}

if ( ! class_exists( 'WC_Integration' ) ) {
	/**
	 * Stub base class so the settings-key constants can be autoloaded.
	 */
	class WC_Integration { // phpcs:ignore
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * In-memory option store driven by $GLOBALS['ef_test_options'].
	 *
	 * @param string $key      Option name.
	 * @param mixed  $fallback Default value.
	 */
	function get_option( string $key, mixed $fallback = false ): mixed {

		return $GLOBALS['ef_test_options'][ $key ] ?? $fallback;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Identity translation stub.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore

		unset( $domain );

		return $text;
	}
}
