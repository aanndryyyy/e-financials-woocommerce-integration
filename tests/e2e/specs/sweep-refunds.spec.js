const { test, expect } = require( '@playwright/test' );
const { execFileSync } = require( 'child_process' );

/**
 * Run PHP inside the wp-env container and return its stdout.
 *
 * @param {string} php PHP source to evaluate.
 * @return {string} Raw stdout.
 */
function wpEval( php ) {
	return execFileSync( 'npx', [ 'wp-env', 'run', 'cli', 'wp', 'eval', php ], {
		cwd: process.cwd(),
		stdio: 'pipe',
		encoding: 'utf8',
	} );
}

/**
 * Exercise the recurring sweep against a shop that contains a refund.
 *
 * Regression guard: refund posts carry post_status wc-completed, so an untyped
 * wc_get_orders() returns WC_Order_Refund objects alongside orders. Those used
 * to reach RetryState::is_due( WC_Order ) and fatal, killing the safety net for
 * every order behind them. No API keys needed: the sweep only enqueues jobs.
 */
test.describe( 'e-Financials sweep', () => {
	test( 'survives refunds and still enqueues the orders behind them', async () => {
		const php = `
$option = 'woocommerce_efinancials_integration_settings';
$backup = get_option( $option );
$result = array();

$pending = function () {
	return as_get_scheduled_actions( array(
		'hook'     => 'ef_sync_order_to_efinancials',
		'status'   => ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 100,
	) );
};

$drain = function () use ( $pending ) {
	foreach ( $pending() as $action_id => $action ) {
		ActionScheduler::store()->cancel_action( (int) $action_id );
	}
};

try {
	// has_credentials() is a local check, so dummies get us past the guard in
	// handle_sweep() without any request ever leaving the container.
	$settings = is_array( $backup ) ? $backup : array();
	$settings['api_key_id']       = 'sweep-e2e-id';
	$settings['api_key_public']   = 'sweep-e2e-public';
	$settings['api_key_password'] = 'sweep-e2e-password';
	update_option( $option, $settings );

	$order = wc_create_order();
	$order->set_billing_email( 'sweep-e2e@example.com' );
	$order->set_total( 20 );
	$order->set_status( 'completed' );
	$order->save();
	$result['order_id'] = $order->get_id();

	$refund = wc_create_refund( array(
		'order_id' => $order->get_id(),
		'amount'   => '5',
		'reason'   => 'sweep e2e',
	) );
	$result['refund_id']   = is_wp_error( $refund ) ? 0 : $refund->get_id();
	$result['refund_type'] = is_wp_error( $refund ) ? '' : $refund->get_type();

	// Completing the order above already enqueued it via the status hook, so
	// clear the queue first: whatever is pending afterwards came from the sweep.
	$drain();

	try {
		do_action( 'ef_sweep_unsynced_orders' );
		$result['swept'] = true;
		$result['error'] = '';
	} catch ( Throwable $e ) {
		$result['swept'] = false;
		$result['error'] = get_class( $e ) . ': ' . $e->getMessage();
	}

	$queued = array();
	foreach ( $pending() as $action ) {
		$args     = $action->get_args();
		$queued[] = (int) $args[0];
	}
	$result['queued_order']  = in_array( $result['order_id'], $queued, true );
	$result['queued_refund'] = $result['refund_id'] > 0
		&& in_array( $result['refund_id'], $queued, true );
} finally {
	// Teardown runs even if the sweep blew up, so a regression cannot leave
	// dummy credentials or stray jobs behind for the next spec. The drain is
	// shop-wide rather than fixture-scoped; anything it cancels that this test
	// did not create is re-enqueued by the next sweep five minutes later.
	$drain();

	if ( ! empty( $result['refund_id'] ) ) {
		wp_delete_post( (int) $result['refund_id'], true );
	}

	if ( ! empty( $result['order_id'] ) ) {
		wp_delete_post( (int) $result['order_id'], true );
	}

	update_option( $option, $backup );
}

echo 'EF_RESULT' . wp_json_encode( $result ) . 'EF_END';
`;

		const output = wpEval( php );
		const match = output.match( /EF_RESULT(.*)EF_END/s );

		expect( match, `no result marker in output:\n${ output }` ).not.toBeNull();

		const result = JSON.parse( match[ 1 ] );

		// Sanity: the fixture really did create the object that used to fatal.
		expect( result.refund_type ).toBe( 'shop_order_refund' );

		expect( result.error ).toBe( '' );
		expect( result.swept ).toBe( true );
		expect( result.queued_order ).toBe( true );
		expect( result.queued_refund ).toBe( false );
	} );

	/**
	 * Regression guard: the sweep used to pass a meta_query straight to
	 * wc_get_orders(), which post storage silently discards, so every paid order
	 * came back and the already-synced ones filled the window. The obvious
	 * replacement — OR( NOT EXISTS, <= now ) on the backoff key — is worse under
	 * HPOS, where it drops orders that have no such meta row at all. Whatever the
	 * mechanism, these four cases have to hold on both storage backends.
	 */
	test( 'enqueues only orders that are unsynced and out of backoff', async () => {
		const php = `
$option = 'woocommerce_efinancials_integration_settings';
$backup = get_option( $option );
$result = array( 'hpos' => false, 'ids' => array(), 'queued' => array() );

$pending = function () {
	return as_get_scheduled_actions( array(
		'hook'     => 'ef_sync_order_to_efinancials',
		'status'   => ActionScheduler_Store::STATUS_PENDING,
		'per_page' => 200,
	) );
};

$drain = function () use ( $pending ) {
	foreach ( $pending() as $action_id => $action ) {
		ActionScheduler::store()->cancel_action( (int) $action_id );
	}
};

try {
	$settings = is_array( $backup ) ? $backup : array();
	$settings['api_key_id']       = 'sweep-e2e-id';
	$settings['api_key_public']   = 'sweep-e2e-public';
	$settings['api_key_password'] = 'sweep-e2e-password';
	update_option( $option, $settings );

	$result['hpos'] = class_exists( 'Automattic\\\\WooCommerce\\\\Utilities\\\\OrderUtil' )
		&& Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled();

	foreach ( array( 'never_synced', 'already_complete', 'backing_off', 'backoff_expired' ) as $kind ) {
		$order = wc_create_order();
		$order->set_total( 10 );
		$order->set_status( 'completed' );

		if ( 'already_complete' === $kind ) {
			$order->update_meta_data( '_ef_sync_complete', 1 );
		}

		if ( 'backing_off' === $kind ) {
			$order->update_meta_data( '_ef_next_attempt_at', (string) ( time() + 3600 ) );
			$order->update_meta_data( '_ef_attempts', 3 );
		}

		if ( 'backoff_expired' === $kind ) {
			$order->update_meta_data( '_ef_next_attempt_at', (string) ( time() - 60 ) );
			$order->update_meta_data( '_ef_attempts', 3 );
		}

		$order->save();
		$result['ids'][ $kind ] = $order->get_id();
	}

	// Creating the orders above enqueued some of them via the status hook.
	$drain();
	do_action( 'ef_sweep_unsynced_orders' );

	foreach ( $pending() as $action ) {
		$args = $action->get_args();
		$result['queued'][] = (int) $args[0];
	}
} finally {
	$drain();

	foreach ( $result['ids'] as $id ) {
		$order = wc_get_order( $id );

		if ( $order ) {
			$order->delete( true );
		}
	}

	update_option( $option, $backup );
}

echo 'EF_RESULT' . wp_json_encode( $result ) . 'EF_END';
`;

		const output = wpEval( php );
		const match = output.match( /EF_RESULT(.*)EF_END/s );

		expect( match, `no result marker in output:\n${ output }` ).not.toBeNull();

		const { ids, queued, hpos } = JSON.parse( match[ 1 ] );
		const enqueued = ( kind ) => queued.includes( ids[ kind ] );

		expect( Object.keys( ids ) ).toHaveLength( 4 );

		expect(
			enqueued( 'never_synced' ),
			`never-synced order was skipped (HPOS: ${ hpos })`
		).toBe( true );
		expect(
			enqueued( 'backoff_expired' ),
			`order whose backoff expired was skipped (HPOS: ${ hpos })`
		).toBe( true );
		expect(
			enqueued( 'already_complete' ),
			`already-synced order burned a slot (HPOS: ${ hpos })`
		).toBe( false );
		expect(
			enqueued( 'backing_off' ),
			`backing-off order burned a slot (HPOS: ${ hpos })`
		).toBe( false );

		// The window is capped so one sweep cannot flood the queue.
		expect( queued.length ).toBeLessThanOrEqual( 20 );
	} );
} );
