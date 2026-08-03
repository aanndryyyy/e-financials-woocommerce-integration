<?php
/**
 * WooCommerce integration settings for e-Financials.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Integration;

use Aanndryyyy\EFinancialsPlugin\Api\RemoteLookup;
use Aanndryyyy\EFinancialsPlugin\Settings\SettingsRepository;
use Aanndryyyy\EFinancialsPlugin\Support\ErrorMessage;
use EFinancials;
use Throwable;

/**
 * WC_Integration settings screen.
 */
class EFinancialsIntegration extends \WC_Integration {

	public const SETTING_KEY_API_KEY_ID = 'api_key_id';

	public const SETTING_KEY_API_KEY_PUBLIC = 'api_key_public';

	public const SETTING_KEY_API_KEY_PASSWORD = 'api_key_password';

	public const SETTING_KEY_API_ENVIRONMENT = 'api_key_environment';

	public const SETTING_KEY_API_ENVIRONMENT_OPTION_TEST = 'api_environment_test';

	public const SETTING_KEY_API_ENVIRONMENT_OPTION_LIVE = 'api_environment_live';

	public const SETTING_KEY_INVOICE_SERIES_ID = 'invoice_series_id';

	public const SETTING_KEY_TEMPLATE_ID = 'cl_templates_id';

	public const SETTING_KEY_SALE_ARTICLE_ID = 'cl_sale_articles_id';

	public const SETTING_KEY_SALE_ARTICLE_MAP = 'cl_sale_articles_map';

	public const SETTING_KEY_TERM_DAYS = 'term_days';

	public const SETTING_KEY_USE_WC_ORDER_NUMBER = 'use_wc_order_number';

	public const SETTING_KEY_DEFAULT_PAYMENT_MODE = 'default_payment_mode';

	public const SETTING_KEY_DEFAULT_CASH_ACCOUNTS_ID = 'default_cash_accounts_id';

	public const SETTING_KEY_DEFAULT_ACCOUNTS_DIMENSIONS_ID = 'default_accounts_dimensions_id';

	public const SETTING_KEY_GATEWAY_MAP = 'gateway_payment_map';

	public const SETTING_KEY_AUTO_DELIVER = 'auto_deliver';

	public const SETTING_KEY_AUTO_DELIVER_EINVOICE = 'auto_deliver_einvoice';

	public const SETTING_KEY_PRODUCT_AUTO_SYNC = 'product_auto_sync';

	private const OPTIONS_TRANSIENT_PREFIX = 'ef_settings_options_';

	private const OPTIONS_TRANSIENT_TTL = 3600;

