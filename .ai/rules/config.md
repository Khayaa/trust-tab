---
paths:
  - config/services.php
---

# Config

## Repair truncated MoMo base URLs
MOMO_BASE_URL must be a full http(s) URL. Hosted URL fields sometimes store ttps:// (the leading h of https dropped). Pass it through App\Support\HttpUrl::normalize so Guzzle does not reject the scheme. Callback hosts remain a hostname with no scheme.

## Ignore APP_URL copies of MOMO_BASE_URL
If MOMO_BASE_URL's host matches APP_URL, treat it as unset and use https://sandbox.momodeveloper.mtn.com. Cloud URL fields often store the app host. Env edits on Cloud do not apply until the environment is redeployed.

## Strip scheme from MoMo callback host
MOMO_CALLBACK_HOST must be a hostname. Pass it through MomoCallbackHost::resolve so a Cloud URL field (https://trusttab.laravel.cloud) is not sent as https://https://….

## South Africa Collection uses the production proxy
Live MTN SA Collection is MOMO_BASE_URL=https://proxy.momoapi.mtn.com, MOMO_TARGET_ENVIRONMENT=mtnsouthafrica, MOMO_CURRENCY=ZAR. Never APP_URL. Do not run momo:provision against this host; the API user and key come from MTN. Sandbox remains sandbox.momodeveloper.mtn.com, sandbox, EUR.
