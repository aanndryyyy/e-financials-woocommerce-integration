<?php
/**
 * Normalises API error text before it reaches merchants or customers.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Support;

/**
 * Raw server stack traces come back on 500s; those must never be pasted
 * verbatim into an order note, which is customer visible.
 */
final class ErrorMessage {

	/**
	 * Maximum length kept for display.
	 */
	private const MAX_LENGTH = 200;

	/**
	 * Collapse an exception message to a single short, trace-free line.
	 *
	 * @param string $message Raw message.
	 */
	public static function sanitize( string $message ): string {

		$message = \wp_strip_all_tags( $message );

		// Server traces start at the first stack frame or file path; drop everything after.
		$cut = \preg_split( '/\R|\bat [A-Za-z0-9_\\\\.]+\(|\/[A-Za-z0-9_\-\/]+\.(php|java|py):\d+/', $message );

		if ( \is_array( $cut ) && isset( $cut[0] ) ) {
			$message = $cut[0];
		}

		$message = \trim( \preg_replace( '/\s+/', ' ', $message ) ?? $message );

		if ( $message === '' ) {
			$message = __( 'Unknown e-Financials API error.', 'e-financials' );
		}

		if ( \mb_strlen( $message ) > self::MAX_LENGTH ) {
			// Byte-wise truncation would split multibyte characters (ä, ü, õ).
			$message = \rtrim( \mb_substr( $message, 0, self::MAX_LENGTH ) ) . '…';
		}

		return $message;
	}
}
