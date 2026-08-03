<?php
/**
 * Registers Action Scheduler / cron callbacks for sync jobs.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Queue;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Meta\OrderMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceInterface;
use Aanndryyyy\EFinancialsPlugin\Support\ErrorMessage;
use Aanndryyyy\EFinancialsPlugin\Support\Logger;
use Aanndryyyy\EFinancialsPlugin\Support\RetryState;
use Aanndryyyy\EFinancialsPlugin\Support\SyncLock;
use Aanndryyyy\EFinancialsPlugin\Sync\CreditInvoiceService;
use Aanndryyyy\EFinancialsPlugin\Sync\OrderSyncService;
use Throwable;
use WC_Order;
use WC_Order_Refund;

/**
 * Background job runners.
 */
class RegisterJobs implements ServiceInterface {

	/**
	 * Provide arguments.
	 *
	 * @param JobScheduler         $scheduler Job scheduler.
	 * @param OrderSyncService     $orders    Order sync.
	 * @param CreditInvoiceService $credits   Credit sync.
	 * @param ClientFactory        $clients   Client factory.
	 * @param Logger               $logger    Logger.
	 */
	public function __construct(
		private readonly JobScheduler $scheduler,
		private readonly OrderSyncService $orders,
		private readonly CreditInvoiceService $credits,
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

		\add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
		\add_action( 'init', [ $this->scheduler, 'schedule_sweep' ], 20 );

		\add_action( JobScheduler::HOOK_SYNC_ORDER, [ $this, 'handle_sync_order' ], 10, 1 );
		\add_action( JobScheduler::HOOK_CREDIT_INVOICE, [ $this, 'handle_credit_invoice' ], 10, 2 );
		\add_action( JobScheduler::HOOK_SWEEP_UNSYNCED, [ $this, 'handle_sweep' ] );
	}

	/**
	 * Provide arguments.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Schedules.
	 *
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_cron_schedules( array $schedules ): array {

		$schedules['ef_every_five_minutes'] = [
			'interval' => 300,
			'display'  => __( 'Every five minutes', 'e-financials' ),
		];

		return $schedules;
	}

	/**
	 * Provide arguments.
	 *
	 * @param int|string $order_id Order ID.
	 */
	public function handle_sync_order( int|string $order_id ): void {

		$this->orders->sync_order( (int) $order_id );
	}

	/**
	 * Provide arguments.
	 *
	 * @param int|string $order_id  Order ID.
	 * @param int|string $refund_id Refund ID.
	 *
	 * @throws \Throwable When credit creation fails.
	 */
	public function handle_credit_invoice( int|string $order_id, int|string $refund_id ): void {

		$order  = \wc_get_order( (int) $order_id );
		$refund = \wc_get_order( (int) $refund_id );

		if ( ! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund ) {
			$this->logger->error(
				'Credit job skipped: order/refund missing.',
				[
					'order_id'  => (int) $order_id,
					'refund_id' => (int) $refund_id,
				]
			);

			return;
		}

		$lock = 'refund-' . (int) $refund_id;

		if ( ! SyncLock::acquire( $lock ) ) {
			return;
		}

		try {
			$this->credits->create_for_refund( $order, $refund );
		} catch ( Throwable $e ) {
			$message = ErrorMessage::sanitize( $e->getMessage() );

			if ( RetryState::record_failure( $order, $message ) ) {
				$order->add_order_note(
					\sprintf(
						/* translators: %s: error message */
						__( 'e-Financials credit invoice failed: %s', 'e-financials' ),
						$message
					)
				);
			}

			$order->save();
			$this->logger->error(
				'Credit invoice job failed.',
				[
					'order_id'  => (int) $order_id,
					'refund_id' => (int) $refund_id,
					'error'     => $e->getMessage(),
				]
			);

			throw $e;
		} finally {
			SyncLock::release( $lock );
		}
	}

	/**
	 * Sweep paid orders whose sync pipeline never completed.
	 *
	 * The marker is SYNC_COMPLETE rather than the invoice id, so an order whose
	 * invoice registered but whose payment recording failed is still retried.
	 */
	public function handle_sweep(): void {

		if ( ! $this->clients->can_make() ) {
			return;
		}

		$orders = \wc_get_orders(
			[
				'limit'      => 20,
				'status'     => [ 'wc-processing', 'wc-completed' ],
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => OrderMetaKeys::SYNC_COMPLETE,
						'compare' => 'NOT EXISTS',
					],
				],
				'return'     => 'objects',
			]
		);

		if ( ! \is_array( $orders ) ) {
			return;
		}

		/**
		 * Order objects from the query.
		 *
		 * @var array<int, WC_Order> $orders
		 */
		foreach ( $orders as $order ) {
			// Backing-off orders must not burn a job slot every five minutes.
			if ( ! RetryState::is_due( $order ) ) {
				continue;
			}

			$this->scheduler->enqueue_sync_order( $order->get_id() );
		}
	}
}
