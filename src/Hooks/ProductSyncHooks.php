<?php
/**
 * Opt-in product upsert hooks.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Hooks;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceInterface;
use Aanndryyyy\EFinancialsPlugin\Settings\SettingsRepository;
use Aanndryyyy\EFinancialsPlugin\Support\Logger;
use Aanndryyyy\EFinancialsPlugin\Sync\ProductEnsureService;
use Throwable;
use WC_Product;

/**
 * Woocommerce_new_product / update → ensure e-Financials product.
 */
class ProductSyncHooks implements ServiceInterface {

	/**
	 * Provide arguments.
	 *
	 * @param ProductEnsureService $products Product ensure.
	 * @param SettingsRepository   $settings Settings.
	 * @param ClientFactory        $clients  Client factory.
	 * @param Logger               $logger   Logger.
	 */
	public function __construct(
		private readonly ProductEnsureService $products,
		private readonly SettingsRepository $settings,
		private readonly ClientFactory $clients,
		private readonly Logger $logger
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {

		if ( ! \class_exists( 'WooCommerce' ) ) {
			return;
		}

		\add_action( 'woocommerce_new_product', [ $this, 'on_product_saved' ], 20, 1 );
		\add_action( 'woocommerce_update_product', [ $this, 'on_product_saved' ], 20, 1 );
	}

	/**
	 * Provide arguments.
	 *
	 * @param int $product_id Product ID.
	 */
	public function on_product_saved( int $product_id ): void {

		if ( ! $this->settings->product_auto_sync() || ! $this->clients->can_make() ) {
			return;
		}

		$product = \wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		try {
			$this->products->ensure_product( $product );
		} catch ( Throwable $e ) {
			$this->logger->error(
				'Product auto-sync failed.',
				[
					'product_id' => $product_id,
					'error'      => $e->getMessage(),
				]
			);
		}
	}
}
