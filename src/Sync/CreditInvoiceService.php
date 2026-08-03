<?php
/**
 * Creates credit sale invoices for WooCommerce refunds.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Sync;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Api\RemoteLookup;
use Aanndryyyy\EFinancialsPlugin\Meta\OrderMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Settings\SettingsRepository;
use Aanndryyyy\EFinancialsPlugin\Support\CountryCodes;
use Aanndryyyy\EFinancialsPlugin\Support\CreditRow;
use Aanndryyyy\EFinancialsPlugin\Support\InvoiceNumber;
use Aanndryyyy\EFinancialsPlugin\Support\Logger;
use Aanndryyyy\EFinancialsPlugin\Support\OrderMeta;
use Aanndryyyy\EFinancialsPlugin\Support\RefundAmounts;
use Aanndryyyy\EFinancialsPlugin\Support\TaxRates;
use EFinancialsClient\Enums\SaleInvoiceType;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Refund;
use WC_Product;

/**
 * Credit invoice create + register for full/partial refunds.
 */
class CreditInvoiceService {

	/**
	 * Provide arguments.
	 *
	 * @param ClientFactory        $client_factory API factory.
	 * @param RemoteLookup         $lookup         Cached API lookups.
	 * @param SettingsRepository   $settings       Settings.
	 * @param ProductEnsureService $products       Products.
	 * @param Logger               $logger         Logger.
	 */
	public function __construct(
		private readonly ClientFactory $client_factory,
		private readonly RemoteLookup $lookup,
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
		$prefix      = $this->lookup->series_number_prefix( $this->settings->invoice_series_id() );

		$payload = [
			'sale_invoice_type'       => SaleInvoiceType::CREDIT_INVOICE->value,
			'credit_sale_invoices_id' => $original_id,
			'cl_templates_id'         => $template_id,
			'clients_id'              => $clients_id,
			'cl_countries_id'         => CountryCodes::to_alpha3( $order->get_billing_country() ),
			'number_suffix'           => $this->original_suffix( $order, $prefix ),
			'create_date'             => $date,
			'journal_date'            => $date,
			'term_days'               => $this->settings->term_days(),
			'cl_currencies_id'        => $order->get_currency(),
			'show_client_balance'     => false,
			'notes'                   => 'WooCommerce refund #' . $refund->get_id() . ' for order #' . $order->get_order_number(),
			'items'                   => $items,
		];

		if ( $prefix !== '' ) {
			$payload['number_prefix'] = $prefix;
		}

		$client   = $this->client_factory->make();
		$response = $client->salesInvoices()->create( $payload );

		if ( ! $response->successful() || $response->createdObjectId === null ) {
			throw new RuntimeException(
				'Failed to create credit sale invoice: ' . \implode( '; ', $response->messages )
			);
		}

		$credit_id = $response->createdObjectId;
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
	 * Build the credited rows for a refund.
	 *
	 * Only what the refund actually covers is credited: itemised refunds map row
	 * by row, and an amount-only refund becomes a single row bounded by the
	 * refund total. The full order composition is never used as a fallback.
	 *
	 * @param WC_Order        $order  Order.
	 * @param WC_Order_Refund $refund Refund.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException When no items can be built.
	 */
	private function build_credit_items( WC_Order $order, WC_Order_Refund $refund ): array {

		$items = $this->build_itemised_credit( $refund );

		if ( $items === [] ) {
			$items = [ $this->build_amount_only_credit( $order, $refund ) ];
		}

		return \array_map( [ CreditRow::class, 'negate' ], $items );
	}

	/**
	 * Suffix of the order's original sale invoice.
	 *
	 * The credit repeats the original's suffix and the server derives the credit
	 * number itself by appending K, then K2, K3, … per further partial credit.
	 *
	 * Prefers the number the server actually assigned, so the credit still
	 * matches after a settings change; falls back to recomputing it the same
	 * way SaleInvoiceService does.
	 *
	 * The fallback is never fatal: the credit is linked to its original by
	 * `credit_sale_invoices_id`, so an unrecoverable suffix costs a tidy number,
	 * not a correct booking. Throwing here would push a permanent condition into
	 * the retry backoff, where it could never drain.
	 *
	 * @param WC_Order $order  Parent order.
	 * @param string   $prefix Series number prefix.
	 */
	private function original_suffix( WC_Order $order, string $prefix ): string {

		$number = OrderMeta::get_string( $order, OrderMetaKeys::SALE_INVOICE_NUMBER );

		if ( $number !== '' ) {
			$suffix = InvoiceNumber::suffix_from_number( $number, $prefix );

			if ( $suffix !== '' ) {
				return $suffix;
			}

			$this->logger->warning(
				'Original invoice number does not match the configured series; recomputing the credit suffix.',
				[
					'order_id' => $order->get_id(),
					'number'   => $number,
					'prefix'   => $prefix,
				]
			);
		}

		$raw = $this->settings->use_wc_order_number()
			? (string) $order->get_order_number()
			: (string) $order->get_id();

		return InvoiceNumber::suffix( $raw, $order->get_id() );
	}

	/**
	 * Map refunded lines, shipping and fees to credit rows.
	 *
	 * @param WC_Order_Refund $refund Refund.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException When a VAT rate or sale article cannot be resolved.
	 */
	private function build_itemised_credit( WC_Order_Refund $refund ): array {

		$items = [];

		foreach ( [ 'line_item', 'shipping', 'fee' ] as $type ) {
			foreach ( $refund->get_items( $type ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product
					&& ! $item instanceof WC_Order_Item_Shipping
					&& ! $item instanceof WC_Order_Item_Fee
				) {
					continue;
				}

				$net = \abs( (float) $item->get_total() );
				$tax = \abs( (float) $item->get_total_tax() );

				if ( $net <= 0.0 && $tax <= 0.0 ) {
					continue;
				}

				$qty = $item instanceof WC_Order_Item_Product
					? \abs( (float) $item->get_quantity() )
					: 1.0;

				$items[] = $this->products->build_row(
					$this->credit_products_id( $item ),
					$item->get_name(),
					$qty,
					$net,
					$tax,
					TaxRates::for_item( $item )
				);
			}
		}

		return $items;
	}

	/**
	 * Build the single row for a refund entered as an amount.
	 *
	 * @param WC_Order        $order  Order.
	 * @param WC_Order_Refund $refund Refund.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the amount is empty or the order mixes VAT rates.
	 */
	private function build_amount_only_credit( WC_Order $order, WC_Order_Refund $refund ): array {

		$gross = \abs( (float) $refund->get_amount() );

		if ( $gross <= 0.0 ) {
			throw new RuntimeException( 'Refund has no amount to credit.' );
		}

		$rate = TaxRates::single_order_rate( $order );

		if ( $rate === null ) {
			throw new RuntimeException(
				'Order mixes VAT rates, so an amount-only refund cannot be credited. Refund the individual line items instead.'
			);
		}

		$tax = \abs( (float) $refund->get_total_tax() );

		$amounts = $tax > 0.0
			? RefundAmounts::split( $gross, $tax )
			: RefundAmounts::split_at_rate( $gross, $rate );

		return $this->products->build_row(
			$this->products->ensure_generic_line_id(),
			\sprintf(
				/* translators: %d: refund id */
				__( 'Refund #%d', 'e-financials' ),
				$refund->get_id()
			),
			1.0,
			$amounts['net'],
			$amounts['tax'],
			$rate
		);
	}

	/**
	 * Resolve the catalogue product to credit for a refunded line.
	 *
	 * @param WC_Order_Item_Product|WC_Order_Item_Shipping|WC_Order_Item_Fee $item Refunded line.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function credit_products_id( object $item ): int {

		if ( $item instanceof WC_Order_Item_Product ) {
			$product = $item->get_product();

			if ( $product instanceof WC_Product ) {
				return $this->products->ensure_product( $product );
			}
		}

		if ( $item instanceof WC_Order_Item_Shipping ) {
			return $this->products->ensure_shipping_id();
		}

		if ( $item instanceof WC_Order_Item_Fee ) {
			return $this->products->ensure_fee_id();
		}

		return $this->products->ensure_generic_line_id();
	}
}
