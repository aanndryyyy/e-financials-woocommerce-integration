/**
 * Storefront (frontend / customer) helpers.
 * Separate from backend helpers per WCOM e2e guidance.
 */

/**
 * Open the shop home page.
 *
 * @param {import('@playwright/test').Page} page
 */
async function openShop( page ) {
	await page.goto( '/' );
}

module.exports = {
	openShop,
};
