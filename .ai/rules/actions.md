---
paths:
  - 'app/Actions/**'
---

# Actions

## State transitions assign properties, never mass-assign
Status and its timestamps are intentionally absent from the models' #[Fillable] attributes so no request can drive a state machine. That means $entry->update(['status' => ...]) silently does nothing. Transition actions must set properties directly and save(): $locked->status = X; $locked->confirmed_at = now(); $locked->save(). AppServiceProvider calls preventSilentlyDiscardingAttributes outside production so this now throws instead of failing quietly. Every transition action re-reads the row with lockForUpdate inside DB::transaction and re-checks the current status before writing, so a double-tap cannot confirm twice.

## Settlement snapshots entries and always verifies with MoMo
RequestTabSettlement snapshots the outstanding entries and their amounts into payment_request_entries before calling MoMo. ApplyMomoResult settles only those snapshotted ids, never whatever is outstanding now, so an entry confirmed while the customer types their PIN is not swept into a payment they never agreed to.

ApplyMomoResult always calls MomoCollections::status() rather than reading a callback body. A callback is an unauthenticated POST from the internet and is only a hint that something changed. The action is idempotent: a request that is already final returns untouched, which is what makes a replayed callback harmless.

external_reference is derived from the payment request's primary key, not a timestamp. Two attempts on one tab within the same second collide on the unique index otherwise.

MoMo caps payerMessage/payeeNote at 160 chars and rejects apostrophes, so the business name is stripped of both before being sent.

## Merchant opens a tab by MoMo number
A tab is opened by the merchant typing the customer's MoMo number. OpenTabWithCustomer normalizes the number (SA leading-zero to 27...), firstOrCreates the user on msisdn, and firstOrCreates the tab on (merchant_id, customer_id). An existing customer's name is never overwritten. A second tap on the same number returns the same tab. A closed tab is reopened. The merchant cannot open a tab against their own number.

Only merchants may create tabs (TabPolicy::create). There is no customer-side accept step: standing at the counter is the acceptance. New users are created with a name and msisdn only — email and password stay null so they cannot be guessed through the login form.

## Never reveal an OTP that was not stored
Do not reveal a dummy OTP for an unknown number. The hashed code is only stored when the MSISDN already has a user. Showing a fake 6-digit value makes judges type it and then see 'that code is not right'. The form still advances so we do not confirm whether a tab exists.

## Reuse one in-flight settlement per tab
RequestTabSettlement locks the tab, then returns the existing Created or Pending payment instead of minting a new UUID. A second settle() must not Request to Pay again. If the in-flight row was never sent (requested_at is null), send() the same UUID; MoMo 409 means it is already pending. Only raise a new payment after the previous one is Successful or Failed.

## Checkout stock and hybrid rails commit together
Do not decrement product stock on scan. CompleteCheckout lockForUpdate()s products and decrements only when the checkout is complete: TrustTab after customer confirm, Pay Now after GET SUCCESSFUL, hybrid only when BOTH succeed. momo_amount + tab_amount must equal line total. Failed MoMo must not add the remainder to the tab or take stock. Status timestamps are assigned on the model, never mass-assigned.

## Customer must accept tab terms
Opening a tab now proposes a limit and payday in the same transaction. The customer must accept (agreement_accepted_at) before AddTabEntry. Standing at the counter is no longer enough. TabPolicy::addEntry requires hasAcceptedAgreement(). Changing terms clears acceptance; saving the same terms does not.

## Partial settlement uses credits not splits
RequestTabSettlement accepts an optional amount ≤ outstanding. Snapshot every outstanding line at request time, not only the lines the amount will cover. ApplyPaymentCredit on SUCCESSFUL allocates this amount plus prior unallocated remainders oldest-first, settles only full lines by assigning status/settled_at, and stores leftover on payment_requests.unallocated_amount. Never UPDATE tab_entries.amount. outstandingBalance is unsettled confirmed minus unallocated remainders — not the full payment amount.

## One open checkout per tab
AddProductToBasket locks the tab and reuses the single OPEN checkout, like in-flight settlement. Same product_id increments quantity and keeps the first unit_price snapshot. Never decrement stock_quantity here — CompleteCheckout will lockForUpdate and deduct only when the sale is complete. CancelOpenCheckout assigns Cancelled + cancelled_at on the model, never mass-assign status.

## Pay Now does not touch the tab ledger
RequestCheckoutPayNow collects the basket total only. Nothing is added to the tab and ApplyPaymentCredit must not run. Reuse the in-flight payment for this checkout_id, not any tab settlement. 202 is pending. ApplyMomoResult GET SUCCESSFUL calls CompleteCheckout (stock lockForUpdate, Completed + completed_at). GET FAILED or RTP reject calls ReopenCheckout. Tab settlement queries must whereNull(checkout_id) so a till debit cannot be reused as payday settlement.

## TrustTab checkout confirms the basket once
RequestTrustTabCheckout sets AwaitingConfirmation with tab_amount = total and momo_amount = 0. No tab entries and no stock yet. ConfirmCheckout writes one confirmed TabEntry per line (checkout_id set) then CompleteCheckout. DisputeCheckout reopens the basket. Do not add pending per-line entries. Pay Now stays merchant-led and skips this confirm.

## Hybrid waits for confirm then MoMo
RequestHybridCheckout sets both rails (momo + tab = total, both > 0) and AwaitingConfirmation. ConfirmCheckout starts RequestCheckoutPayNow for momo_amount only. RecordCheckoutOnTab + CompleteCheckout run only on GET SUCCESSFUL. Failed MoMo reopens with no entries and no stock. A hybrid remainder is one confirmed TabEntry for tab_amount, never per-line full prices.

## Disputes are corrected by a new line
Never UPDATE tab_entries.amount. CorrectDisputedTabEntry withdraws the disputed row and creates a pending replacement with corrects_entry_id. The original amount stays on the withdrawn line so the ledger is append-only. ConfirmPendingTabEntries confirms every waiting line in one transaction after checking outstanding plus the batch total against the limit, so a mid-batch confirm cannot leave half the lines confirmed.
