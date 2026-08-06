<?php
/**
 * Retry backoff schedule.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Tests\Unit;

use Aanndryyyy\EFinancialsPlugin\Support\RetryState;
use PHPUnit\Framework\TestCase;

/**
 * A deterministically failing order must stop hammering the API.
 */
final class RetryStateTest extends TestCase {

	/**
	 * The first retry waits one sweep interval and then doubles.
	 */
	public function test_delay_doubles_per_attempt(): void {

		$this->assertSame( 300, RetryState::delay( 1 ) );
		$this->assertSame( 600, RetryState::delay( 2 ) );
		$this->assertSame( 1200, RetryState::delay( 3 ) );
	}

	/**
	 * Backoff is capped so orders are still retried daily.
	 */
	public function test_delay_is_capped(): void {

		$this->assertSame( 43200, RetryState::delay( 12 ) );
	}
}
