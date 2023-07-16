<?php
/**
 * The file that defines the main start class.
 *
 * A class definition that includes attributes and functions used across both the
 * theme-facing side of the site and the admin area.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Integration;

/**
 * The main start class.
 */
class EFinancialsIntegration extends \WC_Integration {

	const SETTING_KEY_API_KEY_ID = 'api_key_id';

	const SETTING_KEY_API_KEY_PUBLIC = 'api_key_public';

	const SETTING_KEY_API_KEY_PASSWORD = 'api_key_password';

	const SETTING_KEY_API_ENVIRONMENT = 'api_key_environment';

	const SETTING_KEY_API_ENVIRONMENT_OPTION_TEST = 'api_environment_test';

	const SETTING_KEY_API_ENVIRONMENT_OPTION_LIVE = 'api_environment_live';

	/**
	 * Init and hook in the integration.
	 */
	public function __construct() {

		$this->id                 = 'efinancials_integration';
		$this->method_title       = __( 'e-Financials', 'e-financials' );
		$this->method_description = __( 'An integration demo to show you how easy it is to extend WooCommerce.', 'e-financials' );

		$this->init_form_fields();
		$this->init_settings();

		/** @phpstan-ignore-next-line */
		\add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function init_form_fields(): void {

		$this->form_fields = [
			self::SETTING_KEY_API_KEY_ID       => [
				'title'       => __( 'API Key ID', 'e-financials' ),
				'type'        => 'text',
				'description' => __( 'View guide <a href="https://abiinfo.rik.ee/en/node/303">here</a>.', 'e-financials' ),
				'desc_tip'    => false,
				'default'     => '',
			],
			self::SETTING_KEY_API_KEY_PUBLIC   => [
				'title'    => __( 'API Key Public', 'e-financials' ),
				'type'     => 'text',
				'desc_tip' => false,
				'default'  => '',
			],
			self::SETTING_KEY_API_KEY_PASSWORD => [
				'title'    => __( 'API Key Password', 'e-financials' ),
				'type'     => 'password',
				'desc_tip' => false,
				'default'  => '',
			],
			self::SETTING_KEY_API_ENVIRONMENT  => [
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
		];
	}
}
