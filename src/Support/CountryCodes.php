<?php
/**
 * ISO country code helpers for e-Financials (alpha-3).
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Support;

/**
 * Maps WooCommerce alpha-2 countries to e-Financials alpha-3 codes.
 */
final class CountryCodes {

	/**
	 * Common EU + nearby alpha-2 → alpha-3 map.
	 *
	 * @var array<string, string>
	 */
	private const MAP = [
		'AT' => 'AUT',
		'BE' => 'BEL',
		'BG' => 'BGR',
		'CY' => 'CYP',
		'CZ' => 'CZE',
		'DE' => 'DEU',
		'DK' => 'DNK',
		'EE' => 'EST',
		'ES' => 'ESP',
		'FI' => 'FIN',
		'FR' => 'FRA',
		'GB' => 'GBR',
		'GR' => 'GRC',
		'HR' => 'HRV',
		'HU' => 'HUN',
		'IE' => 'IRL',
		'IT' => 'ITA',
		'LT' => 'LTU',
		'LU' => 'LUX',
		'LV' => 'LVA',
		'MT' => 'MLT',
		'NL' => 'NLD',
		'PL' => 'POL',
		'PT' => 'PRT',
		'RO' => 'ROU',
		'SE' => 'SWE',
		'SI' => 'SVN',
		'SK' => 'SVK',
		'US' => 'USA',
		'NO' => 'NOR',
		'CH' => 'CHE',
		'UA' => 'UKR',
		'RU' => 'RUS',
	];

	/**
	 * Convert a WooCommerce billing country (alpha-2) to alpha-3.
	 *
	 * @param string $alpha2 ISO 3166-1 alpha-2 code.
	 */
	public static function to_alpha3( string $alpha2 ): string {

		$code = \strtoupper( \trim( $alpha2 ) );

		if ( $code === '' ) {
			return 'EST';
		}

		if ( \strlen( $code ) === 3 ) {
			return $code;
		}

		return self::MAP[ $code ] ?? 'EST';
	}
}
