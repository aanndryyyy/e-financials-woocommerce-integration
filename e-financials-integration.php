<?php
/**
 * E-Financials WooCommerce Intergration.
 *
 * @package Arbictus\EFinancialsPlugin
 *
 * @wordpress-plugin
 * Plugin Name: e-Financials WooCommerce Intergration
 * Plugin URI: https://github.com/aanndryyyy/e-financials-woocommerce-integration
 * Description: WooCommerce e-Financials integration for easy bookkeeping (E-arveldaja WooCommerce liidestus).
 * Version: 0.0.1
 *
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * WC requires at least: 7.0
 * WC tested up to: 7.4
 *
 * Text Domain: e-financials
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin;

use Aanndryyyy\EFinancialsPlugin\Main\Main;
use Aanndryyyy\EFinancialsPlugin\Plugin;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$loader = require __DIR__ . '/vendor/autoload.php';

/**
 * Declare HPOS compatibility early (before WooCommerce init).
 */
\add_action(
	'before_woocommerce_init',
	static function (): void {

		if ( ! \class_exists( FeaturesUtil::class ) ) {
			return;
		}

		FeaturesUtil::declare_compatibility( 'custom_order_tables', Plugin::file(), true );
	}
);

/**
 * Begins execution of the plugin once WooCommerce is available.
 */
\add_action(
	'plugins_loaded',
	static function () use ( $loader ): void {

		if ( ! \class_exists( Main::class ) || ! \class_exists( 'WooCommerce' ) ) {
			return;
		}

		( new Main( $loader->getPrefixesPsr4(), __NAMESPACE__ ) )->register();
	},
	20
);
