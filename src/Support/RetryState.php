<?php
/**
 * Failure counting and exponential backoff for background sync jobs.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Support;

use Aanndryyyy\EFinancialsPlugin\Meta\OrderMetaKeys;
use WC_Order;

/**
 * Without this, a deterministically failing order retries every five minutes
 * forever — 288 API calls and 288 identical order notes per day.
 */
final class RetryState {

	/**
	 * First retry delay in seconds; doubles per attempt.
	 */
	private const BASE_DELAY = 300;

	/**
	 * Upper bound for a retry delay (12 hours).
	 */
	private const MAX_DELAY = 43200;

	/**
	 * Attempts after which the order is left for manual action.
	 */
	public const MAX_ATTEMPTS = 8;

	/**
	 * Default scope: the order sync pipeline.
	 */
	public const SCOPE_SYNC = '';

	/**
	 * Scope for a refund's credit invoice job.
	 *
	 * Credit invoices fail independently of the order sync, so they must not
	 * consume the sync's attempts or delay its retries.
	 *
	 * @param int $refund_id Refund ID.
	 */
	public static function scope_refund( int $refund_id ): string {

		return '_refund_' . $refund_id;
	}

	/**
	 * Whether the subject may be attempted right now.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $scope Retry scope.
	 */
	public static function is_due( WC_Order $order, string $scope = self::SCOPE_SYNC ): bool {

		if ( self::attempts( $order, $scope ) >= self::MAX_ATTEMPTS ) {
			return false;
		}

		return OrderMeta::get_int( $order, OrderMetaKeys::NEXT_ATTEMPT_AT . $scope ) <= \time();
	}

	/**
	 * Current failure count.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $scope Retry scope.
	 */
	public static function attempts( WC_Order $order, string $scope = self::SCOPE_SYNC ): int {

		return OrderMeta::get_int( $order, OrderMetaKeys::ATTEMPTS . $scope );
	}

	/**
	 * Record a failure and schedule the next attempt.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $message Sanitised failure message.
	 * @param string   $scope   Retry scope.
	 *
	 * @return bool True when the merchant should be notified (first failure or a new message).
	 */
	public static function record_failure( WC_Order $order, string $message, string $scope = self::SCOPE_SYNC ): bool {

		$attempts = self::attempts( $order, $scope ) + 1;
		$previous = OrderMeta::get_string( $order, OrderMetaKeys::LAST_ERROR . $scope );

		OrderMeta::set( $order, OrderMetaKeys::ATTEMPTS . $scope, $attempts );
		OrderMeta::set( $order, OrderMetaKeys::NEXT_ATTEMPT_AT . $scope, \time() + self::delay( $attempts ) );
		OrderMeta::set( $order, OrderMetaKeys::LAST_ERROR . $scope, $message );

		// The metabox and order column always read the unscoped key.
		OrderMeta::set( $order, OrderMetaKeys::LAST_ERROR, $message );

		return $previous !== $message;
	}

	/**
	 * Clear retry state after a successful run.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $scope Retry scope.
	 */
	public static function clear( WC_Order $order, string $scope = self::SCOPE_SYNC ): void {

		$order->delete_meta_data( OrderMetaKeys::ATTEMPTS . $scope );
		$order->delete_meta_data( OrderMetaKeys::NEXT_ATTEMPT_AT . $scope );
		$order->delete_meta_data( OrderMetaKeys::LAST_ERROR . $scope );
		$order->delete_meta_data( OrderMetaKeys::LAST_ERROR );
	}

	/**
	 * Exponential backoff delay for an attempt number.
	 *
	 * @param int $attempts Attempts made so far.
	 */
	public static function delay( int $attempts ): int {

		$exponent = \max( 0, $attempts - 1 );
		$delay    = self::BASE_DELAY * ( 1 << \min( $exponent, 16 ) );

		return \min( $delay, self::MAX_DELAY );
	}
}
