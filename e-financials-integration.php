<?php
/**
 * Bootstraps the e-Financials for WooCommerce plugin.
 *
 * @package Arbictus\EFinancialsPlugin
 *
 * @copyright 2026 Arbictus OÜ
 * @license   GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name: e-Financials for WooCommerce
 * Plugin URI: https://github.com/aanndryyyy/e-financials-woocommerce-integration
 * Description: Bookkeeping sync between your shop and e-Financials (E-arveldaja liidestus).
 * Version: 0.0.1
 * Author: Arbictus OÜ
 * Author URI: https://arbictus.eu
 *
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * WC requires at least: 7.0
 * WC tested up to: 7.4
 *
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
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

require_once __DIR__ . '/vendor/autoload.php';

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
	static function (): void {

		if ( ! \class_exists( Main::class ) || ! \class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Composer caches the loader, so this hands back the instance created above.
		$loader = require __DIR__ . '/vendor/autoload.php';

		( new Main( $loader->getPrefixesPsr4(), __NAMESPACE__ ) )->register();
	},
	20
);
