<?php
/**
 * Creates credit sale invoices for WooCommerce refunds.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Sync;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Meta\OrderMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Settings\SettingsRepository;
use Aanndryyyy\EFinancialsPlugin\Support\CountryCodes;
use Aanndryyyy\EFinancialsPlugin\Support\Logger;
use Aanndryyyy\EFinancialsPlugin\Support\OrderMeta;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;

/**
 * Credit invoice create + register for full/partial refunds.
 */
class CreditInvoiceService {

	/**
	 * Provide arguments.
	 *
	 * @param ClientFactory        $client_factory API factory.
	 * @param SettingsRepository   $settings       Settings.
	 * @param ProductEnsureService $products       Products.
	 * @param Logger               $logger         Logger.
	 */
	public function __construct(
		private readonly ClientFactory $client_factory,
		private readonly SettingsRepository $settings,
		private readonly ProductEnsureService $products,
		private readonly Logger $logger
	) {
	}

	/**
	 * Create a credit sale invoice for a refund.
	 *
	 * @param WC_Order        $order  Parent order.
	 * @param WC_Order_Refund $refund Refund.
	 *
	 * @throws RuntimeException On API / validation failure.
	 */
	public function create_for_refund( WC_Order $order, WC_Order_Refund $refund ): int {

		$original_id = OrderMeta::get_int( $order, OrderMetaKeys::SALE_INVOICE_ID );

		if ( $original_id <= 0 ) {
			throw new RuntimeException( 'Cannot credit: original sale invoice is missing on the order.' );
		}

		$meta_key = OrderMetaKeys::refund_credit_id( $refund->get_id() );
		$existing = OrderMeta::get_int( $order, $meta_key );

		if ( $existing > 0 ) {
			return $existing;
		}

		$clients_id = OrderMeta::get_int( $order, OrderMetaKeys::CLIENTS_ID );

		if ( $clients_id <= 0 ) {
			throw new RuntimeException( 'Cannot credit: e-Financials client is missing on the order.' );
		}

		$template_id = $this->settings->template_id();

		if ( $template_id <= 0 ) {
			throw new RuntimeException( 'e-Financials invoice template is not configured.' );
		}

		$refund_date = $refund->get_date_created();
		$date        = $refund_date instanceof \WC_DateTime ? $refund_date->date( 'Y-m-d' ) : \gmdate( 'Y-m-d' );
		$items       = $this->build_credit_items( $order, $refund );

		$payload = [
			'sale_invoice_type'       => SaleInvoiceService::TYPE_CREDIT_INVOICE,
			'credit_sale_invoices_id' => $original_id,
			'cl_templates_id'         => $template_id,
			'clients_id'              => $clients_id,
			'cl_countries_id'         => CountryCodes::to_alpha3( $order->get_billing_country() ),
			'number_suffix'           => \substr( 'C' . $order->get_order_number() . '-' . $refund->get_id(), 0, 30 ),
			'create_date'             => $date,
			'journal_date'            => $date,
			'term_days'               => $this->settings->term_days(),
			'cl_currencies_id'        => $order->get_currency(),
			'show_client_balance'     => false,
			'notes'                   => 'WooCommerce refund #' . $refund->get_id() . ' for order #' . $order->get_order_number(),
			'items'                   => $items,
		];

		$client   = $this->client_factory->make();
		$response = $client->salesInvoices()->create( $payload );

		if ( ! $response->successful() || $response->createdObjectId === null ) {
			throw new RuntimeException(
				'Failed to create credit sale invoice: ' . \implode( '; ', $response->messages )
			);
		}

		$credit_id = (int) $response->createdObjectId;
		$register  = $client->salesInvoices()->register( $credit_id );

		if ( ! $register->successful() ) {
			throw new RuntimeException(
				'Failed to register credit sale invoice #' . $credit_id . ': ' . \implode( '; ', $register->messages )
			);
		}

		OrderMeta::set( $order, $meta_key, $credit_id );
		OrderMeta::set( $order, OrderMetaKeys::CREDIT_SALE_INVOICE_ID, $credit_id );
		$order->save();

		$order->add_order_note(
			\sprintf(
				/* translators: %d: credit invoice id */
				__( 'e-Financials credit sale invoice created (#%d).', 'e-financials' ),
				$credit_id
			)
		);

		$this->logger->info(
			'Created credit sale invoice.',
			[
				'order_id'  => $order->get_id(),
				'refund_id' => $refund->get_id(),
				'credit_id' => $credit_id,
			]
		);

		return $credit_id;
	}

	/**
	 * Provide arguments.
	 *
	 * @param WC_Order        $order  Order.
	 * @param WC_Order_Refund $refund Refund.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException When no items can be built.
	 */
	private function build_credit_items( WC_Order $order, WC_Order_Refund $refund ): array {

		$refund_items = $refund->get_items( 'line_item' );

		if ( $refund_items !== [] ) {
			// Rebuild using parent ensure logic on a temporary mapping of absolute amounts.
			$items = [];

			foreach ( $refund_items as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$qty = \abs( (float) $item->get_quantity() );
				$net = \abs( (float) $item->get_total() );
				$tax = \abs( (float) $item->get_total_tax() );

				if ( $qty <= 0 && $net <= 0 ) {
					continue;
				}

				if ( $qty <= 0 ) {
					$qty = 1;
				}

				// Prefer linked product from parent line when available.
				$products_id = 0;
				$product     = $item->get_product();

				if ( $product instanceof \WC_Product ) {
					$products_id = $this->products->ensure_product( $product );
				}

				if ( $products_id <= 0 ) {
					// Fall back to parent order invoice item rebuild for generic product.
					$parent_items = $this->products->build_invoice_items( $order );
					$raw_id       = $parent_items[0]['products_id'] ?? 0;
					$products_id  = \is_numeric( $raw_id ) ? (int) $raw_id : 0;
				}

				if ( $products_id <= 0 ) {
					continue;
				}

				$items[] = [
					'products_id'     => $products_id,
					'amount'          => $qty,
					'custom_title'    => $item->get_name(),
					'unit_net_price'  => \round( $net / $qty, 4 ),
					'total_net_price' => \round( $net, 4 ),
					'vat_amount'      => \round( $tax, 4 ),
				];
			}

			if ( $items !== [] ) {
				return $items;
			}
		}

		// Full refund without itemised lines — credit the whole original invoice composition.
		return $this->products->build_invoice_items( $order );
	}
}
