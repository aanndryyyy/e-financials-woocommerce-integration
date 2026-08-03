<?php
/**
 * Credit row sign convention.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Tests\Unit;

use Aanndryyyy\EFinancialsPlugin\Support\CreditRow;
use PHPUnit\Framework\TestCase;

/**
 * A credit row must book negative, or the refund lands as a second sale.
 */
final class CreditRowTest extends TestCase {

	/**
	 * An itemised row: quantity and totals go negative, the unit price does not.
	 */
	public function test_itemised_row_is_negated_except_the_unit_price(): void {

		$row = CreditRow::negate(
			[
				'products_id'     => 7,
				'amount'          => 2.0,
				'unit_net_price'  => 50.0,
				'total_net_price' => 100.0,
				'vat_rate'        => 24.0,
				'vat_amount'      => 24.0,
			]
		);

		$this->assertSame( -2.0, $row['amount'] );
		$this->assertSame( -100.0, $row['total_net_price'] );
		$this->assertSame( -24.0, $row['vat_amount'] );
		$this->assertSame( 50.0, $row['unit_net_price'] );
		$this->assertSame( 24.0, $row['vat_rate'] );
		$this->assertSame( 7, $row['products_id'] );
	}

	/**
	 * The amount-only row carries quantity 1 and the whole refund as its price.
	 */
	public function test_amount_only_row_credits_a_single_unit(): void {

		$row = CreditRow::negate(
			[
				'amount'          => 1.0,
				'unit_net_price'  => 8.2,
				'total_net_price' => 8.2,
				'vat_amount'      => 1.8,
			]
		);

		$this->assertSame( -1.0, $row['amount'] );
		$this->assertSame( -8.2, $row['total_net_price'] );
		$this->assertSame( -1.8, $row['vat_amount'] );
		$this->assertSame( 8.2, $row['unit_net_price'] );
	}

	/**
	 * Negating twice must not flip the row back into a sale.
	 */
	public function test_negation_is_idempotent(): void {

		$once  = CreditRow::negate(
			[
				'amount'          => 1.0,
				'total_net_price' => 10.0,
				'vat_amount'      => 2.4,
			]
		);
		$twice = CreditRow::negate( $once );

		$this->assertSame( $once, $twice );
	}

	/**
	 * A zero-VAT row stays at zero rather than picking up a sign.
	 */
	public function test_absent_and_zero_fields_are_left_alone(): void {

		$row = CreditRow::negate(
			[
				'amount'     => 1.0,
				'vat_amount' => 0.0,
			]
		);

		$this->assertSame( 0.0, $row['vat_amount'] );
		$this->assertArrayNotHasKey( 'total_net_price', $row );
	}
}
