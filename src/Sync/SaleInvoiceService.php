<?php
/**
 * Creates and registers e-Financials sale invoices from WooCommerce orders.
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
use Aanndryyyy\EFinancialsPlugin\Support\InvoiceNumber;
use Aanndryyyy\EFinancialsPlugin\Support\Logger;
use Aanndryyyy\EFinancialsPlugin\Support\OrderMeta;
use RuntimeException;
use WC_Order;

/**
 * Sale invoice create + register.
 */
class SaleInvoiceService {

	public const TYPE_INVOICE = 'INVOICE';

	public const TYPE_CREDIT_INVOICE = 'CREDIT_INVOICE';

	/**
	 * Provide arguments.
	 *
	 * @param ClientFactory        $client_factory API factory.
	 * @param RemoteLookup         $lookup         Cached API lookups.
	 * @param SettingsRepository   $settings       Settings.
	 * @param ProductEnsureService $products       Product ensure service.
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
	 * Create and register a sale invoice for an order.
	 *
	 * @param WC_Order             $order      Order.
	 * @param int                  $clients_id e-Financials client id.
	 * @param array<string, mixed> $cash_fields Optional paid_in_cash fields for Option A.
	 *
	 * @throws RuntimeException On API / validation failure.
	 */
	public function create_and_register( WC_Order $order, int $clients_id, array $cash_fields = [] ): int {

		$existing = OrderMeta::get_int( $order, OrderMetaKeys::SALE_INVOICE_ID );

		if ( $existing > 0 ) {
			return $existing;
		}

		$template_id = $this->settings->template_id();

		if ( $template_id <= 0 ) {
			throw new RuntimeException( 'e-Financials invoice template is not configured.' );
		}

		$date  = $this->order_date( $order );
		$items = $this->products->build_invoice_items( $order );

		$payload = \array_merge(
			[
				'sale_invoice_type'   => self::TYPE_INVOICE,
				'cl_templates_id'     => $template_id,
				'clients_id'          => $clients_id,
				'cl_countries_id'     => CountryCodes::to_alpha3( $order->get_billing_country() ),
				'number_suffix'       => $this->number_suffix( $order ),
				'create_date'         => $date,
				'journal_date'        => $date,
				'term_days'           => $this->settings->term_days(),
				'cl_currencies_id'    => $order->get_currency(),
				'show_client_balance' => false,
				'notes'               => 'WooCommerce order #' . $order->get_order_number(),
				'contract_number'     => (string) $order->get_id(),
				'items'               => $items,
			],
			$cash_fields
		);

		$prefix = $this->lookup->series_number_prefix( $this->settings->invoice_series_id() );

		if ( $prefix !== '' ) {
			$payload['number_prefix'] = $prefix;
		}

		$client   = $this->client_factory->make();
		$response = $client->salesInvoices()->create( $payload );

		if ( ! $response->successful() || $response->createdObjectId === null ) {
			throw new RuntimeException(
				'Failed to create sale invoice: ' . \implode( '; ', $response->messages )
			);
		}

		$invoice_id = (int) $response->createdObjectId;
		$register   = $client->salesInvoices()->register( $invoice_id );

		if ( ! $register->successful() ) {
			throw new RuntimeException(
				'Failed to register sale invoice #' . $invoice_id . ': ' . \implode( '; ', $register->messages )
			);
		}

		$number = '';

		try {
			$fetched = $client->salesInvoices()->get( $invoice_id );
			$number  = $fetched->number ?? '';
		} catch ( \Throwable $e ) {
			// The invoice is registered; only its human-readable number is missing.
			$this->logger->warning(
				'Could not read back the registered invoice number.',
				[
					'invoice_id' => $invoice_id,
					'error'      => $e->getMessage(),
				]
			);
		}

		OrderMeta::set( $order, OrderMetaKeys::SALE_INVOICE_ID, $invoice_id );

		if ( $number !== '' ) {
			OrderMeta::set( $order, OrderMetaKeys::SALE_INVOICE_NUMBER, $number );
		}

		$order->update_meta_data( OrderMetaKeys::SYNCED_AT, \gmdate( 'c' ) );
		$order->delete_meta_data( OrderMetaKeys::LAST_ERROR );
		$order->save();

		$order->add_order_note(
			\sprintf(
				/* translators: 1: invoice id, 2: invoice number */
				__( 'e-Financials sale invoice created (#%1$d%2$s).', 'e-financials' ),
				$invoice_id,
				$number !== '' ? ' / ' . $number : ''
			)
		);

		$this->logger->info(
			'Created and registered sale invoice.',
			[
				'order_id'   => $order->get_id(),
				'invoice_id' => $invoice_id,
			]
		);

		return $invoice_id;
	}

	/**
	 * Build invoice number suffix.
	 *
	 * @param WC_Order $order Order.
	 */
	private function number_suffix( WC_Order $order ): string {

		$raw = $this->settings->use_wc_order_number()
			? (string) $order->get_order_number()
			: (string) $order->get_id();

		return InvoiceNumber::suffix( $raw, $order->get_id() );
	}

	/**
	 * Resolve invoice date from the order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function order_date( WC_Order $order ): string {

		$created = $order->get_date_created();

		if ( $created instanceof \WC_DateTime ) {
			return $created->date( 'Y-m-d' );
		}

		return \gmdate( 'Y-m-d' );
	}
}
