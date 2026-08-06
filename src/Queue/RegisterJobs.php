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
use Automattic\WooCommerce\Utilities\OrderUtil;
use Throwable;
use WC_Order;
use WC_Order_Refund;

/**
 * Background job runners.
 */
class RegisterJobs implements ServiceInterface {

	/**
	 * Private wc_get_orders() flag standing in for the meta_query that post
	 * storage discards. Never sent to HPOS, which rejects unknown query vars.
	 */
	private const PENDING_SYNC_QUERY_VAR = 'ef_pending_sync';

	/**
	 * How many candidates the sweep reads, and how many it may enqueue. The gap
	 * absorbs the backing-off orders filtered out in PHP, so a run of permanently
	 * failing orders cannot starve the ones behind them.
	 */
	private const SWEEP_BATCH = 60;

	private const SWEEP_ENQUEUE_LIMIT = 20;

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
		\add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ $this, 'expand_pending_sync_query' ], 10, 2 );
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

		$lock  = 'refund-' . (int) $refund_id;
		$scope = RetryState::scope_refund( (int) $refund_id );

		if ( ! RetryState::is_due( $order, $scope ) || ! SyncLock::acquire( $lock ) ) {
			return;
		}

		try {
			$this->credits->create_for_refund( $order, $refund );
			RetryState::clear( $order, $scope );
			$order->save();
		} catch ( Throwable $e ) {
			$message = ErrorMessage::sanitize( $e->getMessage() );

			if ( RetryState::record_failure( $order, $message, $scope ) ) {
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
	 * Meta clauses selecting orders the sweep still owes work on.
	 *
	 * Deliberately only one clause, on one key. The backoff window wants
	 * OR( NOT EXISTS, <= now ) over NEXT_ATTEMPT_AT, but the HPOS query builder
	 * folds a NOT EXISTS and a value comparison on the same key into a single
	 * join, which silently drops every order that has no such meta row — that
	 * is, exactly the never-synced orders this sweep exists to catch. So the
	 * backoff check stays in PHP, and the batch is over-fetched to compensate.
	 *
	 * @return array<int|string, mixed>
	 */
	private static function pending_sync_meta_query(): array {

		return [
			[
				'key'     => OrderMetaKeys::SYNC_COMPLETE,
				'compare' => 'NOT EXISTS',
			],
		];
	}

	/**
	 * Translate the sweep's query flag into a meta_query for post storage.
	 *
	 * @param array<string, mixed> $wp_query_args WP_Query arguments.
	 * @param array<string, mixed> $query_vars    Original wc_get_orders() vars.
	 *
	 * @return array<string, mixed>
	 */
	public function expand_pending_sync_query( array $wp_query_args, array $query_vars ): array {

		if ( ( $query_vars[ self::PENDING_SYNC_QUERY_VAR ] ?? false ) !== true ) {
			return $wp_query_args;
		}

		$existing = isset( $wp_query_args['meta_query'] ) && \is_array( $wp_query_args['meta_query'] )
			? $wp_query_args['meta_query']
			: [];

		$existing[] = self::pending_sync_meta_query();

		$wp_query_args['meta_query'] = $existing; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		return $wp_query_args;
	}

	/**
	 * Whether orders live in the HPOS tables rather than in posts.
	 */
	private function orders_table_in_use(): bool {

		return \class_exists( OrderUtil::class )
			&& OrderUtil::custom_orders_table_usage_is_enabled();
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

		$args = [
			'limit'  => self::SWEEP_BATCH,
			// Refund posts also carry wc-completed; without this they eat the
			// window and never leave room for the orders behind them.
			'type'   => 'shop_order',
			'status' => [ 'wc-processing', 'wc-completed' ],
			'return' => 'objects',
		];

		if ( $this->orders_table_in_use() ) {
			// HPOS reads meta_query straight off the query vars.
			$args['meta_query'] = self::pending_sync_meta_query(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		} else {
			// The post-storage store does not: WC_Data_Store_WP::get_wp_query_args()
			// skips the meta_query key outright, so passing one here is a silent
			// no-op. Flag the query instead and expand it in the documented filter.
			$args[ self::PENDING_SYNC_QUERY_VAR ] = true;
		}

		$orders = \wc_get_orders( $args );

		if ( ! \is_array( $orders ) ) {
			return;
		}

		/**
		 * Order objects from the query.
		 *
		 * @var array<int, WC_Order> $orders
		 */
		$enqueued = 0;

		foreach ( $orders as $order ) {
			if ( $enqueued >= self::SWEEP_ENQUEUE_LIMIT ) {
				break;
			}

			// Belt and braces for the type arg above: a refund reaching this loop
			// is a WC_Order_Refund, which fatals on the WC_Order hint below, and
			// query filters can widen what comes back regardless of the arg.
			if ( $order->get_type() !== 'shop_order' ) {
				continue;
			}

			// Backing-off orders must not burn a job slot every five minutes.
			if ( ! RetryState::is_due( $order ) ) {
				continue;
			}

			$this->scheduler->enqueue_sync_order( $order->get_id() );
			++$enqueued;
		}
	}
}
