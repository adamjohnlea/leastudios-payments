<?php
/**
 * Webhook event idempotency repository.
 *
 * @package LEAStudios\Payments\Database
 */

declare(strict_types=1);

namespace LEAStudios\Payments\Database;

// Prevent direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Tracks Stripe webhook event IDs that have already been processed so that
 * Stripe replays cannot cause handlers to run twice.
 */
class Webhook_Event_Repository {

	/**
	 * Get the webhook_events table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return Migration::table( 'webhook_events' );
	}

	/**
	 * Atomically claim a Stripe event ID for processing. Returns true if the
	 * caller is the first to claim it, false if another (concurrent or prior)
	 * call has already claimed it. The UNIQUE index on stripe_event_id makes
	 * the race-free at the database level.
	 *
	 * @param string $event_id   The Stripe event ID (`evt_*`).
	 * @param string $event_type The Stripe event type (`payment_intent.succeeded` etc).
	 * @return bool True if the caller claimed the event; false if it was already processed.
	 */
	public function try_claim( string $event_id, string $event_type ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$this->table(),
			[
				'stripe_event_id' => $event_id,
				'event_type'      => $event_type,
				'processed_at'    => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s' ]
		);

		// $wpdb->insert returns false on UNIQUE constraint violation (duplicate
		// event_id), and 1 on a successful insert. Treat any non-1 result as a
		// duplicate so a replay is not processed twice.
		return 1 === $inserted;
	}
}
