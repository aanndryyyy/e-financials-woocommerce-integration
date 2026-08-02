<?php
/**
 * Delivers registered sale invoices to customers via e-Financials.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Sync;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Meta\OrderMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Settings\SettingsRepository;
use Aanndryyyy\EFinancialsPlugin\Support\Logger;
use Aanndryyyy\EFinancialsPlugin\Support\OrderMeta;
use RuntimeException;
use WC_Order;

/**
 * PDF fetch + deliver (email / e-invoice).
 */
class DeliveryService {

	/**
	 * Provide arguments.
	 *
	 * @param ClientFactory      $client_factory API factory.
	 * @param SettingsRepository $settings       Settings.
	 * @param Logger             $logger         Logger.
	 */
	public function __construct(
		private readonly ClientFactory $client_factory,
		private readonly SettingsRepository $settings,
		private readonly Logger $logger
	) {
	}

	/**
	 * Auto-deliver when enabled in settings.
	 *
	 * @param WC_Order $order      Order.
	 * @param int      $invoice_id Sale invoice id.
	 */
	public function maybe_auto_deliver( WC_Order $order, int $invoice_id ): void {

		if ( ! $this->settings->auto_deliver() ) {
			return;
		}

		if ( OrderMeta::get_string( $order, OrderMetaKeys::DELIVERED_AT ) !== '' ) {
			return;
		}

		$this->deliver( $order, $invoice_id, $this->settings->auto_deliver_einvoice() );
	}

	/**
	 * Deliver invoice email and optionally e-invoice.
	 *
	 * @param WC_Order $order         Order.
	 * @param int      $invoice_id    Sale invoice id.
	 * @param bool     $send_einvoice Whether to send machine XML.
	 *
	 * @throws RuntimeException On API failure.
	 */
	public function deliver( WC_Order $order, int $invoice_id, bool $send_einvoice = false ): void {

		$client  = $this->client_factory->make();
		$options = $client->salesInvoices()->getDeliveryOptions( $invoice_id );

		$send_email = $options->canSendEmail;
		$email      = $order->get_billing_email();

		if ( $email === '' && $options->canSendEmailAddresses !== null && $options->canSendEmailAddresses !== '' ) {
			$email = $options->canSendEmailAddresses;
		}

		if ( $send_einvoice && ! $options->canSendEinvoice ) {
			$send_einvoice = false;
			$this->logger->warning(
				'E-invoice delivery not available for invoice.',
				[
					'invoice_id' => $invoice_id,
					'reason'     => (string) ( $options->canSendEinvoiceReason ?? '' ),
				]
			);
		}

		if ( ! $send_email && ! $send_einvoice ) {
			throw new RuntimeException( 'No delivery channels available for this invoice.' );
		}

		$payload = [
			'send_email'      => $send_email && $email !== '',
			'send_einvoice'   => $send_einvoice,
			'email_addresses' => $email,
		];

		$response = $client->salesInvoices()->deliver( $invoice_id, $payload );

		if ( ! $response->successful() ) {
			throw new RuntimeException(
				'Failed to deliver sale invoice: ' . \implode( '; ', $response->messages )
			);
		}

		$order->update_meta_data( OrderMetaKeys::DELIVERED_AT, \gmdate( 'c' ) );
		$order->save();

		$order->add_order_note( __( 'e-Financials invoice delivered to customer.', 'e-financials' ) );

		$this->logger->info(
			'Delivered sale invoice.',
			[
				'order_id'   => $order->get_id(),
				'invoice_id' => $invoice_id,
			]
		);
	}

	/**
	 * Fetch system PDF binary contents.
	 *
	 * @param int $invoice_id Sale invoice id.
	 *
	 * @return array{name: string, contents: string}
	 *
	 * @throws RuntimeException On API failure.
	 */
	public function get_system_pdf( int $invoice_id ): array {

		$client = $this->client_factory->make();
		$file   = $client->salesInvoices()->getSystemPdf( $invoice_id );

		return [
			'name'     => $file->name !== '' ? $file->name : 'invoice-' . $invoice_id . '.pdf',
			'contents' => $file->contents,
		];
	}
}
