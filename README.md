# TrustTab

> Your neighbourhood tab, trusted by both sides.

TrustTab is a shared digital tab and checkout experience for neighbourhood merchants and the customers they already trust. It was built during the **MTN MoMo Mini App Hackathon 2026** in Johannesburg.

The project digitises informal store credit without turning it into a lending product. A merchant and customer agree on the tab terms, both see the same record of purchases, and MoMo provides the payment and settlement rail.

## What TrustTab does

TrustTab brings the full tab relationship into one place:

- A merchant opens a tab using the customer's MoMo number.
- The merchant proposes a tab limit and monthly settlement day.
- The customer accepts the agreement before anything can be added to the tab.
- The merchant records purchases or builds a basket from the product catalogue.
- The customer confirms or disputes the basket and individual tab entries.
- The customer can pay some or all of the confirmed balance through MTN MoMo.
- At the till, the customer can pay now, add the basket to the tab, or split the payment between MoMo and the agreed tab.
- Both sides see balances, payment status, pending confirmations, disputes, and payday reminders.

TrustTab is **not** a loan, BNPL service, credit bureau, or credit-scoring system. It records an existing relationship of trust and makes the agreement, checkout, and settlement visible to both people.

## The problem

Informal tabs are common in neighbourhood shops, but a handwritten record can be difficult for both sides to verify. The merchant may be the only person who controls the balance, purchases can be forgotten or disputed, and settlement is disconnected from the record of what was bought.

TrustTab creates a mutually visible ledger and connects it directly to checkout and MoMo settlement:

```text
Merchant creates a basket
        |
Customer confirms the purchase
        |
Pay with MoMo, use the agreed tab, or split the amount
        |
TrustTab balance, payment status, and stock update together
```

## Why MoMo is core

MoMo is part of the checkout, not only a way to repay later. A customer can pay what they can immediately through MoMo and place the agreed remainder on the tab. The application uses MoMo Collections Request to Pay, persists each payment request, and waits for a confirmed `SUCCESSFUL` status before settling a balance or completing a payment-dependent checkout.

The integration also handles pending requests, duplicate requests, callbacks, status reconciliation, and failed payments without silently changing the tab balance.

## Main workflows

### Shared tab

1. The merchant adds a customer by their MoMo number.
2. The merchant proposes a limit and settlement day.
3. The customer accepts the terms.
4. The merchant adds purchases to the tab.
5. The customer confirms or disputes each purchase.
6. The customer settles part or all of the confirmed balance with MoMo.

### Merchant till

The merchant can manage products and create a basket for a customer. The basket can be completed through one of three payment paths:

- **Pay Now:** collect the full basket through MoMo.
- **TrustTab:** add the full basket to the agreed tab after customer confirmation.
- **Split payment:** collect one portion through MoMo and place the agreed remainder on the tab.

Inventory is only reduced when the checkout is complete. A scan or an open basket does not change stock.

## Technology

- [Laravel 13](https://laravel.com) and PHP 8.3+
- [Livewire 4](https://livewire.laravel.com) for server-driven interactive pages
- [Laravel Reverb](https://laravel.com/docs/reverb) and Echo for real-time tab updates
- [Laravel Horizon](https://laravel.com/docs/horizon) for queue monitoring
- [MTN MoMo Collections API](https://momodeveloper.mtn.com/) for Request to Pay and settlement
- Vite and Tailwind CSS for frontend assets
- Pest for automated tests

## Getting started

### Requirements

- PHP 8.3 or newer
- Composer
- Node.js and npm
- A database supported by Laravel
- MTN MoMo sandbox credentials for payment testing

### Installation

Clone the repository, install dependencies, and run the project setup script:

```bash
git clone https://github.com/Khayaa/trust-tab.git
cd trust-tab
composer run setup
```

The setup script installs PHP and JavaScript dependencies, creates the local environment file, generates the application key, runs migrations, and builds the frontend assets.

Copy or update `.env` with the application and MoMo configuration required by your environment. In particular, configure the MoMo target environment, API user, API key, subscription key, callback host, currency, and payer details used by the sandbox.

Start the local development environment with:

```bash
composer run dev
```

## Testing

Run the test suite with:

```bash
composer test
```

To run the formatter on modified PHP files:

```bash
vendor/bin/pint --dirty --format agent
```

## Project structure

- `app/Actions` contains focused application workflows such as opening tabs, recording purchases, completing checkouts, and applying MoMo results.
- `app/Services/Momo` contains the MoMo client and Request to Pay integration.
- `app/Models` contains the tab, checkout, ledger, product, and payment request models.
- `resources/views/pages` contains the Livewire pages for login, tabs, products, and the merchant till.
- `tests` contains the Pest feature and unit tests.
- `docs` contains the hackathon blueprint and implementation notes.

## Hackathon context

TrustTab was created for the **MTN MoMo Mini App Hackathon 2026** under the Everyday Essentials theme. The MVP focuses on a practical daily transaction: a customer buying from a trusted local merchant and choosing the most useful way to settle the purchase at that moment.

The central product idea is:

> TrustTab lets customers pay what they can now through MoMo and place the agreed remainder on their merchant tab.

## License

This project is open-sourced under the [MIT license](https://opensource.org/licenses/MIT).
