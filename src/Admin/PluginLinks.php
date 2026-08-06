<?php
/**
 * Plugin row action links.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Admin;

use Aanndryyyy\EFinancialsPlugin\Plugin;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceInterface;

/**
 * Settings shortcut on the plugins list.
 */
class PluginLinks implements ServiceInterface {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {

		$basename = \plugin_basename( Plugin::file() );
		\add_filter( 'plugin_action_links_' . $basename, [ $this, 'add_settings_link' ] );
	}

	/**
	 * Provide arguments.
	 *
	 * @param array<string, string> $links Links.
	 *
	 * @return array<string, string>
	 */
	public function add_settings_link( array $links ): array {

		$url = \admin_url( 'admin.php?page=wc-settings&tab=integration&section=efinancials_integration' );

		$links['settings'] = '<a href="' . \esc_url( $url ) . '">' . \esc_html__( 'Settings', 'e-financials' ) . '</a>';

		return $links;
	}
}
