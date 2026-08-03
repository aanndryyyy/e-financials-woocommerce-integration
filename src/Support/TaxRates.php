<?php
/**
 * Resolves the exact VAT rate WooCommerce applied to an order line.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Support;

use RuntimeException;
use WC_Abstract_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Tax;

/**
 * Invoice lines are legally binding, so rates are read from WooCommerce's own
 * tax rows — never inferred from the tax/net ratio.
 */
final class TaxRates {

	/**
	 * Resolve the VAT percentage applied to a single order line.
	 *
	 * @param WC_Order_Item_Product|WC_Order_Item_Shipping|WC_Order_Item_Fee $item Order line.
	 *
	 * @throws RuntimeException When a taxed line has no resolvable rate.
	 */
	public static function for_item( WC_Order_Item_Product|WC_Order_Item_Shipping|WC_Order_Item_Fee $item ): float {

		$rate_ids = self::rate_ids( $item );
		$tax      = \abs( (float) $item->get_total_tax() );

		if ( $rate_ids === [] ) {
			if ( $tax > 0.0 ) {
				throw new RuntimeException(
					\sprintf(
						/* translators: %s: line item name */
						__( 'Cannot resolve the VAT rate for line "%s": it carries tax but no WooCommerce tax rate.', 'e-financials' ),
						$item->get_name()
					)
				);
			}

			return 0.0;
		}

		return self::sum_rates( self::percentages( $rate_ids, $item->get_name() ) );
	}

	/**
	 * Resolve the single VAT rate used across a whole order.
	 *
	 * Used for amount-only refunds, where there is no line to read. Returns null
	 * when the order mixes rates — such a refund cannot be credited without lines.
	 *
	 * @param WC_Abstract_Order $order Order.
	 */
	public static function single_order_rate( WC_Abstract_Order $order ): ?float {

		$rate_ids = [];

		foreach ( [ 'line_item', 'shipping', 'fee' ] as $type ) {
			foreach ( $order->get_items( $type ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product
					&& ! $item instanceof WC_Order_Item_Shipping
					&& ! $item instanceof WC_Order_Item_Fee
				) {
					continue;
				}

				foreach ( self::rate_ids( $item ) as $rate_id ) {
					$rate_ids[ $rate_id ] = $rate_id;
				}
			}
		}

		if ( $rate_ids === [] ) {
			return 0.0;
		}

		$percentages = self::percentages( \array_values( $rate_ids ), 'order' );
		$unique      = \array_unique( $percentages );

		return \count( $unique ) === 1 ? (float) \reset( $unique ) : null;
	}

	/**
	 * Sum component rates into the effective line rate.
	 *
	 * @param array<int, float> $percentages Rate percentages.
	 */
	public static function sum_rates( array $percentages ): float {

		return \round( \array_sum( $percentages ), 2 );
	}

	/**
	 * Tax rate ids that actually applied to a line.
	 *
	 * @param WC_Order_Item_Product|WC_Order_Item_Shipping|WC_Order_Item_Fee $item Order line.
	 *
	 * @return array<int, int>
	 */
	private static function rate_ids( WC_Order_Item_Product|WC_Order_Item_Shipping|WC_Order_Item_Fee $item ): array {

		$taxes = $item->get_taxes();
		$total = isset( $taxes['total'] ) && \is_array( $taxes['total'] )
			? $taxes['total']
			: [];
		$ids   = [];

		foreach ( $total as $rate_id => $amount ) {
			if ( ! \is_numeric( $rate_id ) ) {
				continue;
			}

			// WooCommerce keeps a key for every rate matched, including zero-value ones.
			if ( $amount === '' || $amount === null ) {
				continue;
			}

			$ids[] = (int) $rate_id;
		}

		return $ids;
	}

	/**
	 * Look up rate percentages, failing loudly on a missing rate row.
	 *
	 * @param array<int, int> $rate_ids Tax rate ids.
	 * @param string          $context  Context used in the error message.
	 *
	 * @return array<int, float>
	 *
	 * @throws RuntimeException When a rate row cannot be read.
	 */
	private static function percentages( array $rate_ids, string $context ): array {

		$percentages = [];

		foreach ( $rate_ids as $rate_id ) {
			$rate = WC_Tax::_get_tax_rate( $rate_id );

			if ( ! \is_array( $rate ) || ! isset( $rate['tax_rate'] ) || ! \is_numeric( $rate['tax_rate'] ) ) {
				throw new RuntimeException(
					\sprintf(
						/* translators: 1: tax rate id, 2: line item name */
						__( 'WooCommerce tax rate #%1$d used by "%2$s" no longer exists; refusing to guess the VAT rate.', 'e-financials' ),
						$rate_id,
						$context
					)
				);
			}

			$percentages[] = \round( (float) $rate['tax_rate'], 2 );
		}

		return $percentages;
	}
}
