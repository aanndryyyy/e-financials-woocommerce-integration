<?php
/**
 * VAT rate → sale article resolution.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Tests\Unit;

use Aanndryyyy\EFinancialsPlugin\Settings\SettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * e-Financials books VAT by sale article, so the mapping decides the bucket.
 */
final class SaleArticleMapTest extends TestCase {

	/**
	 * Seed the in-memory option store.
	 *
	 * @param array<string, mixed> $settings Integration settings.
	 */
	private function withSettings( array $settings ): SettingsRepository {

		$GLOBALS['ef_test_options'] = [ SettingsRepository::OPTION_KEY => $settings ];

		return new SettingsRepository();
	}

	/**
	 * Reset global state between tests.
	 */
	protected function tearDown(): void {

		unset( $GLOBALS['ef_test_options'] );

		parent::tearDown();
	}

	/**
	 * A mapped rate wins over the default article.
	 */
	public function test_mapped_rate_is_used(): void {

		$settings = $this->withSettings(
			[
				'cl_sale_articles_id'  => 1,
				'cl_sale_articles_map' => '{"9":5,"22":7}',
			]
		);

		$this->assertSame( 5, $settings->sale_article_for_rate( 9.0 ) );
		$this->assertSame( 7, $settings->sale_article_for_rate( 22.0 ) );
	}

	/**
	 * Unmapped rates fall back to the default article.
	 */
	public function test_unmapped_rate_falls_back_to_default(): void {

		$settings = $this->withSettings(
			[
				'cl_sale_articles_id'  => 1,
				'cl_sale_articles_map' => '{"9":5}',
			]
		);

		$this->assertSame( 1, $settings->sale_article_for_rate( 24.0 ) );
	}

	/**
	 * Decimal rates match their trimmed key form.
	 */
	public function test_decimal_rate_matches_map_key(): void {

		$settings = $this->withSettings(
			[
				'cl_sale_articles_id'  => 1,
				'cl_sale_articles_map' => '{"20.5":9}',
			]
		);

		$this->assertSame( 9, $settings->sale_article_for_rate( 20.5 ) );
	}

	/**
	 * Malformed JSON degrades to the default article rather than exploding.
	 */
	public function test_broken_json_is_ignored(): void {

		$settings = $this->withSettings(
			[
				'cl_sale_articles_id'  => 3,
				'cl_sale_articles_map' => 'not json',
			]
		);

		$this->assertSame( 3, $settings->sale_article_for_rate( 20.0 ) );
	}
}
