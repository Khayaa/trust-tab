---
paths:
  - 'resources/views/**/*.blade.php'
---

# Views

## Use Tailwind and Livewire, not Bootstrap
TrustTab UI is Livewire 4 + Tailwind CSS 4 (resources/css/app.css). Do not add Bootstrap or a second SPA stack. Keep MoMo WebView JS in resources/js/momo-bridge.js.

## Skeletons are wire:loading, not a package
Do not add Wirebones or another skeletonizer package. Pages are server-rendered, so lazy placeholders would delay first paint. Use x-skeleton plus wire:loading.delay on slow actions (settle, sendCode, openTab). Never wire:target refreshPayment — poll would flash a skeleton every 5s. Keep loading markup wordless; wire:loading stays in the HTML and would fail assertDontSee.

## App nav lives in x-app-nav
App chrome is x-app-nav in the layout: semantic nav, wire:navigate, aria-current on Tabs and Products, Products only for merchants. Account is an initials menu with a POST sign-out. Add a customer on pages::tabs is an in-app sheet, never an always-on form and never wire:confirm.

## App nav is a two-row phone header
x-app-nav is a two-row phone header: brand plus an initials account menu on the first row. Merchants get a Tabs / Products tab bar under that, with aria-current and wire:current. Customers do not get the tab bar; the brand already goes home. Add a customer on pages::tabs is a centered in-app modal, never an always-on form.
