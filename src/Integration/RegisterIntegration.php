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

namespace Aanndryyyy\EFinancialsPlugin\Integration;

use Aanndryyyy\EFinancialsPlugin\Services;

/**
 * The main start class.
 */
class RegisterIntegration implements Services\ServiceInterface {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {

		if ( ! \class_exists( 'WC_Integration' ) ) {
			return;
		}

		\add_filter( 'woocommerce_integrations', [ $this, 'woocommerce_integrations_callback' ] );
	}

	/**
	 * Add a new integration to WooCommerce.
	 *
	 * @param array<class-string> $integrations The array of integrations.
	 *
	 * @return array<class-string>
	 */
	public function woocommerce_integrations_callback( array $integrations ): array {

		$integrations[] = EFinancialsIntegration::class;

		return $integrations;
	}
}
