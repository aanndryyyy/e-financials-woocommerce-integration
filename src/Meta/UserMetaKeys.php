<?php
/**
 * User meta keys used for e-Financials sync state.
 *
 * @package Arbictus\EFinancialsPlugin
 */

declare(strict_types=1);

namespace Aanndryyyy\EFinancialsPlugin\Meta;

/**
 * Kept separate from the order meta keys so the two namespaces cannot drift.
 */
final class UserMetaKeys {

	public const CLIENTS_ID = '_ef_clients_id';
}