	/**
	 * Init and hook in the integration.
	 */
	public function __construct() {

		$this->id                 = 'efinancials_integration';
		$this->method_title       = __( 'e-Financials', 'e-financials' );
		$this->method_description = __( 'Sync WooCommerce orders to e-Arveldaja / e-Financials in the background.', 'e-financials' );

		$this->init_form_fields();
		$this->init_settings();

		// @phpstan-ignore-next-line
		\add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function init_form_fields(): void {

		$series_options   = $this->safe_id_options( 'series', [ $this, 'fetch_invoice_series_options' ] );
		$template_options = $this->safe_id_options( 'templates', [ $this, 'fetch_template_options' ] );
		$article_options  = $this->safe_id_options( 'articles', [ $this, 'fetch_sale_article_options' ] );

		$this->form_fields = [
			'api_section'                              => [
				'title' => __( 'API connection', 'e-financials' ),
				'type'  => 'title',
			],
			self::SETTING_KEY_API_KEY_ID               => [
				'title'       => __( 'API Key ID', 'e-financials' ),
				'type'        => 'text',
				'description' => __( 'View guide <a href="https://abiinfo.rik.ee/en/node/303">here</a>.', 'e-financials' ),
				'desc_tip'    => false,
				'default'     => '',
			],
			self::SETTING_KEY_API_KEY_PUBLIC           => [
				'title'    => __( 'API Key Public', 'e-financials' ),
				'type'     => 'text',
				'desc_tip' => false,
				'default'  => '',
			],
			self::SETTING_KEY_API_KEY_PASSWORD         => [
				'title'    => __( 'API Key Password', 'e-financials' ),
				'type'     => 'password',
				'desc_tip' => false,
				'default'  => '',
			],
			self::SETTING_KEY_API_ENVIRONMENT          => [
				'title'       => __( 'API Environment', 'e-financials' ),
				'type'        => 'select',
				'label'       => __( 'Choose the environment', 'e-financials' ),
				'default'     => self::SETTING_KEY_API_ENVIRONMENT_OPTION_TEST,
				'description' => __( 'View <a href="https://demo-rmp.rik.ee">test environment</a> or <a href="https://e-arveldaja.rik.ee/">live environment</a>.', 'e-financials' ),
				'options'     => [
					self::SETTING_KEY_API_ENVIRONMENT_OPTION_TEST => __( 'Test Environment', 'e-financials' ),
					self::SETTING_KEY_API_ENVIRONMENT_OPTION_LIVE => __( 'Live Environment', 'e-financials' ),
				],
			],
			'invoice_section'                          => [
				'title' => __( 'Invoicing', 'e-financials' ),
				'type'  => 'title',
			],
			self::SETTING_KEY_INVOICE_SERIES_ID        => [
				'title'       => __( 'Invoice series', 'e-financials' ),
				'type'        => 'select',
				'description' => __( 'Number prefix of the selected series is sent as number_prefix on every sale invoice. Leave empty to let e-Financials number invoices itself.', 'e-financials' ),
				'default'     => '',
				'options'     => $series_options,
			],
			self::SETTING_KEY_TEMPLATE_ID              => [
				'title'       => __( 'Invoice template', 'e-financials' ),
				'type'        => 'select',
				'description' => __( 'Sale invoice template (cl_templates_id). Required before first sync.', 'e-financials' ),
				'default'     => '',
				'options'     => $template_options,
			],
			self::SETTING_KEY_SALE_ARTICLE_ID          => [
				'title'       => __( 'Default sale article', 'e-financials' ),
				'type'        => 'select',
				'description' => __( 'Required: e-Financials refuses to create products without a sale account, and books VAT by article. Its VAT rate must match the rate your shop charges.', 'e-financials' ),
				'default'     => '',
				'options'     => $article_options,
			],
			self::SETTING_KEY_SALE_ARTICLE_MAP         => [
				'title'       => __( 'VAT rate → sale article map (JSON)', 'e-financials' ),
				'type'        => 'textarea',
				'description' => __( 'Required for mixed-rate catalogues and for 0% lines — including shops with WooCommerce taxes switched off, where every line is 0%. Example: {"22":1,"9":5,"0":12}. Each order line uses the article mapped to its WooCommerce tax rate; unmapped rates fall back to the default article and sync fails if the rates disagree.', 'e-financials' ),
				'default'     => '',
				'css'         => 'width:100%;min-height:80px;font-family:monospace',
			],
			self::SETTING_KEY_TERM_DAYS                => [
				'title'   => __( 'Payment term (days)', 'e-financials' ),
				'type'    => 'number',
				'default' => '14',
			],
			self::SETTING_KEY_USE_WC_ORDER_NUMBER      => [
				'title'   => __( 'Use WooCommerce order number as invoice suffix', 'e-financials' ),
				'type'    => 'checkbox',
				'label'   => __( 'Push WC order number as number_suffix', 'e-financials' ),
				'default' => 'yes',
			],
			'payment_section'                          => [
				'title'       => __( 'Payment recording', 'e-financials' ),
				'type'        => 'title',
				'description' => __( 'Gateway-agnostic: maps WooCommerce payment method ids to cash fields or transactions. No gateway plugin required.', 'e-financials' ),
			],
			self::SETTING_KEY_DEFAULT_PAYMENT_MODE     => [
				'title'   => __( 'Default payment mode', 'e-financials' ),
				'type'    => 'select',
				'default' => SettingsRepository::PAYMENT_MODE_CASH,
				'options' => [
					SettingsRepository::PAYMENT_MODE_CASH => __( 'Cash fields on invoice', 'e-financials' ),
					SettingsRepository::PAYMENT_MODE_TRANSACTION => __( 'Payment transaction', 'e-financials' ),
					SettingsRepository::PAYMENT_MODE_OFF  => __( 'Off (invoice only)', 'e-financials' ),
				],
			],
			self::SETTING_KEY_DEFAULT_CASH_ACCOUNTS_ID => [
				'title'       => __( 'Default cash account id', 'e-financials' ),
				'type'        => 'number',
				'description' => __( 'Used for Option A (paid_in_cash) when the gateway map does not override.', 'e-financials' ),
				'default'     => '',
			],
			self::SETTING_KEY_DEFAULT_ACCOUNTS_DIMENSIONS_ID => [
				'title'       => __( 'Default accounts dimension id', 'e-financials' ),
				'type'        => 'number',
				'description' => __( 'Used for Option B (transactions) when the gateway map does not override.', 'e-financials' ),
				'default'     => '',
			],
			self::SETTING_KEY_GATEWAY_MAP              => [
				'title'       => __( 'Per-gateway payment map (JSON)', 'e-financials' ),
				'type'        => 'textarea',
				'description' => __( 'Example: {"bacs":{"mode":"transaction","accounts_dimensions_id":4},"cod":{"mode":"cash","cash_accounts_id":1010}}. Empty uses built-in defaults for bacs/cheque/cod.', 'e-financials' ),
				'default'     => '',
				'css'         => 'width:100%;min-height:120px;font-family:monospace',
			],
			'delivery_section'                         => [
				'title' => __( 'Delivery & products', 'e-financials' ),
				'type'  => 'title',
			],
			self::SETTING_KEY_AUTO_DELIVER             => [
				'title'   => __( 'Auto-deliver invoice email after register', 'e-financials' ),
				'type'    => 'checkbox',
				'label'   => __( 'Send PDF email via e-Financials deliver API', 'e-financials' ),
				'default' => 'no',
			],
			self::SETTING_KEY_AUTO_DELIVER_EINVOICE    => [
				'title'   => __( 'Also send e-invoice (XML) when available', 'e-financials' ),
				'type'    => 'checkbox',
				'label'   => __( 'send_einvoice=true when can_send_einvoice', 'e-financials' ),
				'default' => 'no',
			],
			self::SETTING_KEY_PRODUCT_AUTO_SYNC        => [
				'title'   => __( 'Auto-sync products on save', 'e-financials' ),
				'type'    => 'checkbox',
				'label'   => __( 'Upsert e-Financials products when WooCommerce products are saved', 'e-financials' ),
				'default' => 'no',
			],
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function process_admin_options(): bool { // phpcs:ignore Squiz.Commenting.FunctionCommentThrowTag.Missing -- WC parent signature

		$result = parent::process_admin_options();

		$this->flush_option_cache();
		$this->maybe_connection_ping();

		return $result;
	}

	/**
	 * Drop cached remote option lists after a settings change.
	 */
	private function flush_option_cache(): void {

		foreach ( [ 'series', 'templates', 'articles' ] as $bucket ) {
			\delete_transient( self::OPTIONS_TRANSIENT_PREFIX . $bucket );
		}

		// Credentials or environment may have changed; ids cached for the old
		// tenant must never be reused on the new one.
		foreach ( RemoteLookup::cache_keys() as $key ) {
			\delete_transient( $key );
		}
	}

	/**
	 * Whether the current request is this integration's settings screen.
	 *
	 * Remote option lists are only worth loading there; every other admin
	 * request that instantiates integrations must stay HTTP-free.
	 */
	private function is_own_settings_screen(): bool {

		if ( ! \is_admin() ) {
			return false;
		}

		$page    = $this->query_arg( 'page' );
		$tab     = $this->query_arg( 'tab' );
		$section = $this->query_arg( 'section' );

		if ( $page !== 'wc-settings' || $tab !== 'integration' ) {
			return false;
		}

		// An empty section means WooCommerce is showing the first integration.
		return $section === '' || $section === $this->id;
	}

	/**
	 * Read a string query argument.
	 *
	 * @param string $key Query key.
	 */
	private function query_arg( string $key ): string {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized below once the type is known.
		$value = $_GET[ $key ] ?? '';

		if ( ! \is_string( $value ) ) {
			return '';
		}

		return \sanitize_text_field( \wp_unslash( $value ) );
	}

	/**
	 * Smoke-test credentials after save.
	 */
	private function maybe_connection_ping(): void {

		$id       = (string) $this->get_option( self::SETTING_KEY_API_KEY_ID, '' );
		$public   = (string) $this->get_option( self::SETTING_KEY_API_KEY_PUBLIC, '' );
		$password = (string) $this->get_option( self::SETTING_KEY_API_KEY_PASSWORD, '' );

		if ( $id === '' || $public === '' || $password === '' ) {
			return;
		}

		try {
			$live = $this->get_option( self::SETTING_KEY_API_ENVIRONMENT )
				=== self::SETTING_KEY_API_ENVIRONMENT_OPTION_LIVE;

			$client = EFinancials::factory()
				->withApiKeyId( $id )
				->withApiKeyPublic( $public )
				->withApiKeyPassword( $password )
				->withBaseUri( $live ? 'https://rmp-api.rik.ee' : 'https://demo-rmp-api.rik.ee' )
				->make();

			$client->currencies()->all();

			\WC_Admin_Settings::add_message( __( 'e-Financials connection OK.', 'e-financials' ) );
		} catch ( Throwable $e ) {
			\WC_Admin_Settings::add_error(
				\sprintf(
					/* translators: %s: error */
					__( 'e-Financials connection failed: %s', 'e-financials' ),
					ErrorMessage::sanitize( $e->getMessage() )
				)
			);
		}
	}

	/**
	 * Provide arguments.
	 *
	 * @param string                                $bucket   Cache bucket name.
	 * @param callable(): array<int|string, string> $callback Options loader.
	 *
	 * @return array<int|string, string>
	 */
	private function safe_id_options( string $bucket, callable $callback ): array {

		$blank = [ '' => __( '— Select —', 'e-financials' ) ];

		if ( ! $this->is_own_settings_screen() ) {
			return $blank;
		}

		$cached = \get_transient( self::OPTIONS_TRANSIENT_PREFIX . $bucket );

		if ( \is_array( $cached ) ) {
			/**
			 * Cached option list.
			 *
			 * @var array<int|string, string> $cached
			 */
			return $blank + $cached;
		}

		try {
			$options = $callback();
		} catch ( Throwable $e ) {
			\error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Surfaces why the option list is empty.
				'[e-financials] Failed to load ' . $bucket . ' options: ' . $e->getMessage()
			);

			return $blank + [ '' => __( 'Could not load options — check credentials and the error log', 'e-financials' ) ];
		}

		\set_transient( self::OPTIONS_TRANSIENT_PREFIX . $bucket, $options, self::OPTIONS_TRANSIENT_TTL );

		return $blank + $options;
	}

	/**
	 * Get value.
	 *
	 * @return array<int|string, string>
	 */
	private function fetch_invoice_series_options(): array {

		$client  = $this->make_client_from_posted_or_saved();
		$list    = $client->invoices()->all();
		$options = [];

		foreach ( $list->data as $series ) {
			if ( $series->id === null ) {
				continue;
			}

			$label                           = \trim( $series->numberPrefix );
			$extra                           = $series->isDefault ? ' (default)' : '';
			$options[ (string) $series->id ] = ( $label !== '' ? $label : (string) $series->id ) . $extra;
		}

		return $options;
	}

	/**
	 * Get value.
	 *
	 * @return array<int|string, string>
	 */
	private function fetch_template_options(): array {

		$client  = $this->make_client_from_posted_or_saved();
		$list    = $client->templates()->all();
		$options = [];

		foreach ( $list->data as $template ) {
			$label                             = $template->name !== '' ? $template->name : (string) $template->id;
			$options[ (string) $template->id ] = $label . ( $template->isDefault ? ' (default)' : '' );
		}

		return $options;
	}

	/**
	 * Get value.
	 *
	 * @return array<int|string, string>
	 */
	private function fetch_sale_article_options(): array {

		$client  = $this->make_client_from_posted_or_saved();
		$list    = $client->salesArticles()->all();
		$options = [];

		foreach ( $list->data as $article ) {
			if ( $article->id === null ) {
				continue;
			}

			$label                            = $article->nameEng !== '' ? $article->nameEng : $article->nameEst;
			$options[ (string) $article->id ] = $label !== '' ? $label : (string) $article->id;
		}

		return $options;
	}

	/**
	 * May throw on failure.
	 *
	 * @throws \RuntimeException When credentials missing.
	 */
	private function make_client_from_posted_or_saved(): \EFinancialsClient\Contracts\ClientContract {

		$id       = (string) $this->get_option( self::SETTING_KEY_API_KEY_ID, '' );
		$public   = (string) $this->get_option( self::SETTING_KEY_API_KEY_PUBLIC, '' );
		$password = (string) $this->get_option( self::SETTING_KEY_API_KEY_PASSWORD, '' );

		if ( $id === '' || $public === '' || $password === '' ) {
			throw new \RuntimeException( 'Missing credentials' );
		}

		$live = $this->get_option( self::SETTING_KEY_API_ENVIRONMENT )
			=== self::SETTING_KEY_API_ENVIRONMENT_OPTION_LIVE;

		return EFinancials::factory()
			->withApiKeyId( $id )
			->withApiKeyPublic( $public )
			->withApiKeyPassword( $password )
			->withBaseUri( $live ? 'https://rmp-api.rik.ee' : 'https://demo-rmp-api.rik.ee' )
			->make();
	}
}
