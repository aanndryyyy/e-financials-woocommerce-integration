<?php
/**
 * Cached read-side lookups against the e-Financials API.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Api;

use Aanndryyyy\EFinancialsPlugin\Support\Logger;

/**
 * The API exposes no server-side filters, so every "find by X" is a paginated
 * scan. Results are cached so the scans stay off the per-order hot path.
 */
class RemoteLookup {

	/**
	 * Cache lifetime for catalogue scans.
	 */
	private const TTL_SHORT = 300;

	/**
	 * Cache lifetime for configuration-ish data (series, sale articles).
	 */
	private const TTL_LONG = 3600;

	/**
	 * Hard cap on pages walked, so a huge tenant cannot stall a job.
	 */
	private const MAX_PAGES = 50;

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
	 * Find an existing product id by its catalogue code.
	 *
	 * @param string $code Product code.
	 */
	public function product_id_by_code( string $code ): ?int {

		$map = $this->product_code_map();

		return $map[ $code ] ?? null;
	}

	/**
	 * Find an existing client id by billing email.
	 *
	 * @param string $email Lowercased billing email.
	 */
	public function client_id_by_email( string $email ): ?int {

		if ( $email === '' ) {
			return null;
		}

		$map = $this->client_email_map();

		return $map[ $email ] ?? null;
	}

	/**
	 * VAT percentage configured on a sale article.
	 *
	 * @param int $sale_article_id Sale article id.
	 */
	public function sale_article_vat_rate( int $sale_article_id ): ?float {

		$rates = $this->sale_article_vat_rates();

		return $rates[ $sale_article_id ] ?? null;
	}

	/**
	 * Number prefix of an invoice series.
	 *
	 * @param int $series_id Invoice series id.
	 */
	public function series_number_prefix( int $series_id ): string {

		if ( $series_id <= 0 ) {
			return '';
		}

		$cached = \get_transient( $this->cache_key( 'series' ) );

		if ( \is_array( $cached ) ) {
			$prefix = $cached[ $series_id ] ?? '';

			return \is_string( $prefix ) ? $prefix : '';
		}

		$map = [];

		foreach ( $this->client_factory->make()->invoices()->all()->data as $series ) {
			if ( $series->id === null ) {
				continue;
			}

			$map[ $series->id ] = \trim( $series->numberPrefix );
		}

		\set_transient( $this->cache_key( 'series' ), $map, self::TTL_LONG );

		return $map[ $series_id ] ?? '';
	}

	/**
	 * Drop every cached lookup (called when settings change).
	 */
	public function flush(): void {

		foreach ( [ 'series', 'articles', 'products', 'clients' ] as $bucket ) {
			\delete_transient( $this->cache_key( $bucket ) );
		}
	}

	/**
	 * Map of product code → products_id.
	 *
	 * @return array<string, int>
	 */
	private function product_code_map(): array {

		$cached = \get_transient( $this->cache_key( 'products' ) );

		if ( \is_array( $cached ) ) {
			/**
			 * Cached code map.
			 *
			 * @var array<string, int> $cached
			 */
			return $cached;
		}

		$client = $this->client_factory->make();
		$map    = [];
		$page   = 1;

		do {
			$list = $client->products()->all( $page );

			foreach ( $list->items as $product ) {
				if ( $product->id === null || $product->code === '' ) {
					continue;
				}

				$map[ $product->code ] = $product->id;
			}

			++$page;
		} while ( $page <= $list->totalPages && $page <= self::MAX_PAGES );

		$this->warn_if_truncated( 'products', $list->totalPages );
		\set_transient( $this->cache_key( 'products' ), $map, self::TTL_SHORT );

		return $map;
	}

	/**
	 * Map of lowercased email → clients_id.
	 *
	 * @return array<string, int>
	 */
	private function client_email_map(): array {

		$cached = \get_transient( $this->cache_key( 'clients' ) );

		if ( \is_array( $cached ) ) {
			/**
			 * Cached email map.
			 *
			 * @var array<string, int> $cached
			 */
			return $cached;
		}

		$client = $this->client_factory->make();
		$map    = [];
		$page   = 1;

		do {
			$list = $client->clients()->all( $page );

			foreach ( $list->items as $item ) {
				if ( $item->id === null || $item->email === null || $item->email === '' ) {
					continue;
				}

				$email = \strtolower( \trim( $item->email ) );

				// First match wins, mirroring "oldest client for this email".
				if ( ! isset( $map[ $email ] ) ) {
					$map[ $email ] = $item->id;
				}
			}

			++$page;
		} while ( $page <= $list->totalPages && $page <= self::MAX_PAGES );

		$this->warn_if_truncated( 'clients', $list->totalPages );
		\set_transient( $this->cache_key( 'clients' ), $map, self::TTL_SHORT );

		return $map;
	}

	/**
	 * Map of sale article id → VAT percentage.
	 *
	 * @return array<int, float>
	 */
	private function sale_article_vat_rates(): array {

		$cached = \get_transient( $this->cache_key( 'articles' ) );

		if ( \is_array( $cached ) ) {
			/**
			 * Cached VAT rates.
			 *
			 * @var array<int, float> $cached
			 */
			return $cached;
		}

		$map = [];

		foreach ( $this->client_factory->make()->salesArticles()->all()->data as $article ) {
			if ( $article->id === null || $article->vatRate === null ) {
				continue;
			}

			$map[ $article->id ] = \round( $article->vatRate, 2 );
		}

		\set_transient( $this->cache_key( 'articles' ), $map, self::TTL_LONG );

		return $map;
	}

	/**
	 * Log when a scan hit the page cap.
	 *
	 * @param string $bucket      Lookup name.
	 * @param int    $total_pages Total pages reported by the API.
	 */
	private function warn_if_truncated( string $bucket, int $total_pages ): void {

		if ( $total_pages <= self::MAX_PAGES ) {
			return;
		}

		$this->logger->warning(
			'e-Financials lookup truncated at the page cap; matches beyond it are invisible.',
			[
				'lookup'      => $bucket,
				'total_pages' => $total_pages,
				'max_pages'   => self::MAX_PAGES,
			]
		);
	}

	/**
	 * Environment-scoped transient key.
	 *
	 * @param string $bucket Lookup name.
	 */
	private function cache_key( string $bucket ): string {

		return 'ef_lookup_' . $bucket;
	}
}
