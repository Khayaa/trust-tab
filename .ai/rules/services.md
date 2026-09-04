---
paths:
  - 'app/Services/**'
---

# Services

## MoMo reference IDs must be UUID v4
Do not use Eloquent HasUuids for MoMo X-Reference-Id; Laravel 13 HasUuids defaults to UUIDv7. Persist a uuid column and generate with Str::uuid(). Read MoMo secrets via config('services.momo'), never env() in app code. Retry Request to Pay only with the same persisted UUID.

## MoMo sandbox settles in EUR, UI shows ZAR
The MTN sandbox only accepts EUR; sending ZAR returns INVALID_CURRENCY. Send config('services.momo.currency') in the request body and persist it on payment_requests.currency. Never send the display currency. Do not convert the amount - the sandbox is a state machine, not FX. X-Target-Environment must match the currency's country.

## MoMo 409 on RequestToPay means accepted, not failed
Because we reuse one persisted UUID v4 per payment request, retrying RequestToPay returns 409 RESOURCE_ALREADY_EXIST. That means MoMo already accepted the request - treat 202 and 409 both as PENDING. Check the status before calling ->throw(). Never generate a fresh UUID to work around a 409; that creates a second real debit.
