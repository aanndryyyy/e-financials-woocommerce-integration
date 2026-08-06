<?php
/**
 * Credit invoice row sign convention.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Support;

/**
 * A credit row is a sale row with the credited quantity carrying the sign.
 */
final class CreditRow {

	/**
	 * Signed fields. `unit_net_price` is deliberately absent: the unit price
	 * stays positive and the negative `amount` is what makes the row a credit.
	 */
	private const SIGNED = [ 'amount', 'total_net_price', 'vat_amount' ];

	/**
	 * Turn a sale row into a credit row.
	 *
	 * The server recomputes `total_net_price` and `vat_amount` from `amount`
	 * and the row's sale article — verified on demo: a credit row for a 24%
	 * article came back `total_net_price: -100`, `vat_amount: -24`, and the
	 * invoice booked `net -100 / VAT -24 / gross -124`. The totals are negated
	 * here anyway so the payload never disagrees with what the server books.
	 *
	 * Negation is `-abs()` rather than unary minus, so applying this to a row
	 * that already carries credit signs is a no-op instead of flipping it back
	 * into a sale.
	 *
	 * @param array<string, mixed> $row Sale row from ProductEnsureService::build_row().
	 *
	 * @return array<string, mixed>
	 */
	public static function negate( array $row ): array {

		foreach ( self::SIGNED as $key ) {
			if ( isset( $row[ $key ] ) && \is_numeric( $row[ $key ] ) ) {
				$row[ $key ] = -\abs( (float) $row[ $key ] );
			}
		}

		return $row;
	}
}
