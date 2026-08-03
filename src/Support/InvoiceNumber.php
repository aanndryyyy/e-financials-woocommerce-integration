<?php
/**
 * Invoice numbering helpers.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Support;

/**
 * The API rejects non-numeric invoice numbers, so every suffix is reduced to digits.
 */
final class InvoiceNumber {

	/**
	 * Maximum suffix length accepted by the API.
	 */
	private const MAX_LENGTH = 30;

	/**
	 * Reduce a raw suffix candidate to digits, falling back to the order id.
	 *
	 * Stores using order-number prefix plugins produce values such as "WC-1042";
	 * only the digits survive, and an entirely non-numeric value falls back to
	 * the (always numeric) order id.
	 *
	 * @param string $raw      Raw suffix candidate.
	 * @param int    $order_id Order ID used as fallback.
	 */
	public static function suffix( string $raw, int $order_id ): string {

		$digits = \preg_replace( '/\D+/', '', $raw ) ?? '';
		$digits = \ltrim( $digits, '0' );

		if ( $digits === '' ) {
			$digits = (string) $order_id;
		}

		return \substr( $digits, 0, self::MAX_LENGTH );
	}
}
