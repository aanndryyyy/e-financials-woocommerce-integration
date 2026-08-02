<?php
/**
 * Upserts WooCommerce buyers into e-Financials clients.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Sync;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Meta\OrderMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Support\CountryCodes;
use Aanndryyyy\EFinancialsPlugin\Support\Logger;
use Aanndryyyy\EFinancialsPlugin\Support\OrderMeta;
use EFinancialsClient\Responses\Clients\ClientResponse;
use RuntimeException;
use WC_Order;

/**
 * Creates or reuses an e-Financials client for an order.
 */
class ClientUpsertService {

	/**
	 * Provide arguments.
	 *
	 * @param ClientFactory $client_factory API client factory.
	 * @param Logger        $logger         Logger.
	 */
	public function __construct(
		private readonly ClientFactory $client_factory,
		private readonly Logger $logger
	) {
	}

	/**
	 * Ensure a clients_id exists for the order buyer and store it on the order.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @throws RuntimeException On API failure.
	 */
	public function upsert_for_order( WC_Order $order ): int {

		$existing = OrderMeta::get_int( $order, OrderMetaKeys::CLIENTS_ID );

		if ( $existing > 0 ) {
			return $existing;
		}

		$customer_id = $order->get_customer_id();

		if ( $customer_id > 0 ) {
			$from_user_raw = \get_user_meta( $customer_id, OrderMetaKeys::CLIENTS_ID, true );
			$from_user     = \is_numeric( $from_user_raw ) ? (int) $from_user_raw : 0;

			if ( $from_user > 0 ) {
				OrderMeta::set( $order, OrderMetaKeys::CLIENTS_ID, $from_user );
				$order->save();

				return $from_user;
			}
		}

		$client     = $this->client_factory->make();
		$payload    = $this->build_payload( $order );
		$email      = \strtolower( \trim( $order->get_billing_email() ) );
		$matched    = $email !== '' ? $this->find_by_email( $client->clients()->all( 1 ), $email ) : null;
		$clients_id = 0;

		if ( $matched instanceof ClientResponse && $matched->id !== null ) {
			$clients_id = (int) $matched->id;
			$response   = $client->clients()->update( $clients_id, $payload );

			if ( ! $response->successful() ) {
				throw new RuntimeException(
					'Failed to update e-Financials client: ' . \implode( '; ', $response->messages )
				);
			}
		} else {
			$response = $client->clients()->create( $payload );

			if ( ! $response->successful() || $response->createdObjectId === null ) {
				throw new RuntimeException(
					'Failed to create e-Financials client: ' . \implode( '; ', $response->messages )
				);
			}

			$clients_id = (int) $response->createdObjectId;
		}

		OrderMeta::set( $order, OrderMetaKeys::CLIENTS_ID, $clients_id );
		$order->save();

		if ( $customer_id > 0 ) {
			\update_user_meta( $customer_id, OrderMetaKeys::CLIENTS_ID, $clients_id );
		}

		$this->logger->info(
			'Linked e-Financials client.',
			[
				'order_id'   => $order->get_id(),
				'clients_id' => $clients_id,
			]
		);

		return $clients_id;
	}

	/**
	 * Provide arguments.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return array<string, mixed>
	 */
	private function build_payload( WC_Order $order ): array {

		$company = \trim( $order->get_billing_company() );
		$name    = $company !== ''
			? $company
			: \trim( $order->get_formatted_billing_full_name() );

		if ( $name === '' ) {
			$name = 'WooCommerce customer #' . $order->get_id();
		}

		$country = CountryCodes::to_alpha3( $order->get_billing_country() );
		$address = \trim(
			\implode(
				', ',
				\array_filter(
					[
						$order->get_billing_address_1(),
						$order->get_billing_address_2(),
						$order->get_billing_postcode(),
						$order->get_billing_city(),
					],
					static fn ( string $part ): bool => $part !== ''
				)
			)
		);

		$vat = OrderMeta::get_string( $order, '_billing_vat_number' );

		if ( $vat === '' ) {
			$vat = OrderMeta::get_string( $order, '_billing_eu_vat_number' );
		}

		return [
			'is_client'                        => true,
			'is_supplier'                      => false,
			'name'                             => $name,
			'cl_code_country'                  => $country,
			'cl_invoice_country'               => $country,
			'is_member'                        => false,
			'send_invoice_to_email'            => true,
			'send_invoice_to_accounting_email' => false,
			'is_juridical_entity'              => $company !== '',
			'is_physical_entity'               => $company === '',
			'email'                            => $order->get_billing_email(),
			'telephone'                        => $order->get_billing_phone(),
			'address_text'                     => $address,
			'postal_address_text'              => $address,
			'invoice_vat_no'                   => $vat !== '' ? $vat : null,
			'code'                             => ( OrderMeta::get_string( $order, '_billing_registry_code' ) !== '' ) ? OrderMeta::get_string( $order, '_billing_registry_code' ) : null,
		];
	}

	/**
	 * Provide arguments.
	 *
	 * @param \EFinancialsClient\Responses\Clients\ListResponse $clients Client list page.
	 * @param string                                            $email   Billing email.
	 */
	private function find_by_email( \EFinancialsClient\Responses\Clients\ListResponse $clients, string $email ): ?ClientResponse {

		foreach ( $clients->items as $item ) {
			if ( $item->email !== null && \strtolower( $item->email ) === $email ) {
				return $item;
			}
		}

		return null;
	}
}
