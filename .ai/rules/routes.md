---
paths:
  - 'routes/**'
---

# Routes

## MoMo callback fires once and host is pinned
The MoMo callback is delivered once with no retry, so the scheduled payments:reconcile-pending command is the real guarantee, not the webhook. The callback URL host must exactly match the host registered when the API user was created, and must be a hostname not an IP, or MoMo returns INVALID_CALLBACK_URL_HOST. Do not change the deployment hostname after registering.
