---
paths:
  - 'tests/**'
---

# Tests

## Http::preventStrayRequests is on globally
tests/Pest.php calls Http::preventStrayRequests() before every Feature test, so any unfaked outbound request fails loudly rather than hitting the MoMo sandbox and burning reference ids.

phpunit.xml pins MOMO_DEMO_MODE=false so tests exercise MomoCollectionsClient against Http::fake, not DemoMomoCollections. It also force-pins sandbox URL, target environment, currency, and dummy keys so a production .env cannot leak into the suite.

When asserting on a request body with Http::assertSent, guard on the URL first. The token POST has no body, so an unguarded $request['currency'] throws "Undefined array key".

Livewire turns an authorization failure into a 403 during tests: use ->assertForbidden(), not ->throws(AuthorizationException::class).

## Pin sandbox MoMo env in phpunit.xml
phpunit.xml force-pins MOMO_BASE_URL, MOMO_TARGET_ENVIRONMENT=sandbox, and MOMO_CURRENCY=EUR. Local and Cloud .env may be proxy.momoapi.mtn.com / mtnsouthafrica / ZAR. Tests must not inherit those values or live keys.
