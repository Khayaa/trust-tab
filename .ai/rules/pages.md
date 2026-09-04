---
paths:
  - 'resources/views/pages/**'
---

# Pages

## Real login is the page; the demo shortcut is a flagged extra
pages::login is the real email and password form and must always render. The tap-a-name demo shortcut is rendered underneath it only when trusttab.demo_auth is on, and DemoSessionController aborts 404 on the same flag, so knowing the POST URL is not enough to hand yourself a session. Never gate the whole login page on the demo flag.

The form is rate limited to five attempts a minute per email plus IP, and both a wrong password and an unknown email return the identical message, so the form cannot be used to discover who has an account. LoginTest asserts both strings match; keep them identical.

Seeded users all have the password 'password'. Seed MSISDNs are demo South African numbers in international format (27...). The MoMo sandbox only reserves the 4673312345x block for forced failures.

## Failed payment banner is shared
lastPayment Failed is one record shown to merchant and customer. Confirming Pay only sends Request to Pay; MoMo FAILED afterwards is the same banner on both screens. Do not render it next to a settlement error from the same Livewire request.

## Pay uses an in-app confirm sheet
Pay confirmation is an in-app sheet (askToSettle), never wire:confirm. The native browser dialog is off-brand on a till. Escape and backdrop cancel without calling MoMo.

## Pay sheet does not promise a phone PIN
The in-app Pay sheet is only 'do you want to collect this amount now'. Do not promise a phone PIN. Collection sandbox auto-succeeds outside 4673312345x, so MoMo SUCCESSFUL is the approval. Waiting copy is 'Waiting for MoMo' until GET status is final.

## Show national MSISDN on the pay sheet
The Pay sheet displays Msisdn::forDisplay() (072 304 1887), never the stored 27... form. Waiting copy is check your phone for a prompt. Do not promise a PIN. Poll with wire:poll.5s.visible while a payment is in flight.

## Validate only the fields the action uses
show-tab has two forms with #[Validate] on both add and terms fields. Never call $this->validate() or validateOnly() with an array of names. Pass an explicit rules array for only the fields that action uses, otherwise empty description/amount fail settle() and Livewire throws Array to string conversion. Keep @error('settlement') next to the failed-payment banner so a same-request MoMo 400 still shows.

## Refresh the tab after agreement writes
After ProposeTabTerms or AcceptTabAgreement, refresh the Livewire Tab model in place. Replacing $this->tab with a new instance leaves the dehydrated copy stale, so the add form stays visible until a full reload. tabChanged() must refresh too so Echo picks up agreement changes.

## Merchant morning lives on the tabs list
The merchant home is pages::tabs. Render morning cards for merchants only. Customers must not see owed-to-you, till, or low stock. A tab with an AwaitingConfirmation basket is waiting, even when it has no pending entries.

## Customer TrustTabs and payday live on the tabs list
Customers see My TrustTabs on pages::tabs: you owe, waiting on you, and the next payday. Merchants must not see those cards unless they are also a customer elsewhere. Show the sunshine payday banner only when a tab has outstanding and nextDueDate is today or tomorrow, and send Pay with MoMo to tabs.show. Do not invent a dashboard route.

## Dispute and correct use in-app sheets
show-tab dispute and correction are in-app sheets, never wire:confirm. The customer can still confirm or dispute each line. Batch confirm appears only when two or more lines are waiting. Show the correction trail on both the withdrawn original and the new pending line.

## Till search and Quick Add stay on checkout
pages::checkout filters the shelf with Product::search on query (name or barcode). A barcode miss keeps the code and offers Add it. Add without a barcode opens the same in-app sheet. addNewProduct uses SaveProduct then AddProductToBasket; stock is not decremented. Default shelf count is 1 so a one-off sale can go on the basket. Never validateOnly with a name array. Never UPDATE an existing product amount from this sheet.

## This month insights render on the tabs list
pages::tabs shows On TrustTab, MoMo settled, and Most on the tab under This morning for merchants only. Customers must not see those labels. Do not invent a dashboard route.

## Trust History lives on the customer tabs list
pages::tabs shows Trust History under My TrustTabs for customers only. Copy must say this is not a score. Merchants who are not also customers must not see Trust History. Do not invent a dashboard or share/passport route yet.

## Add a customer is a sheet
Add a customer is an in-app sheet on pages::tabs. The home list only shows the sunshine Add a customer button. Escape and backdrop cancel. openTab validates an explicit rules array for the four fields. Customers must not see the button.

## Add to tab is a product modal
Add to tab on pages::show-tab is a centered modal. The tab screen only shows the Add to tab button. The modal searches this merchant's active products with Product::search. Tapping a product fills name and catalogue price; a one-off can still be typed. Stock is not taken here — that stays on the till. Escape and backdrop cancel. Never validateOnly with a name array.
