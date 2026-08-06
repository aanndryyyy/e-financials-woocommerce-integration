const { test, expect } = require( '@playwright/test' );
const {
	wpEval,
	openEFinancialsSettings,
	expectSettingsSaved,
} = require( '../utils/backend' );

const SETTINGS_OPTION = 'woocommerce_efinancials_integration_settings';

/**
 * Store credentials that are well-formed enough to be attempted and are then
 * rejected, and drop the cached option lists so the next settings render has to
 * go and fetch.
 *
 * The previous value comes back base64 encoded so it can be handed straight to
 * restoreSettings() without worrying about quoting the JSON inside it.
 *
 * @return {string} Opaque backup token for restoreSettings().
 */
function breakCredentials() {
	const output = wpEval( `
$settings = get_option( '${ SETTINGS_OPTION }' );
$settings = is_array( $settings ) ? $settings : array();
$backup   = base64_encode( serialize( $settings ) );

$settings['api_key_id']          = 'broken-key-id';
$settings['api_key_public']      = 'broken-key-public';
$settings['api_key_password']    = 'broken-key-password';
$settings['api_key_environment'] = 'api_environment_test';
update_option( '${ SETTINGS_OPTION }', $settings );

foreach ( array( 'series', 'templates', 'articles' ) as $bucket ) {
	delete_transient( 'ef_settings_options_' . $bucket );
}

echo 'EF_BACKUP' . $backup . 'EF_END';
` );

	const match = output.match( /EF_BACKUP(.*)EF_END/s );
	expect( match, `could not back up settings:\n${ output }` ).not.toBeNull();

	return match[ 1 ];
}

/**
 * @param {string} backup Token returned by breakCredentials().
 */
function restoreSettings( backup ) {
	wpEval( `
$backup = unserialize( base64_decode( '${ backup }' ) );
update_option( '${ SETTINGS_OPTION }', is_array( $backup ) ? $backup : array() );

foreach ( array( 'series', 'templates', 'articles' ) as $bucket ) {
	delete_transient( 'ef_settings_options_' . $bucket );
}
echo 'ok';
` );
}

/**
 * Covers merchant-facing sync settings added by the e-Financials implementation.
 * Atomic: each test opens settings itself and does not share mutable order state.
 */
test.describe( 'e-Financials sync settings', () => {
	test( 'invoice series and template fields are present', async ( { page } ) => {
		await openEFinancialsSettings( page );

		await expect(
			page.locator( '#woocommerce_efinancials_integration_invoice_series_id' )
		).toBeVisible();
		await expect(
			page.locator( '#woocommerce_efinancials_integration_cl_templates_id' )
		).toBeVisible();
		await expect(
			page.locator( '#woocommerce_efinancials_integration_term_days' )
		).toBeVisible();
	} );

	test( 'payment mode map controls are present and saveable', async ( { page } ) => {
		await openEFinancialsSettings( page );

		// Avoid connection ping masking the saved notice.
		await page.locator( '#woocommerce_efinancials_integration_api_key_id' ).fill( '' );
		await page.locator( '#woocommerce_efinancials_integration_api_key_public' ).fill( '' );
		await page.locator( '#woocommerce_efinancials_integration_api_key_password' ).fill( '' );

		const mode = page.locator(
			'#woocommerce_efinancials_integration_default_payment_mode'
		);
		await expect( mode ).toBeVisible();
		await mode.selectOption( 'cash' );

		const gatewayMap = page.locator(
			'#woocommerce_efinancials_integration_gateway_payment_map'
		);
		await expect( gatewayMap ).toBeVisible();
		await gatewayMap.fill(
			JSON.stringify( {
				bacs: { mode: 'transaction', accounts_dimensions_id: 4 },
				cod: { mode: 'cash', cash_accounts_id: 1010 },
			} )
		);

		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expectSettingsSaved( page );
		await expect( mode ).toHaveValue( 'cash' );
		await expect( gatewayMap ).toContainText( 'bacs' );
	} );

	test( 'delivery and product sync toggles save', async ( { page } ) => {
		await openEFinancialsSettings( page );

		await page.locator( '#woocommerce_efinancials_integration_api_key_id' ).fill( '' );
		await page.locator( '#woocommerce_efinancials_integration_api_key_public' ).fill( '' );
		await page.locator( '#woocommerce_efinancials_integration_api_key_password' ).fill( '' );

		const autoDeliver = page.locator(
			'#woocommerce_efinancials_integration_auto_deliver'
		);
		const productSync = page.locator(
			'#woocommerce_efinancials_integration_product_auto_sync'
		);

		await expect( autoDeliver ).toBeVisible();
		await expect( productSync ).toBeVisible();

		await autoDeliver.check();
		await productSync.check();
		await page.getByRole( 'button', { name: 'Save changes' } ).click();

		await expectSettingsSaved( page );
		await expect( autoDeliver ).toBeChecked();
		await expect( productSync ).toBeChecked();
	} );

	// Regression guard: the failure branch used to return the message under the
	// same '' key as the "— Select —" placeholder. An array union keeps the left
	// operand, so the message was dropped and the merchant was left with three
	// empty dropdowns and the reason only in the error log.
	test( 'remote dropdowns explain themselves when the options cannot be loaded', async ( {
		page,
	} ) => {
		const backup = breakCredentials();

		try {
			await openEFinancialsSettings( page );

			for ( const field of [
				'invoice_series_id',
				'cl_templates_id',
				'cl_sale_articles_id',
			] ) {
				const select = page.locator(
					`#woocommerce_efinancials_integration_${ field }`
				);

				await expect( select ).toBeVisible();
				await expect( select ).toContainText( /Could not load options/i );
			}
		} finally {
			restoreSettings( backup );
		}
	} );
} );
