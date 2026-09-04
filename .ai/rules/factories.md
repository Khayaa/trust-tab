---
paths:
  - 'database/factories/**'
---

# Factories

## Factory MSISDNs must dodge the sandbox failure block
The MoMo sandbox reserves 4673312345x for forced outcomes: ...450 Failed, ...451 Rejected, ...452 Timeout/Expired, ...454 Pending. UserFactory therefore generates numbers in 4673312{4000-9999} so factory users pay successfully by default. Use PaymentRequestFactory::payerForcing('rejected') to opt into a failure path. Seeded and demo names must not contain apostrophes - the sandbox rejects payerMessage with them, so it is 'Mama Nandi Spaza', never "Mama Nandi's".
