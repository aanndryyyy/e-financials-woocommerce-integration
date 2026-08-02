const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin, openEFinancialsSettings } = require( '../utils/backend' );
const { openShop } = require( '../utils/frontend' );

/**
 * Atomic smoke coverage for critical merchant + storefront flows.
 * Each test sets up its own preconditions and does not depend on others.
 */
test.describe( 'e-Financials smoke', () => {
	test( 'plugin is active and e-Financials settings screen loads', async ( {
		page,
	} ) => {
		await openEFinancialsSettings( page );

		await expect( page.getByRole( 'heading', { name: 'e-Financials' } ) ).toBeVisible();
		await expect( page.getByLabel( 'API Key ID' ) ).toBeVisible();
		await expect( page.getByLabel( 'API Key Public' ) ).toBeVisible();
		await expect( page.locator( '#woocommerce_efinancials_integration_api_key_environment' ) ).toBeVisible();
	} );

	test( 'merchant can save test environment setting', async ( { page } ) => {
		await openEFinancialsSettings( page );

		const environment = page.locator(
			'#woocommerce_efinancials_integration_api_key_environment'
		);
		await environment.selectOption( 'api_environment_test' );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();

		await expect( page.getByText( 'Your settings have been saved' ) ).toBeVisible();
		await expect( environment ).toHaveValue( 'api_environment_test' );
	} );

	test( 'storefront loads while plugin is active', async ( { page } ) => {
		await openShop( page );
		await expect( page.locator( 'body' ) ).toBeVisible();
		await expect( page ).not.toHaveTitle( /error/i );
	} );

	test( 'wp-admin dashboard is reachable', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/' );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	} );
} );
