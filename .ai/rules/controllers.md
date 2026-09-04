---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## Verify MoMo webhooks by GET status
The callback route may be CSRF-exempt as a webhook, but do not settle from the payload alone. Persist the event, dispatch a unique job, GET payment status from MoMo, then settle inside a transaction with lockForUpdate. Idempotency is unique reference + already-final no-op.

## Cloud-visible MoMo callback logs
Log MoMo callback receipt and apply with reference_id only. Never log the payload or MSISDN. Search Laravel Cloud Logs for 'MoMo callback'. A successful payment with no callback line means Request to Pay was retried without X-Callback-Url; polling settled it.

## Store only MoMo callback status
MomoWebhookEvent.payload keeps status only. The callback is unauthenticated, so never persist the full request body (MSISDN, payer, transaction ids). Settlement still comes from GET status, not this payload.
