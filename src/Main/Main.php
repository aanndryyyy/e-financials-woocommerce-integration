<?php
/**
 * The file that defines the main start class.
 *
 * A class definition that includes attributes and functions used across both the
 * theme-facing side of the site and the admin area.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Main;

use Aanndryyyy\EFinancialsPlugin\Main\AbstractMain;

/**
 * The main start class.
 */
class Main extends AbstractMain {

	/**
	 * Register the project with the WordPress system.
	 *
	 * The register_service method will call the register() method in every service class,
	 * which holds the actions and filters - effectively replacing the need to manually add
	 * them in one place.
	 */
	public function register(): void {

		\add_action( 'plugins_loaded', [ $this, 'register_services' ] );
	}
}
