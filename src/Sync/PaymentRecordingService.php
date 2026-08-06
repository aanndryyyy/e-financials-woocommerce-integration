<?php
/**
 * Records order payments in e-Financials (cash fields or transactions).
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
 * Gateway-agnostic payment recording (Option A cash / Option B transactions).
 */
class PaymentRecordingService {

	/**
	 * Incoming payment ("Laekumine") — money in.
	 *
	 * @see docs/accounting-workflow.md §3.5 Option B spike
	 */
	public const TRANSACTION_TYPE_INCOMING = 'D';

	/**
	 * Distribution related_table for sale invoices.
	 */
	public const RELATED_TABLE_SALE_INVOICES = 'sale_invoices';

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
	 * Cash fields to merge into sale invoice create (Option A).
	 *
	 * Empty array means "do not set cash fields" (unpaid / off / transaction mode).
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return array<string, mixed>
	 */
	public function cash_fields_for_create( WC_Order $order ): array {

		$config = $this->settings->payment_config_for_method( $order->get_payment_method() );

		if ( $config['mode'] !== SettingsRepository::PAYMENT_MODE_CASH || ! $order->is_paid() ) {
			return [];
		}

		if ( $config['cash_accounts_id'] <= 0 ) {
			$this->logger->warning(
				'Cash payment mode selected but cash_accounts_id is missing; skipping cash fields.',
				[ 'order_id' => $order->get_id() ]
			);

			return [];
		}

		$paid = $order->get_date_paid();

		return [
			'paid_in_cash'        => true,
			'cash_accounts_id'    => $config['cash_accounts_id'],
			'cash_payment_date'   => $paid instanceof \WC_DateTime ? $paid->date( 'Y-m-d' ) : \gmdate( 'Y-m-d' ),
			'payment_description' => $this->payment_description( $order ),
		];
	}

	/**
	 * Record payment after invoice register when using Option B, or mark cash mode meta.
	 *
	 * @param WC_Order $order      Order.
	 * @param int      $invoice_id Sale invoice id.
	 * @param int      $clients_id Client id.
	 *
	 * @throws RuntimeException On API failure.
	 */
	public function record_after_invoice( WC_Order $order, int $invoice_id, int $clients_id ): void {

		$existing_mode = OrderMeta::get_string( $order, OrderMetaKeys::PAYMENT_MODE );

		if ( $existing_mode === SettingsRepository::PAYMENT_MODE_CASH
			|| OrderMeta::get_int( $order, OrderMetaKeys::TRANSACTION_ID ) > 0
		) {
			return;
		}

		$config = $this->settings->payment_config_for_method( $order->get_payment_method() );

		if ( $config['mode'] === SettingsRepository::PAYMENT_MODE_OFF || ! $order->is_paid() ) {
			$order->update_meta_data( OrderMetaKeys::PAYMENT_MODE, 'none' );
			$order->save();

			return;
		}

		if ( $config['mode'] === SettingsRepository::PAYMENT_MODE_CASH ) {
			// Cash fields were applied at create time when possible.
			$order->update_meta_data( OrderMetaKeys::PAYMENT_MODE, SettingsRepository::PAYMENT_MODE_CASH );
			$order->save();

			return;
		}

		if ( $config['mode'] === SettingsRepository::PAYMENT_MODE_TRANSACTION ) {
			$this->create_and_register_transaction( $order, $invoice_id, $clients_id, $config['accounts_dimensions_id'] );
		}
	}

	/**
	 * Provide arguments.
	 *
	 * @param WC_Order $order                   Order.
	 * @param int      $invoice_id              Sale invoice id.
	 * @param int      $clients_id              Client id.
	 * @param int      $accounts_dimensions_id  Dimension id.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function create_and_register_transaction(
		WC_Order $order,
		int $invoice_id,
		int $clients_id,
		int $accounts_dimensions_id
	): void {

		if ( $accounts_dimensions_id <= 0 ) {
			throw new RuntimeException(
				'Transaction payment mode requires accounts_dimensions_id in settings / gateway map.'
			);
		}

		$paid = $order->get_date_paid();
		$date = $paid instanceof \WC_DateTime ? $paid->date( 'Y-m-d' ) : \gmdate( 'Y-m-d' );

		// Prior partial refunds must not be re-received.
		$amount = \round( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 );

		if ( $amount <= 0.0 ) {
			$this->logger->info(
				'Skipping payment transaction: nothing outstanding after refunds.',
				[ 'order_id' => $order->get_id() ]
			);

			return;
		}

		$payload = [
			'accounts_dimensions_id' => $accounts_dimensions_id,
			'type'                   => self::TRANSACTION_TYPE_INCOMING,
			'amount'                 => $amount,
			'cl_currencies_id'       => $order->get_currency(),
			'date'                   => $date,
			'clients_id'             => $clients_id,
			'description'            => $this->payment_description( $order ),
			'ref_number'             => (string) $order->get_order_number(),
		];

		$client   = $this->client_factory->make();
		$response = $client->transactions()->create( $payload );

		if ( ! $response->successful() || $response->createdObjectId === null ) {
			throw new RuntimeException(
				\esc_html( 'Failed to create payment transaction: ' . \implode( '; ', $response->messages ) )
			);
		}

		$tx_id = $response->createdObjectId;

		$register = $client->transactions()->register(
			$tx_id,
			[
				[
					'related_table' => self::RELATED_TABLE_SALE_INVOICES,
					'related_id'    => $invoice_id,
					'amount'        => $amount,
				],
			]
		);

		if ( ! $register->successful() ) {
			throw new RuntimeException(
				\esc_html( 'Failed to register payment transaction #' . $tx_id . ': ' . \implode( '; ', $register->messages ) )
			);
		}

		OrderMeta::set( $order, OrderMetaKeys::TRANSACTION_ID, $tx_id );
		$order->update_meta_data( OrderMetaKeys::PAYMENT_MODE, SettingsRepository::PAYMENT_MODE_TRANSACTION );
		$order->save();

		$order->add_order_note(
			\sprintf(
				/* translators: %d: transaction id */
				__( 'e-Financials payment transaction registered (#%d).', 'e-financials' ),
				$tx_id
			)
		);

		$this->logger->info(
			'Recorded payment transaction.',
			[
				'order_id'       => $order->get_id(),
				'transaction_id' => $tx_id,
			]
		);
	}

	/**
	 * Build a payment description from the order.
	 *
	 * @param WC_Order $order Order.
	 */
	private function payment_description( WC_Order $order ): string {

		$txn_id = $order->get_transaction_id();
		$parts  = \array_filter(
			[
				$order->get_payment_method_title(),
				$txn_id !== '' ? 'txn ' . $txn_id : '',
				'WC order #' . $order->get_order_number(),
			],
			static fn ( string $part ): bool => $part !== ''
		);

			return \substr( \implode( ' / ', $parts ), 0, 150 );
	}
}
