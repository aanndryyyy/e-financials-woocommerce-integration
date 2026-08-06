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

	/**
	 * A credit invoice reuses the original's suffix, stripped of the series prefix.
	 */
	public function test_suffix_is_recovered_from_a_stored_number(): void {

		$this->assertSame( '1042', InvoiceNumber::suffix_from_number( 'ARB-1042', 'ARB-' ) );
	}

	/**
	 * Digits inside the prefix are not mistaken for the suffix.
	 */
	public function test_digits_in_the_prefix_are_stripped_with_it(): void {

		$this->assertSame( '1042', InvoiceNumber::suffix_from_number( '2026-1042', '2026-' ) );
	}

	/**
	 * A number from another series is refused rather than mis-stripped.
	 */
	public function test_number_without_the_current_prefix_is_refused(): void {

		$this->assertSame( '', InvoiceNumber::suffix_from_number( '2026-1042', 'ARB-' ) );
	}

	/**
	 * A missing stored number yields nothing, so the caller can fall back.
	 */
	public function test_empty_number_yields_empty_suffix(): void {

		$this->assertSame( '', InvoiceNumber::suffix_from_number( '', 'ARB-' ) );
	}
}
