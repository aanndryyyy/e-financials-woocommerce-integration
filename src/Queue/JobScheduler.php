<?php
/**
 * Enqueues background sync jobs via Action Scheduler (or WP-Cron fallback).
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Queue;

/**
 * Thin queue facade — keeps storefront hooks fast.
 */
class JobScheduler {

	public const HOOK_SYNC_ORDER = 'ef_sync_order_to_efinancials';

	public const HOOK_CREDIT_INVOICE = 'ef_credit_invoice_to_efinancials';

	public const HOOK_SWEEP_UNSYNCED = 'ef_sweep_unsynced_orders';

	public const GROUP = 'e-financials';

	/**
	 * Enqueue order sync job (idempotent if already pending).
	 *
	 * @param int $order_id Order ID.
	 */
	public function enqueue_sync_order( int $order_id ): void {

		$this->enqueue( self::HOOK_SYNC_ORDER, [ $order_id ] );
	}

	/**
	 * Enqueue credit invoice job for a refund.
	 *
	 * @param int $order_id  Order ID.
	 * @param int $refund_id Refund ID.
	 */
	public function enqueue_credit_invoice( int $order_id, int $refund_id ): void {

		$this->enqueue( self::HOOK_CREDIT_INVOICE, [ $order_id, $refund_id ] );
	}

	/**
	 * Ensure recurring sweep is scheduled.
	 */
	public function schedule_sweep(): void {

		if ( \function_exists( 'as_has_scheduled_action' ) && \function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! \as_has_scheduled_action( self::HOOK_SWEEP_UNSYNCED, [], self::GROUP ) ) {
				\as_schedule_recurring_action(
					\time() + 300,
					300,
					self::HOOK_SWEEP_UNSYNCED,
					[],
					self::GROUP
				);
			}

			return;
		}

		$next = \wp_next_scheduled( self::HOOK_SWEEP_UNSYNCED );

		if ( $next === false ) {
			\wp_schedule_event( \time() + 300, 'ef_every_five_minutes', self::HOOK_SWEEP_UNSYNCED );
		}
	}

	/**
	 * Provide arguments.
	 *
	 * @param string       $hook Hook name.
	 * @param array<mixed> $args Args.
	 */
	private function enqueue( string $hook, array $args ): void {

		if ( \function_exists( 'as_has_scheduled_action' ) && \function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! \as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				\as_enqueue_async_action( $hook, $args, self::GROUP );
			}

			return;
		}

		// Fallback: run soon via single WP-Cron event.
		/**
		 * Cron callback args.
		 *
		 * @var list<mixed> $cron_args
		 */
		$cron_args = \array_values( $args );
		\wp_schedule_single_event( \time() + 5, $hook, $cron_args );
	}
}
