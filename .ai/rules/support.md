---
paths:
  - app/Support/MerchantMorning.php
  - app/Support/CustomerMorning.php
  - app/Support/TrustHistory.php
---

# Support

## Merchant morning is shop-scoped PHP sums
MerchantMorning is the shop home snapshot on pages::tabs, not a new dashboard route. Sum owed and today's till with bcadd over decimal:2 strings, never SQL SUM. Keep only tabs where isMerchant($user) so a shopkeeper who is also a customer elsewhere does not mix those balances into owed or till. Low stock is this merchant's active products via whereColumn, not money.

## Customer morning is shop-scoped PHP sums
CustomerMorning is the customer home snapshot on pages::tabs, not a new dashboard route. Sum owed with bcadd over decimal:2 strings, never SQL SUM. Keep only tabs where isCustomer($user) so a shopkeeper who also buys elsewhere does not mix merchant-side balances into what they owe. Payday reminders are derived from nextDueDate plus outstanding, never stored.

## This month insights stay on merchant morning
MerchantMorning adds monthTabSales (confirmed plus settled TabEntry amounts with confirmed_at this month), monthMomo (Successful PaymentRequest amounts with completed_at this month), and topOnTab (top 3 descriptions by line count). Sum with bcadd, never SQL SUM. Pending, disputed, withdrawn, last month, other shops, and tabs where the user is the customer are excluded. Still outstanding is the existing owed card. Do not add a dashboard route.

## Trust History is facts, never a score
TrustHistory is factual counts for tabs the user holds as customer: successful tab settlements (checkout_id null), their bcadd total, how many completed_at fell on or before that month's settlement_day, and current disputed_count. Pay Now, failed MoMo, other shops, and tabs where the user is the merchant are excluded. Never call it a score or say anyone is creditworthy. Do not add a dashboard route.
