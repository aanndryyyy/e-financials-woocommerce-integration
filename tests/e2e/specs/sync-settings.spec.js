const { test, expect } = require( '@playwright/test' );
const {
	openEFinancialsSettings,
	expectSettingsSaved,
} = require( '../utils/backend' );

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
} );
