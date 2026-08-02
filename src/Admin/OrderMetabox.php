<?php
/**
 * Admin order metabox: sync status, PDF download, delivery.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Admin;

use Aanndryyyy\EFinancialsPlugin\Support\OrderMeta;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Meta\OrderMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Services\ServiceInterface;
use Aanndryyyy\EFinancialsPlugin\Sync\DeliveryService;
use Throwable;
use WC_Order;

/**
 * Metabox + AJAX PDF download.
 */
class OrderMetabox implements ServiceInterface {

	public const AJAX_PDF = 'ef_download_invoice_pdf';

	/**
	 * Provide arguments.
	 *
	 * @param DeliveryService $delivery Delivery service.
	 * @param ClientFactory   $clients  Client factory.
	 */
	public function __construct(
		private readonly DeliveryService $delivery,
		private readonly ClientFactory $clients
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {

		if ( ! \class_exists( 'WooCommerce' ) ) {
			return;
		}

		\add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ], 30 );
		\add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'render_hpos_panel' ], 20 );
		\add_action( 'wp_ajax_' . self::AJAX_PDF, [ $this, 'ajax_download_pdf' ] );
	}

	/**
	 * Legacy CPT metabox.
	 */
	public function add_meta_box(): void {

		\add_meta_box(
			'ef_order_metabox',
			__( 'e-Financials', 'e-financials' ),
			[ $this, 'render_metabox' ],
			'shop_order',
			'side',
			'default'
		);
	}

	/**
	 * Provide arguments.
	 *
	 * @param \WP_Post|WC_Order $post_or_order Post or order.
	 */
	public function render_metabox( $post_or_order ): void { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamTag

		$order = $post_or_order instanceof WC_Order
			? $post_or_order
			: \wc_get_order( $post_or_order->ID );

		if ( $order instanceof WC_Order ) {
			$this->render_status( $order );
		}
	}

	/**
	 * HPOS order screen panel.
	 *
	 * @param WC_Order $order Order.
	 */
	public function render_hpos_panel( WC_Order $order ): void {

		echo '<div class="order_data_column" style="clear:both;padding-top:1em">';
		echo '<h3>' . \esc_html__( 'e-Financials', 'e-financials' ) . '</h3>';
		$this->render_status( $order );
		echo '</div>';
	}

	/**
	 * Provide arguments.
	 *
	 * @param WC_Order $order Order.
	 */
	private function render_status( WC_Order $order ): void {

		$invoice_id = OrderMeta::get_int( $order, OrderMetaKeys::SALE_INVOICE_ID );
		$number     = OrderMeta::get_string( $order, OrderMetaKeys::SALE_INVOICE_NUMBER );
		$mode       = OrderMeta::get_string( $order, OrderMetaKeys::PAYMENT_MODE );
		$delivered  = OrderMeta::get_string( $order, OrderMetaKeys::DELIVERED_AT );
		$error      = OrderMeta::get_string( $order, OrderMetaKeys::LAST_ERROR );
		$credit     = OrderMeta::get_int( $order, OrderMetaKeys::CREDIT_SALE_INVOICE_ID );

		echo '<p><strong>' . \esc_html__( 'Invoice', 'e-financials' ) . ':</strong> ';
		echo $invoice_id > 0
			? \esc_html( $number !== '' ? $number : '#' . $invoice_id )
			: '&mdash;';
		echo '</p>';

		if ( $mode !== '' ) {
			echo '<p><strong>' . \esc_html__( 'Payment mode', 'e-financials' ) . ':</strong> ' . \esc_html( $mode ) . '</p>';
		}

		if ( $delivered !== '' ) {
			echo '<p><strong>' . \esc_html__( 'Delivered', 'e-financials' ) . ':</strong> ' . \esc_html( $delivered ) . '</p>';
		}

		if ( $credit > 0 ) {
			echo '<p><strong>' . \esc_html__( 'Credit invoice', 'e-financials' ) . ':</strong> #' . \esc_html( (string) $credit ) . '</p>';
		}

		if ( $error !== '' ) {
			echo '<p style="color:#b32d2e"><strong>' . \esc_html__( 'Last error', 'e-financials' ) . ':</strong> ' . \esc_html( $error ) . '</p>';
		}

		if ( $invoice_id > 0 && $this->clients->can_make() ) {
			$url = \wp_nonce_url(
				\admin_url( 'admin-ajax.php?action=' . self::AJAX_PDF . '&order_id=' . $order->get_id() ),
				self::AJAX_PDF
			);
			echo '<p><a class="button" href="' . \esc_url( $url ) . '">' . \esc_html__( 'Download PDF', 'e-financials' ) . '</a></p>';
		}
	}

	/**
	 * Stream system PDF to the browser.
	 */
	public function ajax_download_pdf(): void {

		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			\wp_die( \esc_html__( 'Forbidden', 'e-financials' ), 403 );
		}

		\check_admin_referer( self::AJAX_PDF );

		$order_id_raw = isset( $_GET['order_id'] ) ? \wp_unslash( $_GET['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$order_id     = \is_numeric( $order_id_raw ) ? (int) $order_id_raw : 0;
		$order        = \wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			\wp_die( \esc_html__( 'Order not found.', 'e-financials' ), 404 );
		}

		$invoice_id = OrderMeta::get_int( $order, OrderMetaKeys::SALE_INVOICE_ID );

		if ( $invoice_id <= 0 ) {
			\wp_die( \esc_html__( 'No e-Financials invoice on this order.', 'e-financials' ), 404 );
		}

		try {
			$file     = $this->delivery->get_system_pdf( $invoice_id );
			$binary   = \base64_decode( $file['contents'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- API returns base64 PDF.
			$contents = $binary !== false ? $binary : $file['contents'];

			\nocache_headers();
			\header( 'Content-Type: application/pdf' );
			\header( 'Content-Disposition: attachment; filename="' . \sanitize_file_name( $file['name'] ) . '"' );
			echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		} catch ( Throwable $e ) {
			\wp_die( \esc_html( $e->getMessage() ), 500 );
		}
	}
}
