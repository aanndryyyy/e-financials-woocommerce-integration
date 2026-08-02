<?php
/**
 * WooCommerce refund hooks that enqueue credit invoice jobs.
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
 * Full + partial refund → credit sale invoice.
 */
class RefundHooks implements ServiceInterface {

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

		\add_action( 'woocommerce_order_fully_refunded', [ $this, 'on_fully_refunded' ], 20, 2 );
		\add_action( 'woocommerce_order_partially_refunded', [ $this, 'on_partially_refunded' ], 20, 2 );
	}

	/**
	 * Provide arguments.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function on_fully_refunded( int $order_id, int $refund_id ): void {

		$this->maybe_enqueue( $order_id, $refund_id );
	}

	/**
	 * Provide arguments.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function on_partially_refunded( int $order_id, int $refund_id ): void {

		$this->maybe_enqueue( $order_id, $refund_id );
	}

	/**
	 * Provide arguments.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	private function maybe_enqueue( int $order_id, int $refund_id ): void {

		if ( ! $this->clients->can_make() || $refund_id <= 0 ) {
			return;
		}

		$order = \wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( OrderMeta::get_int( $order, OrderMetaKeys::SALE_INVOICE_ID ) <= 0 ) {
			return;
		}

		if ( OrderMeta::get_int( $order, OrderMetaKeys::refund_credit_id( $refund_id ) ) > 0 ) {
			return;
		}

		$this->scheduler->enqueue_credit_invoice( $order_id, $refund_id );
	}
}
