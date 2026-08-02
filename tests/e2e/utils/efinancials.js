/**
 * Helpers for live/demo e-Financials API e2e coverage.
 * Credentials are never committed — read from Cursor Cloud Secrets / env.
 */

const { execFileSync } = require( 'child_process' );

const SECRET_ALIASES = {
	id: [ 'E_FINANCIALS_API_KEY_ID', 'EFINANCIALS_API_KEY_ID' ],
	public: [ 'E_FINANCIALS_API_KEY_PUBLIC', 'EFINANCIALS_API_KEY_PUBLIC' ],
	password: [ 'E_FINANCIALS_API_KEY_PASSWORD', 'EFINANCIALS_API_KEY_PASSWORD' ],
};

/**
 * @param {string[]} names
 * @returns {string}
 */
function firstEnv( names ) {
	for ( const name of names ) {
		const value = process.env[ name ];
		if ( typeof value === 'string' && value.trim() !== '' ) {
			return value.trim();
		}
	}
	return '';
}

/**
 * @returns {{ id: string, publicKey: string, password: string }}
 */
function getDemoCredentials() {
	return {
		id: firstEnv( SECRET_ALIASES.id ),
		publicKey: firstEnv( SECRET_ALIASES.public ),
		password: firstEnv( SECRET_ALIASES.password ),
	};
}

/**
 * @returns {boolean}
 */
function hasDemoCredentials() {
	const c = getDemoCredentials();
	return Boolean( c.id && c.publicKey && c.password );
}

/**
 * Run `wp eval` inside wp-env CLI and return stdout (stderr filtered).
 *
 * @param {string} php
 * @returns {string}
 */
function wpEval( php ) {
	const out = execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval', php ], {
		cwd: process.cwd(),
		encoding: 'utf8',
		stdio: [ 'pipe', 'pipe', 'pipe' ],
		env: process.env,
		maxBuffer: 10 * 1024 * 1024,
	} );
	return String( out )
		.split( '\n' )
		.filter( ( line ) => ! line.startsWith( 'Deprecated:' ) && ! line.startsWith( 'ℹ' ) && ! line.startsWith( '✔' ) )
		.join( '\n' )
		.trim();
}

/**
 * Probe demo API with injected credentials (HMAC via php-client on the host).
 * wp-env CLI does not inherit host secrets; live sync uses credentials saved into WP options.
 *
 * @returns {{ ok: boolean, message: string, templates?: Array<{id:number,name:string}>, series?: Array<{id:number,label:string}>, articles?: Array<{id:number,name:string}> }}
 */
function probeDemoApiFromHost() {
	const creds = getDemoCredentials();
	const script = `
require "vendor/autoload.php";
$id = getenv("E_FINANCIALS_API_KEY_ID") ?: getenv("EFINANCIALS_API_KEY_ID");
$pub = getenv("E_FINANCIALS_API_KEY_PUBLIC") ?: getenv("EFINANCIALS_API_KEY_PUBLIC");
$pwd = getenv("E_FINANCIALS_API_KEY_PASSWORD") ?: getenv("EFINANCIALS_API_KEY_PASSWORD");
try {
	$client = EFinancials::factory()
		->withApiKeyId($id)
		->withApiKeyPublic($pub)
		->withApiKeyPassword($pwd)
		->withBaseUri("https://demo-rmp-api.rik.ee")
		->make();
	$client->currencies()->all();
	$out = ["ok"=>true,"message"=>"connection OK","templates"=>[],"series"=>[],"articles"=>[]];
	try { foreach ($client->templates()->all()->data as $row) { $out["templates"][] = ["id"=>(int)$row->id,"name"=>(string)($row->name ?? $row->title ?? $row->id)]; } } catch (Throwable $e) {}
	try { foreach ($client->invoices()->all()->data as $row) { $out["series"][] = ["id"=>(int)$row->id,"label"=>(string)($row->numberPrefix ?? $row->number_prefix ?? $row->id)]; } } catch (Throwable $e) {}
	try { foreach ($client->salesArticles()->all()->data as $row) { $out["articles"][] = ["id"=>(int)$row->id,"name"=>(string)($row->name ?? $row->id)]; } } catch (Throwable $e) {}
	echo json_encode($out);
} catch (Throwable $e) {
	echo json_encode(["ok"=>false,"message"=>$e->getMessage()]);
}
`;
	try {
		const out = execFileSync( 'php', [ '-r', script ], {
			cwd: process.cwd(),
			encoding: 'utf8',
			env: process.env,
		} );
		return JSON.parse( out.trim() );
	} catch ( err ) {
		return {
			ok: false,
			message: err instanceof Error ? err.message : String( err ),
		};
	}
}

