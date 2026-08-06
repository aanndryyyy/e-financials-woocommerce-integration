<?php
/**
 * Adds e-Financials invoice info to customer emails.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Admin;

use Aanndryyyy\EFinancialsPlugin\Support\OrderMeta;

use Aanndryyyy\EFinancialsPlugin\Meta\OrderMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceInterface;
use WC_Order;

/**
 * Woocommerce_email_after_order_table note.
 */
class EmailNote implements ServiceInterface {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {

		if ( ! \class_exists( 'WooCommerce' ) ) {
			return;
		}

		\add_action( 'woocommerce_email_after_order_table', [ $this, 'render' ], 20, 4 );
	}

	/**
	 * Provide arguments.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Admin email.
	 * @param bool     $plain_text    Plain text.
	 * @param mixed    $email         Email object.
	 */
	public function render( WC_Order $order, bool $sent_to_admin, bool $plain_text, mixed $email ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		$number = OrderMeta::get_string( $order, OrderMetaKeys::SALE_INVOICE_NUMBER );
		$id     = OrderMeta::get_int( $order, OrderMetaKeys::SALE_INVOICE_ID );

		if ( $id <= 0 && $number === '' ) {
			return;
		}

		$label = $number !== '' ? $number : '#' . $id;

		if ( $plain_text ) {
			echo "\n" . \esc_html( \sprintf( /* translators: %s: invoice number */ __( 'e-Financials invoice: %s', 'e-financials' ), $label ) ) . "\n";

			return;
		}

		echo '<p>' . \esc_html( \sprintf( /* translators: %s: invoice number */ __( 'e-Financials invoice: %s', 'e-financials' ), $label ) ) . '</p>';
	}
}
