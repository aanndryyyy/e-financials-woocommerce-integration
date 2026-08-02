<?php
/**
 * Reads WooCommerce integration settings for e-Financials.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Settings;

use Aanndryyyy\EFinancialsPlugin\Integration\EFinancialsIntegration;

/**
 * Typed access to integration options.
 */
class SettingsRepository {

	public const OPTION_KEY = 'woocommerce_efinancials_integration_settings';

	public const PAYMENT_MODE_CASH = 'cash';

	public const PAYMENT_MODE_TRANSACTION = 'transaction';

	public const PAYMENT_MODE_OFF = 'off';

	/**
	 * Get value.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {

		$settings = \get_option( self::OPTION_KEY, [] );

		if ( ! \is_array( $settings ) ) {
			return [];
		}

		/**
		 * Typed settings bag.
		 *
		 * @var array<string, mixed> $typed
		 */
		$typed = [];

		foreach ( $settings as $key => $value ) {
			if ( \is_string( $key ) ) {
				$typed[ $key ] = $value;
			}
		}

		return $typed;
	}

	/**
	 * Provide arguments.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Default value.
	 */
	public function get( string $key, mixed $fallback = '' ): mixed {

		$settings = $this->all();

		return $settings[ $key ] ?? $fallback;
	}

	/**
	 * Read a string setting.
	 *
	 * @param string $key      Setting key.
	 * @param string $fallback Fallback.
	 */
	public function get_string( string $key, string $fallback = '' ): string {

		$value = $this->get( $key, $fallback );

		return \is_string( $value ) ? $value : $fallback;
	}

	/**
	 * Read an integer setting.
	 *
	 * @param string $key      Setting key.
	 * @param int    $fallback Fallback.
	 */
	public function get_int( string $key, int $fallback = 0 ): int {

		$value = $this->get( $key, $fallback );

		return \is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * API key id.
	 */
	public function api_key_id(): string {

		return $this->get_string( EFinancialsIntegration::SETTING_KEY_API_KEY_ID );
	}

	/**
	 * API key public value.
	 */
	public function api_key_public(): string {

		return $this->get_string( EFinancialsIntegration::SETTING_KEY_API_KEY_PUBLIC );
	}

	/**
	 * API key password.
	 */
	public function api_key_password(): string {

		return $this->get_string( EFinancialsIntegration::SETTING_KEY_API_KEY_PASSWORD );
	}

	/**
	 * Whether live environment is selected.
	 */
	public function is_live(): bool {

		return $this->get( EFinancialsIntegration::SETTING_KEY_API_ENVIRONMENT, EFinancialsIntegration::SETTING_KEY_API_ENVIRONMENT_OPTION_TEST )
			=== EFinancialsIntegration::SETTING_KEY_API_ENVIRONMENT_OPTION_LIVE;
	}

	/**
	 * Whether credentials are configured.
	 */
	public function has_credentials(): bool {

		return $this->api_key_id() !== ''
			&& $this->api_key_public() !== ''
			&& $this->api_key_password() !== '';
	}

	/**
	 * Selected invoice series id.
	 */
	public function invoice_series_id(): int {

		return $this->get_int( EFinancialsIntegration::SETTING_KEY_INVOICE_SERIES_ID );
	}

	/**
	 * Selected template id.
	 */
	public function template_id(): int {

		return $this->get_int( EFinancialsIntegration::SETTING_KEY_TEMPLATE_ID );
	}

	/**
	 * Default sale article id.
	 */
	public function sale_article_id(): int {

		return $this->get_int( EFinancialsIntegration::SETTING_KEY_SALE_ARTICLE_ID );
	}

	/**
	 * Invoice payment term days.
	 */
	public function term_days(): int {

		$days = $this->get_int( EFinancialsIntegration::SETTING_KEY_TERM_DAYS, 14 );

		return $days > 0 ? $days : 14;
	}

	/**
	 * Whether to use WC order number as invoice suffix.
	 */
	public function use_wc_order_number(): bool {

		return $this->get( EFinancialsIntegration::SETTING_KEY_USE_WC_ORDER_NUMBER, 'yes' ) === 'yes';
	}

	/**
	 * Default payment recording mode.
	 */
	public function default_payment_mode(): string {

		$mode = $this->get_string(
			EFinancialsIntegration::SETTING_KEY_DEFAULT_PAYMENT_MODE,
			self::PAYMENT_MODE_CASH
		);

		return $this->normalize_payment_mode( $mode );
	}

	/**
	 * Default cash account id.
	 */
	public function default_cash_accounts_id(): int {

		return $this->get_int( EFinancialsIntegration::SETTING_KEY_DEFAULT_CASH_ACCOUNTS_ID );
	}

	/**
	 * Default accounts dimension id.
	 */
	public function default_accounts_dimensions_id(): int {

		return $this->get_int( EFinancialsIntegration::SETTING_KEY_DEFAULT_ACCOUNTS_DIMENSIONS_ID );
	}

	/**
	 * Whether to auto-deliver invoices.
	 */
	public function auto_deliver(): bool {

		return $this->get( EFinancialsIntegration::SETTING_KEY_AUTO_DELIVER, 'no' ) === 'yes';
	}

	/**
	 * Whether to auto-send e-invoice XML.
	 */
	public function auto_deliver_einvoice(): bool {

		return $this->get( EFinancialsIntegration::SETTING_KEY_AUTO_DELIVER_EINVOICE, 'no' ) === 'yes';
	}

	/**
	 * Whether products auto-sync on save.
	 */
	public function product_auto_sync(): bool {

		return $this->get( EFinancialsIntegration::SETTING_KEY_PRODUCT_AUTO_SYNC, 'no' ) === 'yes';
	}

	/**
	 * Resolve payment mode + account ids for a WC payment method id.
	 *
	 * @param string $payment_method Order payment method id (bacs, cod, stripe, …).
	 *
	 * @return array{mode: string, cash_accounts_id: int, accounts_dimensions_id: int}
	 */
	public function payment_config_for_method( string $payment_method ): array {

		$map    = $this->gateway_map();
		$method = \strtolower( \trim( $payment_method ) );
		$row    = $map[ $method ] ?? [];

		$mode = isset( $row['mode'] )
			? $this->normalize_payment_mode( (string) $row['mode'] )
			: $this->default_payment_mode();

		$cash_accounts_id = isset( $row['cash_accounts_id'] )
			? (int) $row['cash_accounts_id']
			: $this->default_cash_accounts_id();

		$accounts_dimensions_id = isset( $row['accounts_dimensions_id'] )
			? (int) $row['accounts_dimensions_id']
			: $this->default_accounts_dimensions_id();

		return [
			'mode'                   => $mode,
			'cash_accounts_id'       => $cash_accounts_id,
			'accounts_dimensions_id' => $accounts_dimensions_id,
		];
	}

	/**
	 * Get value.
	 *
	 * @return array<string, array{mode?: string, cash_accounts_id?: int, accounts_dimensions_id?: int}>
	 */
	public function gateway_map(): array {

		$raw = $this->get_string( EFinancialsIntegration::SETTING_KEY_GATEWAY_MAP );

		if ( $raw === '' ) {
			return $this->default_gateway_map();
		}

		$decoded = \json_decode( $raw, true );

		if ( ! \is_array( $decoded ) ) {
			return $this->default_gateway_map();
		}

		/**
		 * Decoded gateway map.
		 *
		 * @var array<string, array{mode?: string, cash_accounts_id?: int, accounts_dimensions_id?: int}> $map
		 */
		$map = $decoded;

		return $map;
	}

	/**
	 * Get value.
	 *
	 * @return array<string, array{mode: string}>
	 */
	private function default_gateway_map(): array {

		return [
			'bacs'   => [ 'mode' => self::PAYMENT_MODE_TRANSACTION ],
			'cheque' => [ 'mode' => self::PAYMENT_MODE_TRANSACTION ],
			'cod'    => [ 'mode' => self::PAYMENT_MODE_CASH ],
		];
	}

	/**
	 * Normalize a payment mode string.
	 *
	 * @param string $mode Raw mode.
	 */
	private function normalize_payment_mode( string $mode ): string {

		$mode = \strtolower( \trim( $mode ) );

		return match ( $mode ) {
			self::PAYMENT_MODE_CASH, self::PAYMENT_MODE_TRANSACTION, self::PAYMENT_MODE_OFF => $mode,
			'none' => self::PAYMENT_MODE_OFF,
			default => self::PAYMENT_MODE_CASH,
		};
	}
}
