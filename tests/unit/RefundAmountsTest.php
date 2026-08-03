<?php
/**
 * Amount-only refund maths.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Tests\Unit;

use Aanndryyyy\EFinancialsPlugin\Support\RefundAmounts;
use PHPUnit\Framework\TestCase;

/**
 * A €10 refund must credit €10 — never the order total.
 */
final class RefundAmountsTest extends TestCase {

	/**
	 * Recorded refund tax is used as-is.
	 */
	public function test_split_uses_recorded_tax(): void {

		$this->assertSame(
			[
				'net' => 8.2,
				'tax' => 1.8,
			],
			RefundAmounts::split( -10.0, -1.8 )
		);
	}

	/**
	 * Tax can never exceed the refunded amount.
	 */
	public function test_split_clamps_tax_to_gross(): void {

		$this->assertSame(
			[
				'net' => 0.0,
				'tax' => 5.0,
			],
			RefundAmounts::split( 5.0, 7.5 )
		);
	}

	/**
	 * A gross amount at a known rate is split inclusive of VAT.
	 */
	public function test_split_at_rate_extracts_vat(): void {

		$result = RefundAmounts::split_at_rate( 24.0, 20.0 );

		$this->assertSame( 20.0, $result['net'] );
		$this->assertSame( 4.0, $result['tax'] );
	}

	/**
	 * A zero-rated order refunds entirely as net.
	 */
	public function test_split_at_rate_without_vat(): void {

		$this->assertSame(
			[
				'net' => 12.5,
				'tax' => 0.0,
			],
			RefundAmounts::split_at_rate( 12.5, 0.0 )
		);
	}
}
