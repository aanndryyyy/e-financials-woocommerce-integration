<?php
/**
 * Builds authenticated e-Financials API clients.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Api;

use Aanndryyyy\EFinancialsPlugin\Settings\SettingsRepository;
use EFinancials;
use EFinancialsClient\Client;
use EFinancialsClient\Contracts\ClientContract;
use RuntimeException;

/**
 * Factory around EFinancials::factory().
 */
class ClientFactory {

	private const LIVE_BASE_URI = 'https://rmp-api.rik.ee';

	private const DEMO_BASE_URI = 'https://demo-rmp-api.rik.ee';

	/**
	 * Provide arguments.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct(
		private readonly SettingsRepository $settings
	) {
	}

	/**
	 * Whether credentials are configured.
	 */
	public function can_make(): bool {

		return $this->settings->has_credentials();
	}

	/**
	 * Create an API client from stored settings.
	 *
	 * @throws RuntimeException When credentials are missing.
	 */
	public function make(): ClientContract {

		if ( ! $this->can_make() ) {
			throw new RuntimeException( 'e-Financials API credentials are not configured.' );
		}

		$base_uri = $this->settings->is_live() ? self::LIVE_BASE_URI : self::DEMO_BASE_URI;

		$client = EFinancials::factory()
			->withApiKeyId( $this->settings->api_key_id() )
			->withApiKeyPublic( $this->settings->api_key_public() )
			->withApiKeyPassword( $this->settings->api_key_password() )
			->withBaseUri( $base_uri )
			->make();

		return $client;
	}
}
