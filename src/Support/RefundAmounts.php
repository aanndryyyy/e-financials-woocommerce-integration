<?php
/**
 * Splits an amount-only refund into the net/tax pair an invoice row needs.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Support;

/**
 * WooCommerce's "refund amount" box produces a refund with no line items; the
 * credited amount must come from the refund total, never from the order total.
 */
final class RefundAmounts {

	/**
	 * Split a gross refund into net and tax.
	 *
	 * @param float $gross Gross refunded amount (sign ignored).
	 * @param float $tax   Refunded tax (sign ignored).
	 *
	 * @return array{net: float, tax: float}
	 */
	public static function split( float $gross, float $tax ): array {

		$gross = \round( \abs( $gross ), 4 );
		$tax   = \round( \abs( $tax ), 4 );

		if ( $tax > $gross ) {
			$tax = $gross;
		}

		return [
			'net' => \round( $gross - $tax, 4 ),
			'tax' => $tax,
		];
	}

	/**
	 * Derive the tax portion of a gross amount for a known VAT rate.
	 *
	 * Only used when WooCommerce recorded no refunded tax but the order is taxed
	 * at a single known rate — the rate is read from WooCommerce, not guessed.
	 *
	 * @param float $gross Gross refunded amount.
	 * @param float $rate  VAT percentage.
	 *
	 * @return array{net: float, tax: float}
	 */
	public static function split_at_rate( float $gross, float $rate ): array {

		$gross = \round( \abs( $gross ), 4 );

		if ( $rate <= 0.0 ) {
			return [
				'net' => $gross,
				'tax' => 0.0,
			];
		}

		$net = \round( $gross / ( 1.0 + ( $rate / 100.0 ) ), 4 );

		return [
			'net' => $net,
			'tax' => \round( $gross - $net, 4 ),
		];
	}
}
