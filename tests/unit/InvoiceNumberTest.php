<?php
/**
 * Invoice suffix sanitisation.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Tests\Unit;

use Aanndryyyy\EFinancialsPlugin\Support\InvoiceNumber;
use PHPUnit\Framework\TestCase;

/**
 * The API rejects "Invoice number (WC-1042) must be numeric!".
 */
final class InvoiceNumberTest extends TestCase {

	/**
	 * Plain numeric order numbers pass through.
	 */
	public function test_numeric_order_number_is_kept(): void {

		$this->assertSame( '1042', InvoiceNumber::suffix( '1042', 1042 ) );
	}

	/**
	 * Order-number prefix plugins are reduced to their digits.
	 */
	public function test_prefixed_order_number_is_reduced_to_digits(): void {

		$this->assertSame( '1042', InvoiceNumber::suffix( 'WC-1042', 77 ) );
	}

	/**
	 * A fully non-numeric value falls back to the order id.
	 */
	public function test_non_numeric_falls_back_to_order_id(): void {

		$this->assertSame( '77', InvoiceNumber::suffix( 'INVOICE', 77 ) );
	}

	/**
	 * Leading zeros are dropped rather than sent as a padded number.
	 */
	public function test_leading_zeros_are_dropped(): void {

		$this->assertSame( '42', InvoiceNumber::suffix( 'ORD-0042', 7 ) );
	}
}
