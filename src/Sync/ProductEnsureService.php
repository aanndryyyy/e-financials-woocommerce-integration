<?php
/**
 * Ensures e-Financials products exist for invoice line items.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Sync;

use Aanndryyyy\EFinancialsPlugin\Api\ClientFactory;
use Aanndryyyy\EFinancialsPlugin\Api\RemoteLookup;
use Aanndryyyy\EFinancialsPlugin\Meta\ProductMetaKeys;
use Aanndryyyy\EFinancialsPlugin\Settings\SettingsRepository;
use Aanndryyyy\EFinancialsPlugin\Support\Logger;
use Aanndryyyy\EFinancialsPlugin\Support\TaxRates;
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
	 * @param RemoteLookup       $lookup         Cached API lookups.
	 * @param SettingsRepository $settings       Settings.
	 * @param Logger             $logger         Logger.
	 */
	public function __construct(
		private readonly ClientFactory $client_factory,
		private readonly RemoteLookup $lookup,
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

		$sku  = $product->get_sku();
		$code = $this->normalize_code( $sku !== '' ? $sku : 'WC-P' . $product->get_id() );

		$products_id = $this->find_or_create(
			$code,
			$product->get_name(),
			[
				'description'    => \wp_strip_all_tags( $product->get_short_description() !== '' ? $product->get_short_description() : $product->get_description() ),
				'sales_price'    => (float) $product->get_regular_price(),
				'price_currency' => \get_woocommerce_currency(),
			]
		);

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

			$items[] = $this->build_row(
				$products_id,
				$item->get_name(),
				$qty,
				(float) $item->get_total(),
				(float) $item->get_total_tax(),
				TaxRates::for_item( $item )
			);
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

			$items[] = $this->build_row(
				$this->ensure_generic( self::CODE_SHIPPING, 'WooCommerce shipping' ),
				$item->get_name() !== '' ? $item->get_name() : 'Shipping',
				1.0,
				$net,
				$tax,
				TaxRates::for_item( $item )
			);
		}

		foreach ( $order->get_items( 'fee' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Fee ) {
				continue;
			}

			$items[] = $this->build_row(
				$this->ensure_generic( self::CODE_FEE, 'WooCommerce fee' ),
				$item->get_name() !== '' ? $item->get_name() : 'Fee',
				1.0,
				(float) $item->get_total(),
				(float) $item->get_total_tax(),
				TaxRates::for_item( $item )
			);
		}

		if ( $items === [] ) {
			throw new RuntimeException( 'Order has no invoiceable line items.' );
		}

		return $items;
	}

	/**
	 * Build one invoice row with a verified VAT rate and sale article.
	 *
	 * @param int    $products_id e-Financials product id.
	 * @param string $title       Row title.
	 * @param float  $qty         Quantity.
	 * @param float  $net         Net amount for the whole row.
	 * @param float  $tax         Tax amount for the whole row.
	 * @param float  $vat_rate    VAT percentage read from WooCommerce.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When no matching sale article is configured.
	 */
	public function build_row( int $products_id, string $title, float $qty, float $net, float $tax, float $vat_rate ): array {

		$qty = $qty > 0 ? $qty : 1.0;

		return [
			'products_id'         => $products_id,
			'amount'              => $qty,
			'custom_title'        => $title,
			'unit_net_price'      => \round( $net / $qty, 4 ),
			'total_net_price'     => \round( $net, 4 ),
			'vat_rate'            => $vat_rate,
			'vat_amount'          => \round( $tax, 4 ),
			'cl_sale_articles_id' => $this->sale_article_for_rate( $vat_rate ),
		];
	}

	/**
	 * Resolve the sale article whose VAT rate matches the line.
	 *
	 * The API books VAT by the sale article, not by the row's vat_rate, so a
	 * mismatch would silently land tax in the wrong VAT-return bucket.
	 *
	 * @param float $vat_rate VAT percentage.
	 *
	 * @throws RuntimeException When nothing is configured or the rates disagree.
	 */
	public function sale_article_for_rate( float $vat_rate ): int {

		$sale_article = $this->settings->sale_article_for_rate( $vat_rate );

		if ( $sale_article <= 0 ) {
			throw new RuntimeException(
				\esc_html(
					\sprintf(
						/* translators: %s: VAT percentage */
						__( 'No e-Financials sale article is configured for VAT %s%%. Set a default sale article or add the rate to the VAT rate map.', 'e-financials' ),
						(string) $vat_rate
					)
				)
			);
		}

		$article_rate = $this->lookup->sale_article_vat_rate( $sale_article );

		if ( $article_rate !== null && \abs( $article_rate - $vat_rate ) > 0.001 ) {
			throw new RuntimeException(
				\esc_html(
					\sprintf(
						/* translators: 1: sale article id, 2: article VAT percentage, 3: order line VAT percentage */
						__( 'Sale article #%1$d is VAT %2$s%% but the order line is VAT %3$s%%. Add "%3$s" to the VAT rate → sale article map; e-Financials books VAT by article, so the rates must match. Shops with WooCommerce taxes disabled need a "0" entry.', 'e-financials' ),
						$sale_article,
						(string) $article_rate,
						(string) $vat_rate
					)
				)
			);
		}

		return $sale_article;
	}

	/**
	 * Shared catalogue entry used for shipping rows.
	 *
	 * @throws RuntimeException On API failure.
	 */
	public function ensure_shipping_id(): int {

		return $this->ensure_generic( self::CODE_SHIPPING, 'WooCommerce shipping' );
	}

	/**
	 * Shared catalogue entry used for fee rows.
	 *
	 * @throws RuntimeException On API failure.
	 */
	public function ensure_fee_id(): int {

		return $this->ensure_generic( self::CODE_FEE, 'WooCommerce fee' );
	}

	/**
	 * Shared catalogue entry used for unlinked / synthetic rows.
	 *
	 * @throws RuntimeException On API failure.
	 */
	public function ensure_generic_line_id(): int {

		return $this->ensure_generic( self::CODE_GENERIC, 'WooCommerce line item' );
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

		$code       = $this->normalize_code( $code );
		$option_key = 'ef_generic_products_id_' . \sanitize_key( $code );
		$cached_raw = \get_option( $option_key, 0 );
		$cached     = \is_numeric( $cached_raw ) ? (int) $cached_raw : 0;

		if ( $cached > 0 ) {
			return $cached;
		}

		$products_id = $this->find_or_create(
			$code,
			$name,
			[ 'price_currency' => \get_woocommerce_currency() ]
		);

		\update_option( $option_key, $products_id, false );

		return $products_id;
	}

	/**
	 * Link an existing catalogue entry by code, or create it.
	 *
	 * Product codes are unique tenant-wide, so a failed create is resolved by
	 * looking the code up — never by inventing a suffixed duplicate.
	 *
	 * @param string               $code   Catalogue code.
	 * @param string               $name   Product name.
	 * @param array<string, mixed> $params Additional create parameters.
	 *
	 * @throws RuntimeException On API failure or missing configuration.
	 */
	private function find_or_create( string $code, string $name, array $params ): int {

		$found = $this->lookup->product_id_by_code( $code );

		if ( $found !== null && $found > 0 ) {
			return $found;
		}

		$sale_article = $this->settings->sale_article_id();

		if ( $sale_article <= 0 ) {
			throw new RuntimeException(
				\esc_html__( 'e-Financials requires a default sale article before products can be created. Set it in the integration settings.', 'e-financials' )
			);
		}

		$params['cl_sale_articles_id'] = $sale_article;

		$response = $this->client_factory->make()->products()->create( $name, $code, $params );

		if ( $response->successful() && $response->createdObjectId !== null ) {
			$this->lookup->flush();

			return $response->createdObjectId;
		}

		// A create can lose a race, or the code can pre-date this site: re-scan before failing.
		$this->lookup->flush();
		$found = $this->lookup->product_id_by_code( $code );

		if ( $found !== null && $found > 0 ) {
			return $found;
		}

		throw new RuntimeException(
			\esc_html( 'Failed to create e-Financials product ' . $code . ': ' . \implode( '; ', $response->messages ) )
		);
	}

	/**
	 * Normalize a product code to API limits.
	 *
	 * @param string $code Raw code.
	 */
	public function normalize_code( string $code ): string {

		$code = \preg_replace( '/[^A-Za-z0-9\-_]/', '', $code ) ?? 'WC-ITEM';
		$code = \substr( $code, 0, 20 );

		return $code !== '' ? $code : 'WC-ITEM';
	}
}
