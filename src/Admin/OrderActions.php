<?php
/**
 * Manual order actions for send / deliver / PDF.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Admin;

use Aanndryyyy\EFinancialsPlugin\Support\OrderMeta;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Meta\OrderMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceInterface;
use Aanndryyyy\EFinancialsPlugin\Sync\DeliveryService;
use Aanndryyyy\EFinancialsPlugin\Sync\OrderSyncService;
use Throwable;
use WC_Order;

/**
 * Woocommerce_order_actions entries.
 */
class OrderActions implements ServiceInterface {

	public const ACTION_SYNC = 'ef_send_to_efinancials';

	public const ACTION_DELIVER = 'ef_deliver_invoice';

	public const ACTION_PDF = 'ef_download_pdf';

	/**
	 * Provide arguments.
	 *
	 * @param OrderSyncService $sync     Sync service (for immediate admin sync).
	 * @param DeliveryService  $delivery Delivery.
	 * @param ClientFactory    $clients  Client factory.
	 */
	public function __construct(
		private readonly OrderSyncService $sync,
		private readonly DeliveryService $delivery,
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

		\add_filter( 'woocommerce_order_actions', [ $this, 'register_actions' ] );
		\add_action( 'woocommerce_order_action_' . self::ACTION_SYNC, [ $this, 'handle_sync' ] );
		\add_action( 'woocommerce_order_action_' . self::ACTION_DELIVER, [ $this, 'handle_deliver' ] );
		\add_action( 'woocommerce_order_action_' . self::ACTION_PDF, [ $this, 'handle_pdf' ] );
	}

	/**
	 * Provide arguments.
	 *
	 * @param array<string, string> $actions Actions.
	 *
	 * @return array<string, string>
	 */
	public function register_actions( array $actions ): array {

		if ( ! $this->clients->can_make() ) {
			return $actions;
		}

		$actions[ self::ACTION_SYNC ]    = __( 'Send / resend to e-Financials', 'e-financials' );
		$actions[ self::ACTION_DELIVER ] = __( 'Deliver e-Financials invoice', 'e-financials' );
		$actions[ self::ACTION_PDF ]     = __( 'Download e-Financials PDF', 'e-financials' );

		return $actions;
	}

	/**
	 * Provide arguments.
	 *
	 * @param WC_Order $order Order.
	 */
	public function handle_sync( WC_Order $order ): void {

		try {
			// Clear invoice id only when forcing resend via deleting? Keep idempotent — sync skips if present.
			// For resend after failure, just run again.
			$this->sync->sync_order( $order->get_id() );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Error already stored on order by OrderSyncService.
		}
	}

	/**
	 * Provide arguments.
	 *
	 * @param WC_Order $order Order.
	 */
	public function handle_deliver( WC_Order $order ): void {

		$invoice_id = OrderMeta::get_int( $order, OrderMetaKeys::SALE_INVOICE_ID );

		if ( $invoice_id <= 0 ) {
			$order->add_order_note( __( 'Cannot deliver: no e-Financials invoice on this order.', 'e-financials' ) );
			$order->save();

			return;
		}

		try {
			$this->delivery->deliver( $order, $invoice_id, false );
		} catch ( Throwable $e ) {
			$order->update_meta_data( OrderMetaKeys::LAST_ERROR, $e->getMessage() );
			$order->add_order_note(
				\sprintf(
					/* translators: %s: error */
					__( 'e-Financials deliver failed: %s', 'e-financials' ),
					$e->getMessage()
				)
			);
			$order->save();
		}
	}

	/**
	 * Provide arguments.
	 *
	 * @param WC_Order $order Order.
	 */
	public function handle_pdf( WC_Order $order ): void {

		$invoice_id = OrderMeta::get_int( $order, OrderMetaKeys::SALE_INVOICE_ID );

		if ( $invoice_id <= 0 ) {
			$order->add_order_note( __( 'Cannot download PDF: no e-Financials invoice on this order.', 'e-financials' ) );
			$order->save();

			return;
		}

		// Store a one-shot admin notice flag; actual download goes through metabox AJAX for binary output.
		$order->add_order_note(
			\sprintf(
				/* translators: %d: invoice id */
				__( 'Use the e-Financials metabox to download PDF for invoice #%d.', 'e-financials' ),
				$invoice_id
			)
		);
		$order->save();
	}
}
