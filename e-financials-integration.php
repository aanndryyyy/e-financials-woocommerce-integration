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
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 * WC tested up to: 7.4
 *
 * Text Domain: e-financials
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin;

use Aanndryyyy\EFinancialsPlugin\Main\Main;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$loader = require __DIR__ . '/vendor/autoload.php';

/**
 * Begins execution of the plugin.
 */
if ( \class_exists( Main::class ) ) {
	( new Main( $loader->getPrefixesPsr4(), __NAMESPACE__ ) )->register();
}
