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

	/**
	 * Recover the suffix that was used for an already-created invoice number.
	 *
	 * A credit invoice must repeat its original's suffix — the server derives
	 * the credit number itself by appending `K` (then `K2`, `K3`, … for further
	 * partial credits). Recomputing the suffix from current settings would drift
	 * if `use_wc_order_number` was toggled after the original was sent, so the
	 * stored number is the source of truth when it is available.
	 *
	 * Returns an empty string whenever the suffix cannot be recovered — a number
	 * from another series, whose prefix would otherwise be folded into the
	 * suffix as digits, or one carrying no significant digits — which the
	 * caller distinguishes from a never-synced order and handles by recomputing.
	 * Unlike `suffix()`, this never falls back to the order id: a value invented
	 * here would look like a recovered suffix.
	 *
	 * @param string $number Stored invoice number, e.g. "ARB-1042".
	 * @param string $prefix Series number prefix, e.g. "ARB-".
	 */
	public static function suffix_from_number( string $number, string $prefix ): string {

		if ( $prefix !== '' ) {
			if ( ! \str_starts_with( $number, $prefix ) ) {
				return '';
			}

			$number = \substr( $number, \strlen( $prefix ) );
		}

		$digits = \preg_replace( '/\D+/', '', $number ) ?? '';

		return \substr( \ltrim( $digits, '0' ), 0, self::MAX_LENGTH );
	}
}
