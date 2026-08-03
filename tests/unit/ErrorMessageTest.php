<?php
/**
 * Error text sanitisation.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Tests\Unit;

use Aanndryyyy\EFinancialsPlugin\Support\ErrorMessage;
use PHPUnit\Framework\TestCase;

/**
 * Order notes are customer visible, so server traces must never reach them.
 */
final class ErrorMessageTest extends TestCase {

	/**
	 * A Postgres traceback is reduced to its first line.
	 */
	public function test_stack_trace_is_dropped(): void {

		$raw = "System failure: null value in column \"number\" violates not-null constraint\n"
			. "  at org.postgresql.core.QueryExecutorImpl.receiveErrorResponse(QueryExecutorImpl.java:2725)\n"
			. '  at org.postgresql.jdbc.PgStatement.execute(PgStatement.java:512)';

		$this->assertSame(
			'System failure: null value in column "number" violates not-null constraint',
			ErrorMessage::sanitize( $raw )
		);
	}

	/**
	 * Long single-line messages are truncated.
	 */
	public function test_long_message_is_truncated(): void {

		$message = ErrorMessage::sanitize( str_repeat( 'a', 400 ) );

		$this->assertSame( 201, mb_strlen( $message ) );
		$this->assertStringEndsWith( '…', $message );
	}

	/**
	 * An empty message still says something useful.
	 */
	public function test_empty_message_gets_a_fallback(): void {

		$this->assertSame( 'Unknown e-Financials API error.', ErrorMessage::sanitize( '   ' ) );
	}

	/**
	 * Markup is stripped before display.
	 */
	public function test_markup_is_stripped(): void {

		$this->assertSame( 'boom', ErrorMessage::sanitize( '<b>boom</b>' ) );
	}
}
