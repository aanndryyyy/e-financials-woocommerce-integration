<?php
/**
 * Plugin path helpers.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin;

/**
 * Shared plugin constants.
 */
final class Plugin {

	/**
	 * Absolute path to the main plugin file.
	 */
	public static function file(): string {

		return \dirname( __DIR__ ) . '/e-financials-integration.php';
	}

	/**
	 * Absolute path to the plugin directory.
	 */
	public static function path(): string {

		return \dirname( __DIR__ );
	}
}
