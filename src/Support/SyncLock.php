<?php
/**
 * Short-lived cross-process lock guarding a single order sync.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Support;

/**
 * Action Scheduler only de-duplicates *pending* actions, so a claimed job plus a
 * freshly enqueued one can run concurrently and register two invoices for one
 * order. The lock is a transient with a TTL so a fatal never leaves it stuck.
 */
final class SyncLock {

	/**
	 * Lock lifetime in seconds.
	 */
	public const TTL = 300;

	/**
	 * Try to claim the lock for a subject.
	 *
	 * @param string $key Lock key (for example "order-42").
	 *
	 * @return bool True when this process owns the lock.
	 */
	public static function acquire( string $key ): bool {

		$name = self::name( $key );

		if ( \get_transient( $name ) !== false ) {
			return false;
		}

		\set_transient( $name, \time(), self::TTL );

		return true;
	}

	/**
	 * Release a previously acquired lock.
	 *
	 * @param string $key Lock key.
	 */
	public static function release( string $key ): void {

		\delete_transient( self::name( $key ) );
	}

	/**
	 * Build the transient name for a lock key.
	 *
	 * @param string $key Lock key.
	 */
	private static function name( string $key ): string {

		return 'ef_sync_lock_' . \sanitize_key( $key );
	}
}
