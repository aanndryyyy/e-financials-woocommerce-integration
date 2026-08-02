<?php
/**
 * Ensures e-Financials products exist for invoice line items.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Sync;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Meta\ProductMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Settings\SettingsRepository;
use Aanndryyyy\EFinancialsPlugin\Support\Logger;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product;

/**
 * Resolves products_id values required by sale invoice rows.
 */
class ProductEnsureService {

	private const CODE_SHIPPING = 'WC-SHIP';

	private const CODE_FEE = 'WC-FEE';

	private const CODE_GENERIC = 'WC-LINE';

	/**
	 * Provide arguments.
	 *
	 * @param ClientFactory      $client_factory API client factory.
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
	 * Ensure a catalog product is linked in e-Financials.
	 *
	 * @param WC_Product $product WooCommerce product.
	 *
	 * @throws RuntimeException On API failure.
	 */
	public function ensure_product( WC_Product $product ): int {

		$existing_raw = $product->get_meta( ProductMetaKeys::PRODUCTS_ID, true );
		$existing     = \is_numeric( $existing_raw ) ? (int) $existing_raw : 0;

		if ( $existing > 0 ) {
			return $existing;
		}

		$name = $product->get_name();
		$sku  = $product->get_sku();
		$code = $this->normalize_code( $sku !== '' ? $sku : 'WC-P' . $product->get_id() );

		$params = [
			'description'    => \wp_strip_all_tags( $product->get_short_description() !== '' ? $product->get_short_description() : $product->get_description() ),
			'sales_price'    => (float) $product->get_regular_price(),
			'price_currency' => \get_woocommerce_currency(),
		];

		$sale_article = $this->settings->sale_article_id();

		if ( $sale_article > 0 ) {
			$params['cl_sale_articles_id'] = $sale_article;
		}

		$client   = $this->client_factory->make();
		$response = $client->products()->create( $name, $code, $params );

		if ( ! $response->successful() || $response->createdObjectId === null ) {
			// Retry with a unique code if code collision.
			$code     = $this->normalize_code( 'WC-P' . $product->get_id() . '-' . \wp_generate_password( 4, false ) );
			$response = $client->products()->create( $name, $code, $params );
		}

		if ( ! $response->successful() || $response->createdObjectId === null ) {
			throw new RuntimeException(
				'Failed to create e-Financials product: ' . \implode( '; ', $response->messages )
			);
		}

		$products_id = (int) $response->createdObjectId;
		$product->update_meta_data( ProductMetaKeys::PRODUCTS_ID, (string) $products_id );
		$product->save();

		$this->logger->info(
			'Linked e-Financials product.',
			[
				'product_id'  => $product->get_id(),
				'products_id' => $products_id,
			]
		);

		return $products_id;
	}

