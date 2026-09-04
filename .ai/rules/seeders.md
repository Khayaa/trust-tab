---
paths:
  - 'database/seeders/**'
---

# Seeders

## Seeders must not use factories
Do not call model factories from seeders. fakerphp/faker is require-dev, so Cloud composer --no-dev has no fake() helper and db:seed dies in UserFactory. Create demo records with explicit attributes. Leave factories for tests.

## Seeded customer uses a demo MSISDN
Sipho's seeded MSISDN is 27723041887 (072 304 1887). Nandi's is 27821234567 (082 123 4567). These are demo numbers, never a live wallet. Re-seeding updates those users by email so Cloud does not need migrate:fresh.

## Demo shelf stays under R5
Seeded catalogue prices stay under R5 (around R1.50–R3.00). Always rewrite selling_price on re-seed. Do not leave grocery amounts like R18 bread. Tab lines in the seeder stay in the same cheap range. Test factories may still use larger amounts.

## Never seed a live wallet number
Sipho is 27723041887 and Nandi is 27821234567. These are demo numbers, never a live wallet. Do not put a real MSISDN in seeders, tests, placeholders, or rules.
