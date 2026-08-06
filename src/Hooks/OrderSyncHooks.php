<?php
/**
 * WooCommerce hooks that enqueue order sync jobs.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Hooks;

use Aanndryyyy\EFinancialsPlugin\Support\OrderMeta;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Meta\OrderMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Queue\JobScheduler;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceInterface;
use WC_Order;

/**
 * Payment_complete + completed status → background sync.
 */
class OrderSyncHooks implements ServiceInterface {

	/**
	 * Provide arguments.
	 *
	 * @param JobScheduler  $scheduler Job scheduler.
	 * @param ClientFactory $clients   Client factory.
	 */
	public function __construct(
		private readonly JobScheduler $scheduler,
		private readonly ClientFactory $clients
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {

		if ( ! \class_exists( 'WooCommerce' ) ) {
			return;
		}

		\add_action( 'woocommerce_payment_complete', [ $this, 'on_payment_complete' ], 20, 1 );
		\add_action( 'woocommerce_order_status_completed', [ $this, 'on_status_completed' ], 20, 1 );
	}

	/**
	 * Provide arguments.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_payment_complete( int $order_id ): void {

		$this->maybe_enqueue( $order_id );
	}

	/**
	 * Provide arguments.
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_status_completed( int $order_id ): void {

		$this->maybe_enqueue( $order_id );
	}

	/**
	 * Provide arguments.
	 *
	 * @param int $order_id Order ID.
	 */
	private function maybe_enqueue( int $order_id ): void {

		if ( ! $this->clients->can_make() ) {
			return;
		}

		$order = \wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( OrderMeta::get_int( $order, OrderMetaKeys::SALE_INVOICE_ID ) > 0 ) {
			return;
		}

		$this->scheduler->enqueue_sync_order( $order_id );
	}
}
