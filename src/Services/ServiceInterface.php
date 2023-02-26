<?php
/**
 * File that holds Service interface.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Services;

/**
 * Interface Service.
 */
interface ServiceInterface {

	/**
	 * Register the current service.
	 *
	 * A register method holds the plugin action and filter hooks.
	 * Following the single responsibility principle, every class
	 * holds a functionality for a certain part of the plugin.
	 * This is why every class should hold its own hooks.
	 */
	public function register(): void;
}
