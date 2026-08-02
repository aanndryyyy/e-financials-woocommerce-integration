<?php
/**
 * Orchestrates full order → e-Financials sync pipeline.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Sync;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Meta\OrderMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Support\Logger;
use Throwable;
use WC_Order;

/**
 * Client → products → invoice → register → payment → optional deliver.
 */
class OrderSyncService {

	/**
	 * Provide arguments.
	 *
	 * @param ClientFactory           $client_factory Client factory.
	 * @param ClientUpsertService     $clients        Client upsert.
	 * @param SaleInvoiceService      $invoices       Sale invoices.
	 * @param PaymentRecordingService $payments       Payments.
	 * @param DeliveryService         $delivery       Delivery.
	 * @param Logger                  $logger         Logger.
	 */
	public function __construct(
		private readonly ClientFactory $client_factory,
		private readonly ClientUpsertService $clients,
		private readonly SaleInvoiceService $invoices,
		private readonly PaymentRecordingService $payments,
		private readonly DeliveryService $delivery,
		private readonly Logger $logger
	) {
	}

	/**
	 * Sync a WooCommerce order to e-Financials.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return bool True on success / already synced.
	 *
	 * @throws \Throwable When the API sync fails (after persisting the error).
	 */
	public function sync_order( int $order_id ): bool {

		$order = \wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			$this->logger->error( 'Order sync skipped: order not found.', [ 'order_id' => $order_id ] );

			return false;
		}

		if ( ! $this->client_factory->can_make() ) {
			$this->logger->warning( 'Order sync skipped: credentials missing.', [ 'order_id' => $order_id ] );

			return false;
		}

		try {
			$clients_id = $this->clients->upsert_for_order( $order );
			$cash       = $this->payments->cash_fields_for_create( $order );
			$invoice_id = $this->invoices->create_and_register( $order, $clients_id, $cash );

			// Reload order meta after invoice write.
			$order = \wc_get_order( $order_id );

			if ( ! $order instanceof WC_Order ) {
				return false;
			}

			$this->payments->record_after_invoice( $order, $invoice_id, $clients_id );
			$this->delivery->maybe_auto_deliver( $order, $invoice_id );

			return true;
		} catch ( Throwable $e ) {
			$order->update_meta_data( OrderMetaKeys::LAST_ERROR, $e->getMessage() );
			$order->save();
			$order->add_order_note(
				\sprintf(
					/* translators: %s: error message */
					__( 'e-Financials sync failed: %s', 'e-financials' ),
					$e->getMessage()
				)
			);

			$this->logger->error(
				'Order sync failed.',
				[
					'order_id' => $order_id,
					'error'    => $e->getMessage(),
				]
			);

			throw $e;
		}
	}
}
