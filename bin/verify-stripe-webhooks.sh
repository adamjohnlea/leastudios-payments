#!/usr/bin/env bash
# Stripe CLI verification for the dahlia API upgrade.
#
# Walks each handler the unit tests can't reach (real Stripe payload shapes
# under API 2026-03-25.dahlia). Run against test mode only — Stripe trigger
# creates real objects on the account it points at.
#
# Prerequisites:
#   - Stripe CLI installed and `stripe login` complete
#   - The plugin webhook secret in Payments > Settings matches the one
#     printed by `stripe listen` (whsec_...)
#   - WP_DEBUG on so handler-side errors land in the PHP log
#
# Usage:
#   Terminal 1:  bin/verify-stripe-webhooks.sh listen
#   Terminal 2:  bin/verify-stripe-webhooks.sh trigger
#   Then inspect Payments > Orders / Subscriptions in wp-admin and the
#   PHP error log for any '[leaStudios Payments]' lines.

set -euo pipefail

WEBHOOK_URL="${WEBHOOK_URL:-https://leastudios-plugins.test/wp-json/leastudios-payments/v1/webhook}"

case "${1:-help}" in
	listen)
		echo "Forwarding Stripe events to: $WEBHOOK_URL"
		echo "Copy the whsec_... value into Payments > Settings > Webhook Secret."
		echo
		exec stripe listen --forward-to "$WEBHOOK_URL"
		;;

	trigger)
		echo "==> checkout.session.completed (Checkout_Handler)"
		stripe trigger checkout.session.completed
		echo

		echo "==> customer.subscription.created (Subscription_Handler::handle_subscription_change)"
		stripe trigger customer.subscription.created
		echo

		echo "==> customer.subscription.updated"
		stripe trigger customer.subscription.updated
		echo

		echo "==> customer.subscription.deleted"
		stripe trigger customer.subscription.deleted
		echo

		echo "==> invoice.paid (handle_invoice_paid — confirms invoice.parent.subscription_details path)"
		stripe trigger invoice.paid
		echo

		echo "==> invoice.payment_failed (handle_invoice_payment_failed — same path)"
		stripe trigger invoice.payment_failed
		echo

		echo "==> charge.refunded (Refund_Handler)"
		stripe trigger charge.refunded
		echo

		echo "Done. Check the 'stripe listen' terminal for [200 OK] on every event."
		echo "Any 4xx/5xx means a handler rejected or errored on the dahlia payload."
		;;

	*)
		echo "Usage: $0 {listen|trigger}"
		exit 1
		;;
esac
