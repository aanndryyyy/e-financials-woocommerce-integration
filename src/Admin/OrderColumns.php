<?php
/**
 * Orders list columns (legacy + HPOS).
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
 * Invoice number / payment mode / error columns.
 */
class OrderColumns implements ServiceInterface {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {

		if ( ! \class_exists( 'WooCommerce' ) ) {
			return;
		}

		\add_filter( 'manage_edit-shop_order_columns', [ $this, 'add_column' ], 20 );
		\add_action( 'manage_shop_order_posts_custom_column', [ $this, 'render_legacy_column' ], 20, 2 );

		\add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'add_column' ], 20 );
		\add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'render_hpos_column' ], 20, 2 );
	}

	/**
	 * Provide arguments.
	 *
	 * @param array<string, string> $columns Columns.
	 *
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {

		$columns['ef_invoice'] = __( 'e-Financials', 'e-financials' );

		return $columns;
	}

	/**
	 * Provide arguments.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Post ID.
	 */
	public function render_legacy_column( string $column, int $post_id ): void {

		if ( $column !== 'ef_invoice' ) {
			return;
		}

		$order = \wc_get_order( $post_id );

		if ( $order instanceof WC_Order ) {
			echo \wp_kses_post( $this->cell_html( $order ) );
		}
	}

	/**
	 * Provide arguments.
	 *
	 * @param string       $column Column id.
	 * @param WC_Order|int $order  Order.
	 */
	public function render_hpos_column( string $column, WC_Order|int $order ): void {

		if ( $column !== 'ef_invoice' ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = \wc_get_order( $order );
		}

		if ( $order instanceof WC_Order ) {
			echo \wp_kses_post( $this->cell_html( $order ) );
		}
	}

	/**
	 * Provide arguments.
	 *
	 * @param WC_Order $order Order.
	 */
	private function cell_html( WC_Order $order ): string {

		$number = OrderMeta::get_string( $order, OrderMetaKeys::SALE_INVOICE_NUMBER );
		$id     = OrderMeta::get_int( $order, OrderMetaKeys::SALE_INVOICE_ID );
		$mode   = OrderMeta::get_string( $order, OrderMetaKeys::PAYMENT_MODE );
		$credit = OrderMeta::get_int( $order, OrderMetaKeys::CREDIT_SALE_INVOICE_ID );
		$error  = OrderMeta::get_string( $order, OrderMetaKeys::LAST_ERROR );

		if ( $id <= 0 && $error === '' ) {
			return '&mdash;';
		}

		$parts = [];

		if ( $number !== '' || $id > 0 ) {
			$parts[] = \esc_html( $number !== '' ? $number : '#' . $id );
		}

		if ( $mode !== '' ) {
			$parts[] = \esc_html( $mode );
		}

		if ( $credit > 0 ) {
			$parts[] = \esc_html__( 'credit', 'e-financials' );
		}

		if ( $error !== '' ) {
			$parts[] = '<span style="color:#b32d2e" title="' . \esc_attr( $error ) . '">' . \esc_html__( 'error', 'e-financials' ) . '</span>';
		}

		return \implode( ' · ', $parts );
	}
}