	/**
	 * Build invoice item rows for an order, ensuring products exist.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws RuntimeException On API failure.
	 */
	public function build_invoice_items( WC_Order $order ): array {

		$items = [];

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$qty = (float) $item->get_quantity();

			if ( $qty <= 0 ) {
				continue;
			}

			$product     = $item->get_product();
			$products_id = $product instanceof WC_Product
				? $this->ensure_product( $product )
				: $this->ensure_generic( self::CODE_GENERIC, 'WooCommerce line item' );

			$net = (float) $item->get_total();
			$tax = (float) $item->get_total_tax();

			$items[] = [
				'products_id'     => $products_id,
				'amount'          => $qty,
				'custom_title'    => $item->get_name(),
				'unit_net_price'  => \round( $net / $qty, 4 ),
				'total_net_price' => \round( $net, 4 ),
				'vat_rate'        => $this->guess_vat_rate( $net, $tax ),
				'vat_amount'      => \round( $tax, 4 ),
			];
		}

		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Shipping ) {
				continue;
			}

			$net = (float) $item->get_total();
			$tax = (float) $item->get_total_tax();

			if ( $net === 0.0 && $tax === 0.0 ) {
				continue;
			}

			$items[] = [
				'products_id'     => $this->ensure_generic( self::CODE_SHIPPING, 'WooCommerce shipping' ),
				'amount'          => 1,
				'custom_title'    => $item->get_name() !== '' ? $item->get_name() : 'Shipping',
				'unit_net_price'  => \round( $net, 4 ),
				'total_net_price' => \round( $net, 4 ),
				'vat_rate'        => $this->guess_vat_rate( $net, $tax ),
				'vat_amount'      => \round( $tax, 4 ),
			];
		}

		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Fee ) {
				continue;
			}

			$net = (float) $item->get_total();
			$tax = (float) $item->get_total_tax();

			$items[] = [
				'products_id'     => $this->ensure_generic( self::CODE_FEE, 'WooCommerce fee' ),
				'amount'          => 1,
				'custom_title'    => $item->get_name() !== '' ? $item->get_name() : 'Fee',
				'unit_net_price'  => \round( $net, 4 ),
				'total_net_price' => \round( $net, 4 ),
				'vat_rate'        => $this->guess_vat_rate( $net, $tax ),
				'vat_amount'      => \round( $tax, 4 ),
			];
		}

		if ( $items === [] ) {
			throw new RuntimeException( 'Order has no invoiceable line items.' );
		}

		$sale_article = $this->settings->sale_article_id();

		if ( $sale_article > 0 ) {
			foreach ( $items as &$row ) {
				$row['cl_sale_articles_id'] = $sale_article;
			}
			unset( $row );
		}

		return $items;
	}

	/**
	 * Ensure a shared generic product (shipping/fee/unlinked) exists.
	 *
	 * @param string $code Product code (max 20).
	 * @param string $name Product name.
	 *
	 * @throws RuntimeException On API failure.
	 */
	private function ensure_generic( string $code, string $name ): int {

		$option_key = 'ef_generic_products_id_' . \sanitize_key( $code );
		$cached_raw = \get_option( $option_key, 0 );
		$cached     = \is_numeric( $cached_raw ) ? (int) $cached_raw : 0;

		if ( $cached > 0 ) {
			return $cached;
		}

		$client       = $this->client_factory->make();
		$params       = [
			'price_currency' => \get_woocommerce_currency(),
		];
		$sale_article = $this->settings->sale_article_id();

		if ( $sale_article > 0 ) {
			$params['cl_sale_articles_id'] = $sale_article;
		}

		$response = $client->products()->create( $name, $this->normalize_code( $code ), $params );

		if ( ! $response->successful() || $response->createdObjectId === null ) {
			// Likely already exists — store a synthetic lookup via create with unique suffix is avoided;
			// rethrow so the job retries after admin fixes catalogue conflicts.
			throw new RuntimeException(
				'Failed to create generic e-Financials product ' . $code . ': ' . \implode( '; ', $response->messages )
			);
		}

		$products_id = (int) $response->createdObjectId;
		\update_option( $option_key, $products_id, false );

		return $products_id;
	}

	/**
	 * Normalize a product code to API limits.
	 *
	 * @param string $code Raw code.
	 */
	private function normalize_code( string $code ): string {

		$code = \preg_replace( '/[^A-Za-z0-9\-_]/', '', $code ) ?? 'WC-ITEM';
		$code = \substr( $code, 0, 20 );

		return $code !== '' ? $code : 'WC-ITEM';
	}

	/**
	 * Guess VAT percent from net and tax amounts.
	 *
	 * @param float $net Net amount.
	 * @param float $tax Tax amount.
	 */
	private function guess_vat_rate( float $net, float $tax ): float {

		if ( $net <= 0.0 || $tax <= 0.0 ) {
			return 0.0;
		}

		$rate = \round( ( $tax / $net ) * 100, 2 );

		foreach ( [ 0.0, 5.0, 9.0, 20.0, 22.0, 24.0 ] as $known ) {
			if ( \abs( $rate - $known ) < 0.6 ) {
				return $known;
			}
		}

		return $rate;
	}
}
