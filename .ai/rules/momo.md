---
paths:
  - 'app/Services/Momo/**'
---

# Momo

## MoMo client: 409 means in flight, callback is only a hint
The persisted reference_id is reused on every retry, so a 409 RESOURCE_ALREADY_EXIST means the debit is already in flight, not that it failed. Treat 409 exactly like 202 (pending). Minting a fresh UUID to "fix" a 409 charges the customer twice.

Str::uuid() is v4, which is what MoMo requires. Do not put HasUuids on PaymentRequest: it generates v7 and MoMo rejects it.

Access tokens are cached under momo.collection.token.{target_environment}.{api_user} for 50 minutes, short of the real expiry. Do not request one per call.

X-Callback-Url is omitted entirely when services.momo.callback_host is empty, because a host that does not match the one registered on the API user fails the whole request with INVALID_CALLBACK_URL_HOST. payments:reconcile-pending covers the gap.

MomoTransactionStatus (MoMo's PENDING/SUCCESSFUL/FAILED) is kept separate from PaymentRequestStatus on purpose. Convert with toPaymentRequestStatus().

## Retry RTP without callback on host mismatch
If Request to Pay returns INVALID_CALLBACK_URL_HOST, retry once with the same UUID and no X-Callback-Url. A 400 did not consume the id. Polling and payments:reconcile-pending cover the missing callback. Never mint a new UUID.

## Sandbox succeeds outside the reserved fail block
The Collection sandbox succeeds for ZA MSISDNs outside the reserved 4673312345x fail block. An earlier failure for a non-Sipho customer was INVALID_CALLBACK_URL_HOST, not the payer number. Sipho is a convenient seeded customer, not the only number that can pay. Only the tab's customer may settle.

## Namespace MoMo token cache by environment
Access tokens are cached under momo.collection.token.{target_environment}.{api_user} for 50 minutes. Switching sandbox to mtnsouthafrica (or API user) must not reuse the previous token.

## GET status FAILED is the real production reject
A 202 Request to Pay is not success. GET status FAILED is where production reports NOT_ALLOWED, PAYER_LIMIT_REACHED, SENDER_ACCOUNT_NOT_ACTIVE, APPROVAL_REJECTED, or COULD_NOT_PERFORM_TRANSACTION (PIN timeout). Parse reason from reason, nested reason.code, or code. Log MoMo payment failed with reference_id and reason only. Map those codes in MomoRequestFailed::messageForCode; unknown codes still show MoMo declined this payment.
