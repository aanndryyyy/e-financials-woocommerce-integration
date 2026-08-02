/**
 * WP Admin / merchant (backend) helpers.
 * Keep admin flows here so specs stay concise and self-contained.
 */

const ADMIN_USER = process.env.WP_ADMIN_USER || 'admin';
const ADMIN_PASSWORD = process.env.WP_ADMIN_PASSWORD || 'password';

/**
 * Log in to WP Admin. Safe to call when already logged in.
 *
 * @param {import('@playwright/test').Page} page
 */
async function loginAsAdmin( page ) {
	await page.goto( '/wp-login.php' );

	if ( page.url().includes( 'wp-admin' ) ) {
		return;
	}

	await page.locator( '#user_login' ).fill( ADMIN_USER );
	await page.locator( '#user_pass' ).fill( ADMIN_PASSWORD );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( /wp-admin/ );
}

/**
 * Open WooCommerce → Settings → Integration → e-Financials.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openEFinancialsSettings( page ) {
	await loginAsAdmin( page );
	await page.goto(
		'/wp-admin/admin.php?page=wc-settings&tab=integration&section=efinancials_integration'
	);
}

/**
 * Assert settings form saved (success and/or connection ping notice).
 *
 * @param {import('@playwright/test').Page} page
 */
async function expectSettingsSaved( page ) {
	await expectNotice(
		page,
		/Your settings have been saved|e-Financials connection (OK|failed)/i
	);
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {RegExp|string} text
 */
async function expectNotice( page, text ) {
	const { expect } = require( '@playwright/test' );
	await expect( page.locator( '#message, .updated, .error, .notice' ).filter( { hasText: text } ).first() ).toBeVisible( {
		timeout: 20_000,
	} );
}

module.exports = {
	loginAsAdmin,
	openEFinancialsSettings,
	expectSettingsSaved,
	expectNotice,
	ADMIN_USER,
	ADMIN_PASSWORD,
};
