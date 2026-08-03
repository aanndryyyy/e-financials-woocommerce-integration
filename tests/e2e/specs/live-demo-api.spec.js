const { test, expect } = require( '@playwright/test' );
const {
	openEFinancialsSettings,
	expectNotice,
} = require( '../utils/backend' );
const {
	hasDemoCredentials,
	probeDemoApiFromHost,
	pickSyncableArticle,
	configurePluginSettings,
	setStoreCurrency,
	createPaidOrder,
	syncOrderNow,
	getOrderSyncMeta,
	getDemoCredentials,
} = require( '../utils/efinancials' );

/**
 * Live demo API coverage. Requires Cursor Cloud Secrets:
 *   E_FINANCIALS_API_KEY_ID / EFINANCIALS_API_KEY_ID
 *   E_FINANCIALS_API_KEY_PUBLIC / EFINANCIALS_API_KEY_PUBLIC
 *   E_FINANCIALS_API_KEY_PASSWORD / EFINANCIALS_API_KEY_PASSWORD
 *
 * Skips only when secrets are absent (CI without secrets).
 * Fails clearly when secrets are present but RIK rejects auth (wrong password
 * or API key IP allowlist blocking the agent egress IP).
 */
test.describe( 'e-Financials live demo API @live', () => {
	test.describe.configure( { timeout: 180_000 } );

	test.skip( ! hasDemoCredentials(), 'Demo API secrets not configured in environment' );

	/** @type {ReturnType<typeof probeDemoApiFromHost>} */
	let probe;

	test.beforeAll( () => {
		probe = probeDemoApiFromHost();
	} );

	test( 'demo credentials authenticate against demo-rmp-api.rik.ee', async () => {
		if ( ! probe.ok ) {
			const egressHint =
				'RIK returns Unauthorized for the injected demo credentials. ' +
				'Confirm the ApiKey password is correct and that the key allows ' +
				'this agent egress IP (or 0.0.0.0/0) in demo-rmp.rik.ee → Settings → API keys. ' +
				`API message: ${ probe.message }`;
			throw new Error( egressHint );
		}
		expect( probe.message ).toMatch( /OK/i );
	} );

	test( 'settings save shows connection OK and loads template options', async ( {
		page,
	} ) => {
		test.skip( ! probe.ok, `Demo API auth failed: ${ probe.message }` );

		const creds = getDemoCredentials();
		await openEFinancialsSettings( page );

		await page
			.locator( '#woocommerce_efinancials_integration_api_key_id' )
			.fill( creds.id );
		await page
			.locator( '#woocommerce_efinancials_integration_api_key_public' )
			.fill( creds.publicKey );
		await page
			.locator( '#woocommerce_efinancials_integration_api_key_password' )
			.fill( creds.password );
		await page
			.locator( '#woocommerce_efinancials_integration_api_key_environment' )
			.selectOption( 'api_environment_test' );

		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expectNotice( page, /e-Financials connection OK/i );

		// Reload so dropdowns refetch from demo API.
		await openEFinancialsSettings( page );
		const template = page.locator(
			'#woocommerce_efinancials_integration_cl_templates_id'
		);
		await expect( template ).toBeVisible();
		const options = await template.locator( 'option' ).allTextContents();
		expect(
			options.some( ( t ) => ! /Could not load options/i.test( t ) )
		).toBeTruthy();
	} );

	test( 'paid order syncs to a demo sale invoice', async () => {
		test.skip( ! probe.ok, `Demo API auth failed: ${ probe.message }` );

		const templateId = probe.templates?.[ 0 ]?.id;
		test.skip( ! templateId, 'Demo tenant has no invoice templates' );

		// Not articles[0]: that is whatever the API listed first, often an article
		// posting to a dimensioned account, which e-Financials refuses.
		const article = pickSyncableArticle( probe.articles );
		test.skip(
			! article,
			'Demo tenant has no sale article that is valid, free of account dimensions, and pins no VAT rate'
		);

		configurePluginSettings( {
			templateId,
			seriesId: probe.series?.[ 0 ]?.id,
			articleId: article.id,
			// Avoid cash/transaction account IDs until discovered from tenant.
			paymentMode: 'off',
		} );
		setStoreCurrency( 'EUR' );

		const orderId = createPaidOrder();
		expect( orderId ).toBeGreaterThan( 0 );

		const result = syncOrderNow( orderId );
		if ( ! result.ok ) {
			throw new Error(
				`Order ${ orderId } sync failed using sale article #${ article.id } ` +
					`(${ article.name }, account ${ article.accountsId }, VAT ${ article.vatRate }): ` +
					`${ result.error || result.lastError || 'unknown' }`
			);
		}

		const meta = getOrderSyncMeta( orderId );
		expect( meta.invoiceId ).toBeTruthy();
		expect( meta.clientsId ).toBeTruthy();
		expect( meta.syncedAt ).toBeTruthy();
		expect( meta.error || '' ).toBe( '' );
	} );
} );
