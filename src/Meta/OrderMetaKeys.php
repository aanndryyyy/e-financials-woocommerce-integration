<?php
/**
 * Order meta keys used for e-Financials sync state.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Meta;

/**
 * Canonical order meta keys from the accounting workflow plan.
 */
final class OrderMetaKeys {

	public const CLIENTS_ID = '_ef_clients_id';

	public const SALE_INVOICE_ID = '_ef_sale_invoice_id';

	public const SALE_INVOICE_NUMBER = '_ef_sale_invoice_number';

	public const PAYMENT_MODE = '_ef_payment_mode';

	public const TRANSACTION_ID = '_ef_transaction_id';

	public const DELIVERED_AT = '_ef_delivered_at';

	public const SYNCED_AT = '_ef_synced_at';

	public const LAST_ERROR = '_ef_last_error';

	public const CREDIT_SALE_INVOICE_ID = '_ef_credit_sale_invoice_id';

	/**
	 * Per-refund credit invoice meta key.
	 *
	 * @param int $refund_id WooCommerce refund ID.
	 */
	public static function refund_credit_id( int $refund_id ): string {

		return '_ef_refund_' . $refund_id . '_credit_id';
	}
}
