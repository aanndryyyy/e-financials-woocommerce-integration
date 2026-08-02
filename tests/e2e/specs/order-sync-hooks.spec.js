const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'child_process' );
const {
	loginAsAdmin,
	openEFinancialsSettings,
	expectSettingsSaved,
} = require( '../utils/backend' );

/**
 * Ensure at least one order exists so the classic orders list (with columns) renders.
 */
function ensureSampleOrder() {
	const php = `
$products = wc_get_products( array( 'limit' => 1 ) );
if ( ! $products ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'E2E Product' );
	$p->set_regular_price( '10' );
	$p->set_status( 'publish' );
	$p->save();
	$product_id = $p->get_id();
} else {
	$product_id = $products[0]->get_id();
}
$orders = wc_get_orders( array( 'limit' => 1, 'return' => 'ids' ) );
if ( ! $orders ) {
	$order = wc_create_order();
	$order->add_product( wc_get_product( $product_id ), 1 );
	$order->set_billing_email( 'e2e@example.com' );
	$order->set_billing_first_name( 'E2E' );
	$order->set_billing_last_name( 'Buyer' );
	$order->set_billing_country( 'EE' );
	$order->calculate_totals();
	$order->save();
}
echo 'ok';
`;
	execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval', php ], {
		cwd: process.cwd(),
		stdio: 'pipe',
	} );
}

/**
 * Verifies sync-related admin order UI without requiring live e-Financials API keys.
 */
test.describe( 'e-Financials order sync UI', () => {
	test( 'orders list shows e-Financials column', async ( { page } ) => {
		ensureSampleOrder();
		await loginAsAdmin( page );

		// Legacy CPT list (HPOS is off in default wp-env). Also try HPOS path.
		await page.goto( '/wp-admin/edit.php?post_type=shop_order' );

		const column = page
			.locator( 'th.column-ef_invoice, thead th' )
			.filter( { hasText: 'e-Financials' } );

		if ( ( await column.count() ) === 0 ) {
			await page.goto( '/wp-admin/admin.php?page=wc-orders' );
		}

		await expect( column.first() ).toBeVisible( { timeout: 30_000 } );
	} );

	test( 'credentials can be saved for later sync jobs', async ( { page } ) => {
		await openEFinancialsSettings( page );

		await page.locator( '#woocommerce_efinancials_integration_api_key_id' ).fill( 'e2e-test-id' );
		await page
			.locator( '#woocommerce_efinancials_integration_api_key_public' )
			.fill( 'e2e-test-public' );
		await page
			.locator( '#woocommerce_efinancials_integration_api_key_password' )
			.fill( 'e2e-test-password' );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();

		await expectSettingsSaved( page );
		await expect(
			page.locator( '#woocommerce_efinancials_integration_api_key_id' )
		).toHaveValue( 'e2e-test-id' );
	} );
} );
