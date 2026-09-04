---
paths:
  - 'app/Models/**'
  - app/Models/PaymentRequest.php
  - app/Models/Product.php
---

# Models

## Never sum money with SQL SUM
SQL SUM on a decimal column returns a float on SQLite (tests) and a DECIMAL on MySQL (production), so a balance formatted as '140.00' in production comes back as '140' in tests. Sum money in PHP with bcadd over the cast decimal:2 strings instead, as Tab::outstandingBalance() does. Money columns are decimal(12,2) cast to 'decimal:2', so model attributes are strings - compare them with toBe, never toEqual, or float coercion hides precision bugs.

## Surface sandbox failure reasons on the till
Show PaymentRequest::failureMessage() on the failed-payment banner. MoMo sandbox often returns PAYER_NOT_FOUND for a real ZA number; the generic 'did not go through' hid that. Keep the till sentence that nothing was taken.

## Show unmapped MoMo codes on the till
When GET status FAILED has no mapped sentence, show the MoMo code in parentheses on the till, e.g. MoMo declined this payment (NOT_FOO). That is how a Cloud demo is diagnosed without SSH. Normalize spaced reasons like INTERNAL PROCESSING ERROR to INTERNAL_PROCESSING_ERROR before mapping. Log payload_keys (not values) with MoMo payment failed.

## Sum loaded outstandingEntries in PHP
Tab::outstandingBalance() uses the outstandingEntries relation when it is already loaded (the tabs list eager-loads it). Never SQL SUM. If the relation is not loaded, query outstanding amounts and bcadd in PHP.

## Tab available is derived from an agreed limit
tabs.limit_amount and settlement_day are proposed by the merchant; only the customer sets agreement_accepted_at. Do not store available or current_balance. Available is limit minus outstanding. Reject TrustTab or hybrid remainder that would exceed the limit, inside lockForUpdate() on the tab. settlement_day is 1-31, not a calendar date.

## Outstanding is unsettled confirmed minus unallocated credits
Never SQL SUM money. Tab::outstandingBalance() is bcadd of confirmed unsettled decimal:2 amounts minus unallocated remainder of SUCCESSFUL payments. Partial settlement allocates oldest-first and never splits an entry or UPDATE tab_entries.amount. Subtracting the full payment while also dropping settled rows double-counts.

## Catalogue stock waits for checkout
Products belong to a merchant. Catalogue is merchant-only. Do not decrement stock_quantity on save or scan. Checkout will lockForUpdate and deduct only when the sale is complete. Barcode is unique per merchant and nullable. Name is unique per merchant.

## Awaiting baskets count toward the limit
Tab::committedAmount() includes AwaitingConfirmation checkout tab_amount plus pending entries, so AddTabEntry cannot sneak over the limit while a basket is waiting. ConfirmCheckout must compare committedAmount to the limit, not wouldExceedLimit(tab_amount), or the basket is counted twice. availableAmount stays limit minus outstanding only.

## Hybrid remainder stays committed in flight
Tab::committedAmount() includes tab_amount from AwaitingConfirmation and from AwaitingMomo checkouts with a tab rail, so AddTabEntry cannot sneak over the limit after the customer confirms and while MoMo is still pending.

## Product search is name or barcode
Product::search uses whereAny on name and barcode. Empty trim is a no-op. Scope both the till shelf and the catalogue list so another shop never appears. LIKE values are bound, never interpolated.
