<?php
/**
 * Declares WooCommerce HPOS compatibility.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Compatibility;

use Aanndryyyy\EFinancialsPlugin\Plugin;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceInterface;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * Before_woocommerce_init HPOS declaration.
 *
 * Note: also declared early in the main plugin file so it runs even if
 * service registration is delayed.
 */
class HposCompatibility implements ServiceInterface {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {

		\add_action( 'before_woocommerce_init', [ $this, 'declare_compatibility' ] );
	}

	/**
	 * Declare custom order tables compatibility.
	 */
	public function declare_compatibility(): void {

		if ( ! \class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', Plugin::file(), true );
	}
}
