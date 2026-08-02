<?php
/**
 * Typed helpers for WooCommerce order meta.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Support;

use WC_Order;

/**
 * Avoids mixed casts from WC_Data::get_meta().
 */
final class OrderMeta {

	/**
	 * Read integer meta.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Meta key.
	 */
	public static function get_int( WC_Order $order, string $key ): int {

		$value = $order->get_meta( $key, true );

		if ( \is_int( $value ) ) {
			return $value;
		}

		if ( \is_string( $value ) && \is_numeric( $value ) ) {
			return (int) $value;
		}

		if ( \is_float( $value ) ) {
			return (int) $value;
		}

		return 0;
	}

	/**
	 * Read string meta.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Meta key.
	 */
	public static function get_string( WC_Order $order, string $key ): string {

		$value = $order->get_meta( $key, true );

		if ( \is_string( $value ) ) {
			return $value;
		}

		if ( \is_numeric( $value ) ) {
			return (string) $value;
		}

		return '';
	}

	/**
	 * Write scalar meta as string (WC stub expects array|string).
	 *
	 * @param WC_Order   $order Order.
	 * @param string     $key   Meta key.
	 * @param int|string $value Value.
	 */
	public static function set( WC_Order $order, string $key, int|string $value ): void {

		$order->update_meta_data( $key, (string) $value );
	}
}