/**
 * Persist demo credentials + discovered IDs into WC integration settings via WP-CLI.
 *
 * @param {{ templateId: number, seriesId?: number, articleId?: number, paymentMode?: string, cashAccountsId?: number, accountsDimensionsId?: number }} opts
 */
function configurePluginSettings( opts ) {
	const creds = getDemoCredentials();
	const templateId = Number( opts.templateId ) || 0;
	const seriesId = Number( opts.seriesId ) || 0;
	const articleId = Number( opts.articleId ) || 0;
	const paymentMode = opts.paymentMode || 'off';
	const cashAccountsId = Number( opts.cashAccountsId ) || 0;
	const accountsDimensionsId = Number( opts.accountsDimensionsId ) || 0;

	// Write settings without embedding secrets in the shell history: pass via PHP getenv from host… but CLI container has no secrets.
	// Instead, pipe a JSON blob through wp option update using a here-doc written from Node.
	const settings = {
		api_key_id: creds.id,
		api_key_public: creds.publicKey,
		api_key_password: creds.password,
		api_key_environment: 'api_environment_test',
		cl_templates_id: String( templateId ),
		invoice_series_id: seriesId ? String( seriesId ) : '',
		cl_sale_articles_id: articleId ? String( articleId ) : '',
		term_days: '14',
		use_wc_order_number: 'yes',
		default_payment_mode: paymentMode,
		default_cash_accounts_id: cashAccountsId ? String( cashAccountsId ) : '',
		default_accounts_dimensions_id: accountsDimensionsId
			? String( accountsDimensionsId )
			: '',
		gateway_payment_map: '',
		auto_deliver: 'no',
		auto_deliver_einvoice: 'no',
		product_auto_sync: 'no',
	};

	const json = JSON.stringify( settings );
	const php = `
$json = <<<'JSON'
${ json }
JSON;
$settings = json_decode( $json, true );
if ( ! is_array( $settings ) ) { echo 'bad_json'; return; }
update_option( 'woocommerce_efinancials_integration_settings', $settings );
echo 'configured';
`;
	const result = wpEval( php );
	if ( ! result.includes( 'configured' ) ) {
		throw new Error( `Failed to configure plugin settings: ${ result }` );
	}
}

/**
 * Create a paid order suitable for sync and return its ID.
 *
 * @returns {number}
 */
