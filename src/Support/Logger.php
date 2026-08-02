<?php
/**
 * WooCommerce logger wrapper.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Support;

/**
 * Thin logger around WC_Logger.
 */
class Logger {

	private const SOURCE = 'e-financials';

	/**
	 * Log an informational message.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function info( string $message, array $context = [] ): void {

		$this->log( 'info', $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function error( string $message, array $context = [] ): void {

		$this->log( 'error', $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function warning( string $message, array $context = [] ): void {

		$this->log( 'warning', $message, $context );
	}

	/**
	 * Provide arguments.
	 *
	 * @param string               $level   Log level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	private function log( string $level, string $message, array $context ): void {

		if ( ! \function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger = \wc_get_logger();
		$logger->log(
			$level,
			$message . ( $context !== [] ? ' ' . \wp_json_encode( $context ) : '' ),
			[ 'source' => self::SOURCE ]
		);
	}
}
