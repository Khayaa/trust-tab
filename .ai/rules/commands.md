---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## momo:provision must target the sandbox host
MOMO_BASE_URL is https://sandbox.momodeveloper.mtn.com. APP_URL / trusttab.laravel.cloud belong in MOMO_CALLBACK_HOST. Posting /v1_0/apiuser at the app yields Laravel's 'The route could not be found' 404, which looks like MoMo. Refuse before the HTTP call when the host is not sandbox.momodeveloper.mtn.com.
