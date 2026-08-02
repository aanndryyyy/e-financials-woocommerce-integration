// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

/**
 * Playwright + wp-env setup following current WooCommerce e2e practices.
 *
 * @see https://github.com/woocommerce/woocommerce/tree/trunk/plugins/woocommerce/tests/e2e-pw
 * @see https://developer.woocommerce.com/testing-extensions-and-maintaining-quality-code/best-practices-for-writing-and-running-end-to-end-tests/
 */
module.exports = defineConfig( {
	testDir: './specs',
	outputDir: './test-results',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: 1,
	reporter: [ [ 'list' ], [ 'html', { open: 'never', outputFolder: 'playwright-report' } ] ],
	timeout: 60_000,
	expect: {
		timeout: 15_000,
	},
	use: {
		baseURL: process.env.BASE_URL || 'http://localhost:8888',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		...devices[ 'Desktop Chrome' ],
	},
} );