function createPaidOrder() {
	const php = `
$products = wc_get_products( array( 'limit' => 1, 'status' => 'publish' ) );
if ( ! $products ) {
	$p = new WC_Product_Simple();
	$p->set_name( 'Live E2E Product ' . gmdate( 'His' ) );
	$p->set_regular_price( '12.50' );
	$p->set_status( 'publish' );
	$p->save();
	$product_id = $p->get_id();
} else {
	$product_id = $products[0]->get_id();
}
$order = wc_create_order();
$order->add_product( wc_get_product( $product_id ), 1 );
$order->set_billing_email( 'live-e2e-' . time() . '@example.com' );
$order->set_billing_first_name( 'Live' );
$order->set_billing_last_name( 'E2E' );
$order->set_billing_address_1( 'Test 1' );
$order->set_billing_city( 'Tallinn' );
$order->set_billing_postcode( '10111' );
$order->set_billing_country( 'EE' );
$order->set_payment_method( 'cod' );
$order->set_payment_method_title( 'Cash on delivery' );
$order->set_status( 'processing' );
$order->calculate_totals();
$order->save();
// Do not call payment_complete() here — that enqueues a background sync and races the explicit sync step.
echo 'ORDER_ID=' . (string) $order->get_id();
`;
	const raw = wpEval( php );
	const match = raw.match( /ORDER_ID=(\d+)/ );
	const orderId = match ? Number( match[ 1 ] ) : 0;
	if ( ! orderId ) {
		throw new Error( `Could not create paid order: ${ raw }` );
	}
	return orderId;
}

/**
 * Synchronously run the sync job for an order (bypasses AS delay).
 *
 * @param {number} orderId
 * @returns {{ ok: boolean, invoiceId?: string, invoiceNumber?: string, error?: string, clientsId?: string, paymentMode?: string }}
 */
function syncOrderNow( orderId ) {
	const php = `
$order_id = ${ Number( orderId ) };
try {
	do_action( 'ef_sync_order_to_efinancials', $order_id );
	$order = wc_get_order( $order_id );
	echo json_encode( array(
		'ok' => (bool) $order->get_meta( '_ef_sale_invoice_id' ),
		'invoiceId' => (string) $order->get_meta( '_ef_sale_invoice_id' ),
		'invoiceNumber' => (string) $order->get_meta( '_ef_sale_invoice_number' ),
		'clientsId' => (string) $order->get_meta( '_ef_clients_id' ),
		'paymentMode' => (string) $order->get_meta( '_ef_payment_mode' ),
		'error' => (string) $order->get_meta( '_ef_last_error' ),
		'syncedAt' => (string) $order->get_meta( '_ef_synced_at' ),
	) );
} catch ( Throwable $e ) {
	$order = wc_get_order( $order_id );
	echo json_encode( array(
		'ok' => false,
		'error' => $e->getMessage(),
		'invoiceId' => $order ? (string) $order->get_meta( '_ef_sale_invoice_id' ) : '',
		'lastError' => $order ? (string) $order->get_meta( '_ef_last_error' ) : '',
	) );
}
`;
	const raw = wpEval( php );
	const jsonLine = raw
		.split( '\n' )
		.reverse()
		.find( ( line ) => line.trim().startsWith( '{' ) );
	if ( ! jsonLine ) {
		return { ok: false, error: raw };
	}
	return JSON.parse( jsonLine );
}

/**
 * Read order meta map for assertions.
 *
 * @param {number} orderId
 */
function getOrderSyncMeta( orderId ) {
	const php = `
$order = wc_get_order( ${ Number( orderId ) } );
if ( ! $order ) { echo '{}'; return; }
echo json_encode( array(
	'invoiceId' => (string) $order->get_meta( '_ef_sale_invoice_id' ),
	'invoiceNumber' => (string) $order->get_meta( '_ef_sale_invoice_number' ),
	'clientsId' => (string) $order->get_meta( '_ef_clients_id' ),
	'paymentMode' => (string) $order->get_meta( '_ef_payment_mode' ),
	'error' => (string) $order->get_meta( '_ef_last_error' ),
	'syncedAt' => (string) $order->get_meta( '_ef_synced_at' ),
) );
`;
	const raw = wpEval( php );
	const jsonLine = raw
		.split( '\n' )
		.reverse()
		.find( ( line ) => line.trim().startsWith( '{' ) );
	return jsonLine ? JSON.parse( jsonLine ) : {};
}

module.exports = {
	getDemoCredentials,
	hasDemoCredentials,
	probeDemoApiFromHost,
	configurePluginSettings,
	createPaidOrder,
	syncOrderNow,
	getOrderSyncMeta,
	wpEval,
};
