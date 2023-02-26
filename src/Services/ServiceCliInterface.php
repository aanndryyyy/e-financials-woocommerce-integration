<?php
/**
 * File that holds Service interface.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Services;

/**
 * Interface Service WP-CLI.
 */
interface ServiceCliInterface {

	/**
	 * Register the current service.
	 *
	 * A register method holds the plugin action and filter hooks.
	 * Following the single responsibility principle, every class
	 * holds a functionality for a certain part of the plugin.
	 * This is why every class should hold its own hooks.
	 *
	 * @return void
	 */
	public function register(): void;
}
